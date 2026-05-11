<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Repositories\ProductRepository;
use App\Services\Integration\PrestaShopClient;
use App\Services\Sync\ProductImageNormalizationService;
use App\Support\HttpClient;
use App\Support\ImageNormalizerFactory;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;

class ImageDiagnosticsController extends Controller
{
    use ApiResponseTrait;

    private function allowLongRunningRequest(): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        @ini_set('max_execution_time', '0');
    }

    /**
     * @OA\Get(
     *     path="/api/v1/diagnostics/images",
     *     operationId="imageDiagnosticsSummary",
     *     tags={"Diagnostics"},
     *     summary="Image pipeline health",
     *     description="Aggregates product image rows: missing usable images per product, AY dimension/ratio issues, duplicates, and failed normalization.",
     *     @OA\Parameter(name="include_samples", in="query", @OA\Schema(type="boolean", default=false)),
     *     @OA\Parameter(name="sample_limit", in="query", @OA\Schema(type="integer", minimum=1, maximum=50, default=15)),
     *     @OA\Response(response=200, description="Diagnostics payload", @OA\JsonContent(ref="#/components/schemas/ApiSuccessBase"))
     * )
     */
    public function summary(Request $request)
    {
        $includeSamples = filter_var($request->query('include_samples', false), FILTER_VALIDATE_BOOLEAN);
        $limit = (int) $request->query('sample_limit', 15);

        $repo = new ProductRepository();
        $summary = $repo->getImageDiagnosticsSummary();
        $productsMissingUsable = $summary['products_without_image_rows'] + $summary['products_with_images_but_no_ok'];

        $payload = [
            'summary' => array_merge($summary, [
                'products_missing_usable_images' => $productsMissingUsable,
                'normalization_available' => ImageNormalizerFactory::available(),
            ]),
        ];
        if ($includeSamples) {
            $payload['samples'] = $repo->getImageDiagnosticsSamples($limit);
        }

        return $this->success($payload);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/diagnostics/images/gallery",
     *     operationId="imageDiagnosticsGallery",
     *     tags={"Diagnostics"},
     *     summary="Products with image strips for diagnostics UI",
     *     @OA\Parameter(name="filter", in="query", @OA\Schema(type="string", enum={"problematic","all"}, default="problematic")),
     *     @OA\Parameter(name="limit", in="query", @OA\Schema(type="integer", minimum=1, maximum=48, default=18)),
     *     @OA\Response(response=200, description="Gallery rows", @OA\JsonContent(ref="#/components/schemas/ApiSuccessBase"))
     * )
     */
    public function gallery(Request $request)
    {
        $filter = $request->query('filter', 'problematic');
        $filter = $filter === 'all' ? 'all' : 'problematic';
        $limit = (int) $request->query('limit', 18);

        $repo = new ProductRepository();
        $rows = $repo->getImageGalleryRows($filter, $limit);

        return $this->success([
            'filter' => $filter,
            'normalization_available' => ImageNormalizerFactory::available(),
            'rows' => $rows,
        ]);
    }

    /**
     * Re-fetch from PrestaShop and letterbox images (1125×1500) like product sync.
     *
     * @OA\Post(
     *     path="/api/v1/diagnostics/images/normalize",
     *     operationId="imageDiagnosticsNormalize",
     *     tags={"Diagnostics"},
     *     summary="Normalize product images",
     *     security={{"bearerAuth":{}}, {"apiTokenHeader":{}}},
     *     @OA\Response(response=200, description="Per-product normalization results", @OA\JsonContent(ref="#/components/schemas/ApiSuccessBase"))
     * )
     */
    public function normalize(Request $request)
    {
        $this->allowLongRunningRequest();

        $validated = $request->validate([
            'product_ids' => ['sometimes', 'array', 'max:30'],
            'product_ids.*' => ['integer', 'min:1'],
            'mode' => ['sometimes', 'string', 'in:problematic'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $http = new HttpClient(
            (int) ($_ENV['HTTP_TIMEOUT_SEC'] ?? 30),
            (int) ($_ENV['HTTP_MAX_RETRIES'] ?? 3)
        );

        $normalizer = ImageNormalizerFactory::create($http);
        if ($normalizer === null) {
            return $this->error(
                'Image normalization is disabled (IMAGE_NORMALIZE_ENABLED) or not configured (APP_URL or IMAGE_NORMALIZE_PUBLIC_BASE_URL).',
                503
            );
        }

        try {
            $ps = new PrestaShopClient($http);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 503);
        }

        $repo = new ProductRepository();
        $service = new ProductImageNormalizationService($ps, $repo, $normalizer);

        $productIds = array_values(array_unique(array_map('intval', (array) ($validated['product_ids'] ?? []))));
        if (($validated['mode'] ?? '') === 'problematic' && $productIds === []) {
            $cap = (int) ($validated['limit'] ?? 25);
            $productIds = $repo->findProductIdsForImageGallery('problematic', max(1, min(50, $cap)));
        }

        if ($productIds === []) {
            return $this->error('No product_ids supplied and nothing matched problematic filter.', 422);
        }

        $productIds = array_slice($productIds, 0, 30);

        $batch = $service->normalizeLocalProducts($productIds);

        return $this->success($batch, 'normalization finished');
    }
}
