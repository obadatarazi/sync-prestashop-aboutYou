<?php

namespace SyncBridge\Services;

use SyncBridge\Database\ProductRepository;
use SyncBridge\Database\RetryJobRepository;
use SyncBridge\Database\SyncRunRepository;
use SyncBridge\Database\Database;
use SyncBridge\Support\ValidationException;

/**
 * ProductSyncService
 *
 * Orchestrates ONE-WAY product sync: PrestaShop → DB → AboutYou.
 *
 * Flow per product:
 *   1) Fetch full PS product + combinations
 *   2) Upsert into local DB (products, product_variants)
 *   3) Fetch + normalize images → store locally → save paths to DB
 *   4) Map DB product to AY payload
 *   5) Push to AY API
 *   6) Mark product synced in DB
 *
 * Progress is written to sync_runs + sync_logs tables so the UI
 * can poll and show per-product real-time status.
 */
class ProductSyncService
{
    private ProductRepository $products;
    private RetryJobRepository $retryJobs;
    private SyncRunRepository $runs;
    private DbSyncLogger      $logger;
    private string $runId;

    // Injected dependencies (PS client, AY client, mapper, image normalizer)
    private mixed $ps;
    private mixed $ay;
    private mixed $mapper;
    private mixed $imageNormalizer;

    private array $stats = [
        'fetched' => 0, 'pushed' => 0, 'skipped' => 0, 'failed' => 0,
    ];
    private int $lastProgressUpdateMs = 0;

    public function __construct(
        string  $runId,
        mixed   $ps,
        mixed   $ay,
        mixed   $mapper,
        mixed   $imageNormalizer = null
    ) {
        $this->runId           = $runId;
        $this->ps              = $ps;
        $this->ay              = $ay;
        $this->mapper          = $mapper;
        $this->imageNormalizer = $imageNormalizer;
        $this->products        = new ProductRepository();
        $this->retryJobs       = new RetryJobRepository();
        $this->runs            = new SyncRunRepository();
        $this->logger          = new DbSyncLogger($runId, 'sync');
    }

    // ----------------------------------------------------------------
    // FULL SYNC
    // ----------------------------------------------------------------

    public function syncAll(): array
    {
        $this->resetStats();
        $this->logger->info('ProductSyncService::syncAll started');

        $psProducts = $this->ps->getAllProducts();
        $total = count($psProducts);
        $this->stats['fetched'] = $total;

        $this->updateRunProgress([
            'total_items'  => $total,
            'current_phase'=> 'fetching_products',
            'last_message' => "Fetched {$total} products from PrestaShop",
        ], true);
        $this->logger->info("Fetched {$total} products from PrestaShop");

        $this->processProducts($psProducts);

        $this->logger->info('ProductSyncService::syncAll completed', $this->stats);
        return $this->stats;
    }

    // ----------------------------------------------------------------
    // INCREMENTAL SYNC
    // ----------------------------------------------------------------

    public function syncIncremental(string $since): array
    {
        $this->resetStats();
        $this->logger->info("ProductSyncService::syncIncremental since {$since}");

        $psProducts = $this->ps->getProductsModifiedSince($since);
        $total = count($psProducts);
        $this->stats['fetched'] = $total;
        $this->logger->info("Fetched {$total} changed products");

        $this->processProducts($psProducts);
        return $this->stats;
    }

    public function syncStockAndPrices(string $since): array
    {
        $this->resetStats();
        $this->logger->info("ProductSyncService::syncStockAndPrices since {$since}");

        $psProducts = $this->ps->getProductsModifiedSince($since);
        $stockItems = [];
        $priceItems = [];

        foreach ($psProducts as $psProduct) {
            $productId = $this->products->upsertFromPrestaShop($psProduct);
            $combinations = $this->ps->getCombinations((int) ($psProduct['id'] ?? 0));
            foreach ($combinations as $combo) {
                $this->products->upsertVariant($productId, $combo, (float) ($psProduct['price'] ?? 0));
                $sku = trim((string) ($combo['reference'] ?? ''));
                if ($sku === '') {
                    continue;
                }
                $stockItems[] = [
                    'sku' => $sku,
                    'quantity' => max(0, (int) ($combo['quantity'] ?? 0)),
                ];
                $priceItems[] = [
                    'sku' => $sku,
                    'price' => [
                        'country_code' => strtoupper((string) ($_ENV['AY_DEFAULT_COUNTRY_CODE'] ?? 'DE')),
                        'retail_price' => round((float) $this->mapper->calculateVariantRetailPrice($psProduct, $combo), 2),
                    ],
                    'valid_at' => gmdate('c'),
                ];
            }
        }

        $stockResults = $this->ay->updateStocks($stockItems);
        $priceResults = $this->ay->updatePrices($priceItems);

        foreach ([$stockResults, $priceResults] as $resultSet) {
            foreach ($resultSet as $result) {
                if (!empty($result['error'])) {
                    $this->stats['failed']++;
                } else {
                    $this->stats['pushed']++;
                }
            }
        }

        return $this->stats;
    }

    // ----------------------------------------------------------------
    // TARGETED SYNC (specific IDs)
    // ----------------------------------------------------------------

    public function syncForProductIds(array $psIds): array
    {
        $this->resetStats();
        $psProducts = [];
        foreach (array_unique($psIds) as $id) {
            $p = $this->ps->getProduct((int)$id);
            if ($p) $psProducts[] = $p;
            else $this->stats['failed']++;
        }
        $this->stats['fetched'] = count($psProducts);
        $this->processProducts($psProducts);
        return $this->stats;
    }

    // ----------------------------------------------------------------
    // CORE PROCESSING  — product by product
    // ----------------------------------------------------------------

    private function processProducts(array $psProducts): void
    {
        $total = count($psProducts);

        foreach ($psProducts as $idx => $psProduct) {
            $seq      = $idx + 1;
            $psId     = (int)($psProduct['id'] ?? 0);

            try {
                // ── Update run progress ──────────────────────────────
                $this->updateRunProgress([
                    'current_product_id' => $psId,
                    'current_phase'      => 'processing',
                    'done_items'         => $idx,
                    'pushed'             => $this->stats['pushed'],
                    'failed'             => $this->stats['failed'],
                    'last_message'       => "Processing PS#{$psId} ({$seq}/{$total})",
                ], true);

                $this->logger->info("Processing PS#{$psId}", ['seq' => $seq, 'total' => $total]);

                // ── 1. Upsert into DB ─────────────────────────────────
                $productId = $this->products->upsertFromPrestaShop($psProduct);
                $this->products->markSyncing($productId);

                // Resolve category name
                $categoryName = '';
                $catId = (int)($psProduct['id_category_default'] ?? 0);
                if ($catId) {
                    $cat = $this->ps->getCategory($catId);
                    if ($cat) {
                        $categoryName = is_array($cat['name'] ?? null)
                            ? ($cat['name'][0]['value'] ?? '')
                            : (string)($cat['name'] ?? '');
                        // Update category name in DB
                        \SyncBridge\Database\Database::execute(
                            "UPDATE products SET category_name=?, category_ps_id=? WHERE id=?",
                            [$categoryName, $catId, $productId]
                        );
                    }
                }

                // ── 2. Combinations → product_variants ───────────────
                $this->logger->info("Fetching combinations PS#{$psId}");
                $combinations = $this->ps->getCombinations($psId);
                foreach ($combinations as $combo) {
                    $this->products->upsertVariant($productId, $combo, (float)($psProduct['price'] ?? 0));
                }
                $combinations = $this->applyVariantEanOverrides($productId, $combinations);

                // ── 3. Images → normalize → store in DB ──────────────
                $this->logger->info("Processing images PS#{$psId}");
                $rawUrls   = $this->ps->getProductImageUrls($psId, $psProduct);
                $imageUrls = $rawUrls;

                if ($this->imageNormalizer !== null && !empty($rawUrls)) {
                    $normalizedUrls = [];
                    foreach ($rawUrls as $pos => $url) {
                        $psImgId = $this->extractImageId($url);
                        $existing = $this->products->findImageByProductAndPsImageId($productId, $psImgId);
                        $imgDbId = $this->products->upsertImage($productId, $url, $psImgId, $pos);
                        if (($existing['status'] ?? '') === 'ok'
                            && trim((string) ($existing['public_url'] ?? '')) !== ''
                            && (string) ($existing['source_url'] ?? '') === $url
                        ) {
                            $normalizedUrls[] = (string) $existing['public_url'];
                            continue;
                        }

                        $result = $this->imageNormalizer->normalizeSingleUrl($url);
                        if ($result !== null) {
                            [$localPath, $publicUrl, $w, $h, $bytes] = $result;
                            $this->products->markImageOk($imgDbId, $localPath, $publicUrl, $w, $h, $bytes);
                            $normalizedUrls[] = $publicUrl;
                        } else {
                            $this->products->markImageError($imgDbId, 'Normalization failed');
                            $this->logger->warning("Image normalization failed PS#{$psId}", ['url' => $url]);
                        }
                    }
                    $imageUrls = $normalizedUrls ?: $rawUrls;
                } elseif (!empty($rawUrls)) {
                    // Persist raw PrestaShop image references even when normalization is disabled.
                    // This keeps product thumbnails available in the UI.
                    foreach ($rawUrls as $pos => $url) {
                        $psImgId = $this->extractImageId($url);
                        $imgDbId = $this->products->upsertImage($productId, $url, $psImgId, $pos);
                        $this->products->markImageOkFromSource($imgDbId);
                    }
                }

                // ── 4. Map to AY payload ──────────────────────────────
                $this->logger->info("Building AY payload PS#{$psId}");
                $localProduct = $this->products->findById($productId) ?? [];
                if ($localProduct !== []) {
                    $psProduct = array_merge($psProduct, array_filter([
                        'export_title' => $localProduct['export_title'] ?? null,
                        'export_description' => $localProduct['export_description'] ?? null,
                        'export_material_composition' => $localProduct['export_material_composition'] ?? null,
                        'ay_category_id' => $localProduct['ay_category_id'] ?? null,
                        'ay_brand_id' => $localProduct['ay_brand_id'] ?? null,
                        'category_ps_id' => $localProduct['category_ps_id'] ?? null,
                    ], static fn (mixed $value): bool => $value !== null && $value !== ''));
                }
                $effectiveCategoryId = $this->resolveAyCategoryIdForRequirements($psProduct);
                $this->injectCategoryRequirements($psProduct, $effectiveCategoryId);
                $mapStartedAt = microtime(true);
                $ayProduct = $this->mapper->mapProductToAy(
                    $psProduct,
                    $combinations,
                    $imageUrls,
                    $categoryName
                );
                $mapElapsedMs = (int) round((microtime(true) - $mapStartedAt) * 1000);
                foreach (($ayProduct['warnings'] ?? []) as $warning) {
                    $this->logger->warning('AY payload warning PS#' . $psId, [
                        'warning' => $warning,
                        'reason_code' => $this->extractReasonCode((string) $warning),
                    ]);
                }
                $this->logger->info('Preflight passed PS#' . $psId, [
                    'elapsed_ms' => $mapElapsedMs,
                    'variant_count' => count($ayProduct['variants'] ?? []),
                ]);

                // ── 5. Push to AboutYou ───────────────────────────────
                $this->updateRunProgress([
                    'current_phase' => 'pushing_to_ay',
                    'last_message'  => "Pushing PS#{$psId} → AY",
                ], true);

                $pushStartedAt = microtime(true);
                $results = $this->ay->upsertProducts($ayProduct['variants']);
                $pushElapsedMs = (int) round((microtime(true) - $pushStartedAt) * 1000);
                $hasError = false;
                foreach ($results as $res) {
                    if (isset($res['error'])) {
                        $hasError = true;
                        $this->logger->error("AY push failed PS#{$psId}", [
                            'error' => $res['error'],
                            'reason_code' => 'ay_api_contract',
                            'retryable' => false,
                        ]);
                    }
                }

                if ($hasError) {
                    $pushErrors = array_values(array_filter(array_map(
                        static fn (array $res): string => trim((string) ($res['error'] ?? '')),
                        $results
                    )));
                    $errorMessage = $pushErrors !== [] ? implode(' | ', array_values(array_unique($pushErrors))) : 'AY push returned error';
                    $this->products->markError($productId, $errorMessage);
                    $this->recordProductErrorEvent(
                        $productId,
                        $psId,
                        'push',
                        'ay_api_contract',
                        $errorMessage,
                        ['results' => $results]
                    );
                    $this->retryJobs->enqueue('product_push', (string) $psId, [
                        'ps_product_id' => $psId,
                        'style_key' => $ayProduct['style_key'] ?? null,
                        'reason_code' => 'ay_api_contract',
                    ], 'AY push returned error');
                    $this->stats['failed']++;
                } else {
                    // ── 6. Mark synced ────────────────────────────────
                    $this->products->markSynced($productId, $ayProduct['style_key']);
                    $this->retryJobs->markDone('product_push', (string) $psId);
                    $this->stats['pushed']++;
                    $this->logger->info("✓ PS#{$psId} → AY:{$ayProduct['style_key']}", [
                        'variants' => count($ayProduct['variants']),
                        'push_elapsed_ms' => $pushElapsedMs,
                        'reason_code' => 'success',
                    ]);
                }

            } catch (ValidationException $e) {
                $this->stats['failed']++;
                $error = implode('; ', $e->errors()) ?: $e->getMessage();
                $reasonCounts = $this->summarizeValidationReasons($e->errors());
                $this->logger->error("✗ PS#{$psId} validation failed", [
                    'errors' => $e->errors(),
                    'reason_code' => 'local_preflight',
                    'reason_breakdown' => $reasonCounts,
                    'retryable' => false,
                ]);
                try {
                    $row = $this->products->findByPsId($psId);
                    if ($row) {
                        $this->products->markError((int) $row['id'], $error);
                        $this->recordProductErrorEvent(
                            (int) $row['id'],
                            $psId,
                            'preflight',
                            'local_preflight',
                            $error,
                            ['errors' => $e->errors()]
                        );
                    }
                } catch (\Throwable) {
                }
            } catch (\Throwable $e) {
                $this->stats['failed']++;
                $reasonCode = $this->classifyTransportError($e);
                $retryable = $reasonCode !== 'local_preflight';
                $this->logger->error("✗ PS#{$psId} failed: " . $e->getMessage(), [
                    'reason_code' => $reasonCode,
                    'retryable' => $retryable,
                ]);
                if ($retryable) {
                    $this->retryJobs->enqueue('product_push', (string) $psId, [
                        'ps_product_id' => $psId,
                        'reason_code' => $reasonCode,
                    ], $e->getMessage());
                }

                try {
                    $row = $this->products->findByPsId($psId);
                    if ($row) {
                        $this->products->markError((int) $row['id'], $e->getMessage());
                        $this->recordProductErrorEvent(
                            (int) $row['id'],
                            $psId,
                            'runtime',
                            $reasonCode,
                            $e->getMessage(),
                            ['exception' => get_class($e)]
                        );
                    }
                } catch (\Throwable) {}
            }
        }

        // Final progress update
        $this->updateRunProgress([
            'done_items'    => $total,
            'pushed'        => $this->stats['pushed'],
            'failed'        => $this->stats['failed'],
            'skipped'       => $this->stats['skipped'],
            'current_phase' => 'complete',
            'last_message'  => "Sync complete: {$this->stats['pushed']} pushed, {$this->stats['failed']} failed",
        ], true);
    }

    private function extractImageId(string $url): string
    {
        if (preg_match('/\/(\d+)\?/', $url, $m)) return $m[1];
        if (preg_match('/\/(\d+)$/', $url, $m)) return $m[1];
        return md5($url);
    }

    private function resetStats(): void
    {
        $this->stats = ['fetched' => 0, 'pushed' => 0, 'skipped' => 0, 'failed' => 0];
    }

    public function getStats(): array { return $this->stats; }

    private function updateRunProgress(array $data, bool $force = false): void
    {
        $now = (int) floor(microtime(true) * 1000);
        $intervalMs = max(250, (int) ($_ENV['SYNC_PROGRESS_UPDATE_MS'] ?? 1200));
        if (!$force && ($now - $this->lastProgressUpdateMs) < $intervalMs) {
            return;
        }
        $this->runs->updateProgress($this->runId, $data);
        $this->lastProgressUpdateMs = $now;
    }

    private function extractReasonCode(string $message): string
    {
        if (preg_match('/\[reason=([a-z0-9_]+)\]/i', $message, $match) === 1) {
            return strtolower($match[1]);
        }
        return 'unknown';
    }

    private function summarizeValidationReasons(array $errors): array
    {
        $counts = [];
        foreach ($errors as $message) {
            $reason = $this->extractReasonCode((string) $message);
            $counts[$reason] = ($counts[$reason] ?? 0) + 1;
        }
        return $counts;
    }

    private function classifyTransportError(\Throwable $e): string
    {
        $message = strtolower($e->getMessage());
        if (str_contains($message, 'timeout') || str_contains($message, 'timed out')) {
            return 'transport_timeout';
        }
        if (str_contains($message, 'curl') || str_contains($message, 'connection') || str_contains($message, 'network')) {
            return 'transport_network';
        }
        if (str_contains($message, 'rate limit') || str_contains($message, '429')) {
            return 'transport_rate_limited';
        }
        if (str_contains($message, 'forbidden') || str_contains($message, '401') || str_contains($message, '403')) {
            return 'auth';
        }
        return 'transport_unknown';
    }

    private function applyVariantEanOverrides(int $productId, array $combinations): array
    {
        if ($productId <= 0 || $combinations === []) {
            return $combinations;
        }

        $variants = $this->products->getVariants($productId);
        if ($variants === []) {
            return $combinations;
        }

        $eanByCombo = [];
        foreach ($variants as $variant) {
            $comboId = (int) ($variant['ps_combo_id'] ?? 0);
            if ($comboId <= 0) {
                continue;
            }
            $eanByCombo[$comboId] = trim((string) ($variant['ean13'] ?? ''));
        }

        foreach ($combinations as &$combo) {
            $comboId = (int) ($combo['id'] ?? 0);
            if ($comboId <= 0 || !array_key_exists($comboId, $eanByCombo)) {
                continue;
            }
            $override = $eanByCombo[$comboId];
            $combo['ean13'] = $override !== '' ? $override : null;
        }
        unset($combo);

        return $combinations;
    }

    private function injectCategoryRequirements(array &$psProduct, int $categoryId): void
    {
        if ($categoryId <= 0 || !method_exists($this->ay, 'getRequiredCategoryMetadata')) {
            return;
        }
        try {
            $metadata = $this->ay->getRequiredCategoryMetadata($categoryId);
            $requiredGroups = is_array($metadata['required_groups'] ?? null)
                ? $metadata['required_groups']
                : [];
            $requiredTextFields = is_array($metadata['required_text_fields'] ?? null)
                ? $metadata['required_text_fields']
                : [];

            // Some AY category endpoints return groups but omit explicit "required"
            // flags. In that case, rely on locally curated defaults table.
            if ($requiredGroups === []) {
                $fallback = $this->loadFallbackRequirementMetadataFromDefaults($categoryId);
                if (($fallback['required_groups'] ?? []) !== []) {
                    $requiredGroups = (array) ($fallback['required_groups'] ?? []);
                    $requiredTextFields = array_values(array_unique(array_merge(
                        $requiredTextFields,
                        (array) ($fallback['required_text_fields'] ?? [])
                    )));
                    $this->logger->warning('AY metadata had no explicit required groups; using local defaults fallback', [
                        'category_id' => $categoryId,
                        'fallback_groups' => count($requiredGroups),
                    ]);
                }
            }

            if ($requiredGroups !== []) {
                $psProduct['ay_required_attribute_groups'] = $requiredGroups;
            }
            if ($requiredTextFields !== []) {
                $psProduct['ay_required_text_fields'] = $requiredTextFields;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Could not load AY category requirement metadata', [
                'category_id' => $categoryId,
                'error' => $e->getMessage(),
            ]);
            $fallback = $this->loadFallbackRequirementMetadataFromDefaults($categoryId);
            if (($fallback['required_groups'] ?? []) !== []) {
                $psProduct['ay_required_attribute_groups'] = $fallback['required_groups'];
                if (($fallback['required_text_fields'] ?? []) !== []) {
                    $psProduct['ay_required_text_fields'] = $fallback['required_text_fields'];
                }
                $this->logger->warning('Using fallback category requirement metadata from local defaults', [
                    'category_id' => $categoryId,
                    'fallback_groups' => count((array) ($fallback['required_groups'] ?? [])),
                    'fallback_text_fields' => (array) ($fallback['required_text_fields'] ?? []),
                ]);
                return;
            }
            $strictPreflight = filter_var($_ENV['AY_STRICT_PREFLIGHT'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $requireMetadata = filter_var($_ENV['AY_REQUIRE_CATEGORY_METADATA'] ?? true, FILTER_VALIDATE_BOOLEAN);
            if ($strictPreflight && $requireMetadata) {
                throw new ValidationException(
                    'Category requirement metadata unavailable',
                    [
                        '[reason=missing_category_metadata] Could not load AboutYou category requirements for category '
                        . $categoryId
                        . '; fix AY_BASE_URL/AY_API_KEY or disable AY_REQUIRE_CATEGORY_METADATA temporarily.',
                    ]
                );
            }
        }
    }

    /**
     * Local fallback used when AY category metadata endpoint is unavailable.
     *
     * @return array{
     *   required_groups:list<array{id:int,name:string,required:bool,default_ay_id:int}>,
     *   required_text_fields:list<string>
     * }
     */
    protected function loadFallbackRequirementMetadataFromDefaults(int $categoryId): array
    {
        try {
            $rows = \SyncBridge\Database\Database::fetchAll(
                'SELECT ay_group_id, ay_group_name, default_ay_id
                   FROM ay_required_group_defaults
                  WHERE ay_group_id > 0
                    AND (ay_category_id = ? OR ay_category_id = 0)
               ORDER BY ay_category_id DESC, ay_group_id ASC',
                [$categoryId]
            );
        } catch (\Throwable) {
            $rows = [];
        }

        $requiredGroupsById = [];
        foreach ($rows as $row) {
            $groupId = (int) ($row['ay_group_id'] ?? 0);
            $defaultAyId = (int) ($row['default_ay_id'] ?? 0);
            if ($groupId <= 0 || $defaultAyId <= 0 || isset($requiredGroupsById[$groupId])) {
                continue;
            }
            $requiredGroupsById[$groupId] = [
                'id' => $groupId,
                'name' => trim((string) ($row['ay_group_name'] ?? '')) !== ''
                    ? (string) $row['ay_group_name']
                    : 'group_' . $groupId,
                'required' => true,
                'default_ay_id' => $defaultAyId,
            ];
        }

        $requiredTextFields = [];
        $rawTextFields = trim((string) ($_ENV['AY_FALLBACK_REQUIRED_TEXT_FIELDS'] ?? 'material_composition_textile'));
        if ($rawTextFields !== '') {
            $requiredTextFields = array_values(array_unique(array_filter(array_map(
                static fn (string $field): string => strtolower(trim($field)),
                explode(',', $rawTextFields)
            ))));
        }

        return [
            'required_groups' => array_values($requiredGroupsById),
            'required_text_fields' => $requiredTextFields,
        ];
    }

    private function recordProductErrorEvent(
        int $productId,
        int $psId,
        string $phase,
        string $reasonCode,
        string $errorMessage,
        array $details = []
    ): void {
        if ($productId <= 0 || $psId <= 0 || trim($errorMessage) === '') {
            return;
        }
        try {
            Database::execute(
                "INSERT INTO product_sync_errors
                    (product_id, ps_id, run_id, phase, reason_code, error_message, error_details)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [
                    $productId,
                    $psId,
                    $this->runId,
                    in_array($phase, ['preflight', 'push', 'runtime'], true) ? $phase : 'runtime',
                    trim($reasonCode) !== '' ? $reasonCode : 'unknown',
                    mb_substr($errorMessage, 0, 65535),
                    $details !== [] ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                ]
            );
        } catch (\Throwable) {
            // Keep sync flow resilient even if error history persistence fails.
        }
    }

    private function resolveAyCategoryIdForRequirements(array $psProduct): int
    {
        $explicit = (int) ($psProduct['ay_category_id'] ?? 0);
        if ($explicit > 0) {
            return $explicit;
        }

        $psCategoryId = (string) ($psProduct['id_category_default'] ?? $psProduct['category_ps_id'] ?? '');
        $map = json_decode((string) ($_ENV['AY_CATEGORY_MAP'] ?? '{}'), true);
        if (is_array($map) && $psCategoryId !== '' && array_key_exists($psCategoryId, $map)) {
            $mapped = $map[$psCategoryId];
            if (is_array($mapped)) {
                $mapped = $mapped['id'] ?? 0;
            }
            if ((int) $mapped > 0) {
                return (int) $mapped;
            }
        }

        return (int) ($_ENV['AY_CATEGORY_ID'] ?? 0);
    }
}
