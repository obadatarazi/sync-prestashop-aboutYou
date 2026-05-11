<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Repositories\ProductRepository;
use App\Services\Integration\PrestaShopClient;
use App\Support\ImageNormalizer;

/**
 * Re-fetch PrestaShop image URLs and letterbox to 1125×1500 (same rules as ProductSyncService).
 */
final class ProductImageNormalizationService
{
    public function __construct(
        private readonly PrestaShopClient $ps,
        private readonly ProductRepository $products,
        private readonly ImageNormalizer $normalizer,
    ) {
    }

    /**
     * @param list<int> $productIds Local `products.id` values
     * @return array{ok: bool, totals: array{normalized: int, failed: int, skipped: int, errors: int}, results: list<array<string, mixed>>}
     */
    public function normalizeLocalProducts(array $productIds): array
    {
        $totals = ['normalized' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => 0];
        $results = [];
        foreach ($productIds as $pid) {
            $r = $this->normalizeLocalProduct((int) $pid);
            $results[] = ['product_id' => (int) $pid] + $r;
            if (!($r['ok'] ?? false)) {
                $totals['errors']++;
            }
            $totals['normalized'] += (int) ($r['normalized'] ?? 0);
            $totals['failed'] += (int) ($r['failed'] ?? 0);
            $totals['skipped'] += (int) ($r['skipped'] ?? 0);
        }

        return ['ok' => true, 'totals' => $totals, 'results' => $results];
    }

    /**
     * @return array{ok: bool, normalized: int, failed: int, skipped: int, error?: string, message?: string}
     */
    public function normalizeLocalProduct(int $productId): array
    {
        $product = $this->products->findById($productId);
        if ($product === null) {
            return ['ok' => false, 'error' => 'product_not_found', 'normalized' => 0, 'failed' => 0, 'skipped' => 0];
        }
        $psId = (int) ($product['ps_id'] ?? 0);
        if ($psId <= 0) {
            return ['ok' => false, 'error' => 'invalid_ps_id', 'normalized' => 0, 'failed' => 0, 'skipped' => 0];
        }

        $psProduct = $this->ps->getProduct($psId);
        if ($psProduct === null) {
            return ['ok' => false, 'error' => 'prestashop_product_missing', 'normalized' => 0, 'failed' => 0, 'skipped' => 0];
        }

        $rawUrls = $this->ps->getProductImageUrls($psId, $psProduct);
        if ($rawUrls === []) {
            return [
                'ok' => true,
                'message' => 'no_images_in_prestashop',
                'normalized' => 0,
                'failed' => 0,
                'skipped' => 0,
            ];
        }

        $normalized = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($rawUrls as $pos => $url) {
            $url = (string) $url;
            $psImgId = $this->extractImageId($url);
            $existing = $this->products->findImageByProductAndPsImageId($productId, $psImgId);
            $imgDbId = $this->products->upsertImage($productId, $url, $psImgId, (int) $pos);
            $existingPublicUrl = trim((string) ($existing['public_url'] ?? ''));
            $existingWidth = (int) ($existing['width'] ?? 0);
            $existingHeight = (int) ($existing['height'] ?? 0);
            $existingLooksNormalized = str_contains($existingPublicUrl, '/ay-normalized/');
            $existingMeetsAyMinimum = $existingWidth >= 1125 && $existingHeight >= 1500;
            if (($existing['status'] ?? '') === 'ok'
                && $existingPublicUrl !== ''
                && (string) ($existing['source_url'] ?? '') === $url
                && ($existingLooksNormalized || $existingMeetsAyMinimum)
            ) {
                $skipped++;
                continue;
            }

            $result = $this->normalizer->normalizeSingleUrl($url);
            if ($result !== null) {
                [$localPath, $publicUrl, $w, $h, $bytes] = $result;
                $this->products->markImageOk($imgDbId, $localPath, $publicUrl, $w, $h, $bytes);
                $normalized++;
            } else {
                $this->products->markImageError($imgDbId, 'Normalization failed');
                $failed++;
            }
        }

        return ['ok' => true, 'normalized' => $normalized, 'failed' => $failed, 'skipped' => $skipped];
    }

    private function extractImageId(string $url): string
    {
        if (preg_match('/\/(\d+)\?/', $url, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/\/(\d+)$/', $url, $m) === 1) {
            return $m[1];
        }

        return md5($url);
    }
}
