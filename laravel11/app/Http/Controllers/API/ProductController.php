<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductIndexRequest;
use App\Http\Resources\ProductResource;
use App\Repositories\ProductRepository;
use App\Services\Integration\AboutYouMapper;
use App\Services\Integration\PrestaShopClient;
use App\Support\ValidationException;
use App\Models\Product;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    use ApiResponseTrait;

    /**
     * @OA\Get(
     *     path="/api/v1/products",
     *     operationId="productsIndex",
     *     tags={"Products"},
     *     summary="List products",
     *     description="Returns paginated products with optional filtering and search.",
     *     @OA\Parameter(name="page", in="query", @OA\Schema(type="integer", minimum=1, default=1)),
     *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", minimum=1, maximum=200, default=20)),
     *     @OA\Parameter(name="status", in="query", @OA\Schema(type="string", enum={"synced","pending","error","syncing","quarantined"})),
     *     @OA\Parameter(name="search", in="query", @OA\Schema(type="string", maxLength=255)),
     *     @OA\Response(
     *         response=200,
     *         description="Products fetched successfully",
     *         @OA\JsonContent(
     *             allOf={
     *                 @OA\Schema(ref="#/components/schemas/ApiSuccessBase"),
     *                 @OA\Schema(
     *                     @OA\Property(property="total", type="integer", example=245),
     *                     @OA\Property(property="page", type="integer", example=1),
     *                     @OA\Property(property="per_page", type="integer", example=20),
     *                     @OA\Property(property="rows", type="array", @OA\Items(ref="#/components/schemas/Product"))
     *                 )
     *             }
     *         )
     *     ),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ApiError"))
     * )
     */
    public function index(ProductIndexRequest $request)
    {
        $query = Product::query();

        if ($status = $request->string('status')->value()) {
            $query->where('sync_status', $status);
        }
        if ($search = $request->string('search')->value()) {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('reference', 'like', "%{$search}%")
                    ->orWhere('ay_style_key', 'like', "%{$search}%");
            });
        }

        $rows = $query->orderBy('ps_id')->paginate((int) $request->input('per_page', 20));
        return $this->success([
            'total' => $rows->total(),
            'rows' => ProductResource::collection($rows->items()),
            'page' => $rows->currentPage(),
            'per_page' => $rows->perPage(),
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/products/{productId}",
     *     operationId="productsShow",
     *     tags={"Products"},
     *     summary="Get product details",
     *     description="Returns product details with variants and images.",
     *     @OA\Parameter(name="productId", in="path", required=true, @OA\Schema(type="integer", minimum=1)),
     *     @OA\Response(
     *         response=200,
     *         description="Product details fetched successfully",
     *         @OA\JsonContent(
     *             allOf={
     *                 @OA\Schema(ref="#/components/schemas/ApiSuccessBase"),
     *                 @OA\Schema(
     *                     @OA\Property(property="product", ref="#/components/schemas/Product"),
     *                     @OA\Property(property="variants", type="array", @OA\Items(ref="#/components/schemas/ProductVariant")),
     *                     @OA\Property(property="images", type="array", @OA\Items(ref="#/components/schemas/ProductImage"))
     *                 )
     *             }
     *         )
     *     ),
     *     @OA\Response(response=404, description="Product not found", @OA\JsonContent(ref="#/components/schemas/ApiError"))
     * )
     */
    public function show(int $productId)
    {
        $product = Product::with(['variants', 'images', 'syncErrors'])->findOrFail($productId);
        return $this->success([
            'product' => new ProductResource($product),
            'variants' => $product->variants,
            'images' => $product->images,
            'sync_errors' => $product->syncErrors()->latest('id')->limit(50)->get(),
        ]);
    }

    public function updateDraft(Request $request, int $productId)
    {
        $data = $request->validate([
            'export_title' => ['nullable', 'string', 'max:512'],
            'export_description' => ['nullable', 'string'],
            'export_material_composition' => ['nullable', 'string'],
            'ay_category_id' => ['nullable', 'integer', 'min:1'],
            'ay_category_path' => ['nullable', 'string', 'max:512'],
            'ay_brand_id' => ['nullable', 'integer', 'min:1'],
            'ay_manual_required_attributes_json' => ['nullable', 'string'],
        ]);

        if (array_key_exists('ay_manual_required_attributes_json', $data) && $data['ay_manual_required_attributes_json'] !== null && $data['ay_manual_required_attributes_json'] !== '') {
            $decoded = json_decode((string) $data['ay_manual_required_attributes_json'], true);
            if (!is_array($decoded)) {
                return $this->error('ay_manual_required_attributes_json must be a valid JSON object');
            }
        }

        $product = Product::query()->findOrFail($productId);

        if (array_key_exists('ay_category_id', $data) && ($data['ay_category_id'] === null || (int) $data['ay_category_id'] <= 0)) {
            $data['ay_category_id'] = null;
            $data['ay_category_path'] = null;
        }

        $product->fill($data);
        $product->save();

        return $this->success(['product' => new ProductResource($product->fresh())], 'draft_saved');
    }

    public function previewPayload(
        Request $request,
        int $productId,
        PrestaShopClient $prestaShopClient,
        AboutYouMapper $aboutYouMapper
    ) {
        $product = Product::query()->findOrFail($productId);
        $psProduct = $prestaShopClient->getProduct((int) $product->ps_id);
        if (!$psProduct) {
            return $this->error('PrestaShop product not found for this record', 404);
        }

        $combinations = $prestaShopClient->getCombinations((int) $product->ps_id);
        $imageUrls = $prestaShopClient->getProductImageUrls((int) $product->ps_id, $psProduct);

        $psProduct = array_merge($psProduct, array_filter([
            'export_title' => $product->export_title,
            'export_description' => $product->export_description,
            'export_material_composition' => $product->export_material_composition,
            'ay_manual_required_attributes_json' => $product->ay_manual_required_attributes_json,
            'ay_missing_payload_json' => $product->ay_missing_payload_json,
            'ay_category_id' => $product->ay_category_id,
            'ay_brand_id' => $product->ay_brand_id,
            'category_ps_id' => $product->category_ps_id,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));

        try {
            $payload = $aboutYouMapper->mapProductToAy(
                $psProduct,
                $combinations,
                $imageUrls,
                (string) ($product->category_name ?? '')
            );

            return $this->success([
                'ready' => true,
                'payload' => $payload,
                'errors' => [],
            ]);
        } catch (ValidationException $e) {
            return $this->success([
                'ready' => false,
                'payload' => null,
                'errors' => $e->errors(),
            ]);
        }
    }

    public function refetchFromPrestaShop(Request $request, PrestaShopClient $prestaShopClient)
    {
        $validated = $request->validate([
            'ps_product_ids' => ['nullable', 'array'],
            'ps_product_ids.*' => ['integer', 'min:1'],
        ]);

        $requestedIds = array_values(array_unique(array_map('intval', (array) ($validated['ps_product_ids'] ?? []))));
        $repository = new ProductRepository();
        $products = [];
        $notFound = [];

        if ($requestedIds !== []) {
            foreach ($requestedIds as $psId) {
                $psProduct = $prestaShopClient->getProduct($psId);
                if ($psProduct) {
                    $products[] = $psProduct;
                } else {
                    $notFound[] = $psId;
                }
            }
        } else {
            $products = $prestaShopClient->getAllProducts();
        }

        foreach ($products as $psProduct) {
            $repository->upsertFromPrestaShop($psProduct);
        }

        return $this->success([
            'updated' => count($products),
            'requested' => $requestedIds !== [] ? count($requestedIds) : count($products),
            'not_found_ids' => $notFound,
        ], 'products refetched');
    }
}
