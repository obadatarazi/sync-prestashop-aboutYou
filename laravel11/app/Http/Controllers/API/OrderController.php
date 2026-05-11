<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Repositories\OrderRepository;
use App\Services\Integration\AboutYouClient;
use App\Services\Integration\AboutYouMapper;
use App\Services\Integration\PrestaShopClient;
use App\Services\Sync\OrderSyncService;
use App\Services\Sync\ProductSyncService;
use App\Support\HttpClient;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    use ApiResponseTrait;

    /**
     * @OA\Get(
     *     path="/api/v1/orders",
     *     operationId="ordersIndex",
     *     tags={"Orders"},
     *     summary="List orders",
     *     description="Returns paginated order records.",
     *     @OA\Parameter(name="page", in="query", @OA\Schema(type="integer", minimum=1, default=1)),
     *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", minimum=1, maximum=200, default=20)),
     *     @OA\Response(
     *         response=200,
     *         description="Orders fetched successfully",
     *         @OA\JsonContent(
     *             allOf={
     *                 @OA\Schema(ref="#/components/schemas/ApiSuccessBase"),
     *                 @OA\Schema(
     *                     @OA\Property(property="total", type="integer", example=80),
     *                     @OA\Property(property="page", type="integer", example=1),
     *                     @OA\Property(property="per_page", type="integer", example=20),
     *                     @OA\Property(property="rows", type="array", @OA\Items(ref="#/components/schemas/Order"))
     *                 )
     *             }
     *         )
     *     )
     * )
     */
    public function index(Request $request)
    {
        $rows = Order::query()->orderByDesc('id')->paginate((int) $request->input('per_page', 20));
        return $this->success([
            'total' => $rows->total(),
            'rows' => OrderResource::collection($rows->items()),
            'page' => $rows->currentPage(),
            'per_page' => $rows->perPage(),
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/orders/{orderId}",
     *     operationId="ordersShow",
     *     tags={"Orders"},
     *     summary="Get order details",
     *     description="Returns order details and order items.",
     *     @OA\Parameter(name="orderId", in="path", required=true, @OA\Schema(type="integer", minimum=1)),
     *     @OA\Response(
     *         response=200,
     *         description="Order details fetched successfully",
     *         @OA\JsonContent(
     *             allOf={
     *                 @OA\Schema(ref="#/components/schemas/ApiSuccessBase"),
     *                 @OA\Schema(
     *                     @OA\Property(property="order", ref="#/components/schemas/Order"),
     *                     @OA\Property(property="items", type="array", @OA\Items(ref="#/components/schemas/OrderItem"))
     *                 )
     *             }
     *         )
     *     ),
     *     @OA\Response(response=404, description="Order not found", @OA\JsonContent(ref="#/components/schemas/ApiError"))
     * )
     */
    public function show(int $orderId)
    {
        $order = Order::with('items')->findOrFail($orderId);
        return $this->success(['order' => new OrderResource($order), 'items' => $order->items]);
    }

    public function update(Request $request, int $orderId)
    {
        $payload = $request->validate([
            'customer_email' => ['nullable', 'string', 'max:255'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'total_paid' => ['nullable', 'numeric', 'min:0'],
            'total_products' => ['nullable', 'numeric', 'min:0'],
            'total_shipping' => ['nullable', 'numeric', 'min:0'],
            'discount_total' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'shipping_country_iso' => ['nullable', 'string', 'size:2'],
            'billing_country_iso' => ['nullable', 'string', 'size:2'],
            'shipping_method' => ['nullable', 'string', 'max:120'],
            'payment_method' => ['nullable', 'string', 'max:120'],
            'shipping_address_json' => ['nullable', 'string'],
            'billing_address_json' => ['nullable', 'string'],
            'ay_status' => ['nullable', 'string', 'max:60'],
            'sync_status' => ['nullable', 'string', 'in:pending,importing,imported,status_pushed,error,quarantined'],
            'error_message' => ['nullable', 'string'],
        ]);

        $order = Order::query()->findOrFail($orderId);
        (new OrderRepository())->updateOrderDetails((int) $order->id, $payload);

        $fresh = Order::query()->with('items')->findOrFail($orderId);
        return $this->success([
            'order' => new OrderResource($fresh),
            'items' => $fresh->items,
        ], 'order_updated');
    }

    public function repush(Request $request, int $orderId)
    {
        $validated = $request->validate([
            'include_stock_sync' => ['nullable', 'boolean'],
        ]);
        $includeStockSync = (bool) ($validated['include_stock_sync'] ?? true);

        $order = Order::query()->with('items')->findOrFail($orderId);
        $ayOrderId = trim((string) $order->ay_order_id);
        if ($ayOrderId === '') {
            return $this->error('Order cannot be re-pushed: missing AY order id.', 422);
        }

        $runId = 'repush-' . bin2hex(random_bytes(8));
        $http = new HttpClient(
            (int) ($_ENV['HTTP_TIMEOUT_SEC'] ?? 30),
            (int) ($_ENV['HTTP_MAX_RETRIES'] ?? 3)
        );
        $ps = new PrestaShopClient($http);
        $ay = new AboutYouClient($http);
        $mapper = new AboutYouMapper();
        $orders = new OrderRepository();

        try {
            $orders->resetForRepush((int) $order->id);
            $imported = (new OrderSyncService($runId, $ps, $ay, $mapper))->retryImportByAyOrderId($ayOrderId);
            if (!$imported) {
                $failedOrder = Order::query()->where('ay_order_id', $ayOrderId)->first();
                $detail = trim((string) ($failedOrder?->error_message ?? ''));
                $message = $detail !== ''
                    ? 'Re-push failed: ' . $detail
                    : 'Re-push did not complete. Check retry queue and sync logs for details.';

                return $this->error($message, 500);
            }

            $stockResult = null;
            if ($includeStockSync) {
                $freshOrder = Order::query()->with('items')->where('ay_order_id', $ayOrderId)->first();
                $productIds = collect($freshOrder?->items ?? [])
                    ->map(static fn ($item): int => (int) ($item->product_id ?? 0))
                    ->filter(static fn (int $id): bool => $id > 0)
                    ->unique()
                    ->values()
                    ->all();

                if ($productIds !== []) {
                    $stockResult = (new ProductSyncService($runId . '-stock', $ps, $ay, $mapper))
                        ->syncForProductIds($productIds);
                } else {
                    $stockResult = ['fetched' => 0, 'pushed' => 0, 'skipped' => 0, 'failed' => 0];
                }
            }

            $freshOrder = Order::query()->with('items')->where('ay_order_id', $ayOrderId)->first();
            if ($freshOrder === null) {
                return $this->error('Order was re-pushed but could not be reloaded from database.', 500);
            }

            return $this->success([
                'order' => new OrderResource($freshOrder),
                'items' => $freshOrder->items,
                'stock_sync' => $stockResult,
            ], 'Order re-pushed to PrestaShop successfully.');
        } catch (\Throwable $e) {
            return $this->error('Re-push failed: ' . $e->getMessage(), 500);
        }
    }

    public function refetchFromAboutYou(Request $request, AboutYouClient $aboutYouClient, AboutYouMapper $aboutYouMapper)
    {
        $validated = $request->validate([
            'since' => ['nullable', 'string'],
        ]);

        $since = trim((string) ($validated['since'] ?? ''));
        if ($since === '') {
            $since = null;
        }

        $orders = $aboutYouClient->getNewOrders($since);
        $repository = new OrderRepository();
        $updated = 0;
        $failed = 0;
        $failedIds = [];

        foreach ($orders as $ayOrder) {
            $ayOrderId = (string) ($ayOrder['id'] ?? $ayOrder['order_id'] ?? $ayOrder['order_number'] ?? '');
            try {
                $orderDbId = $repository->createFromAy($ayOrder, $ayOrderId);
                $mapped = $aboutYouMapper->mapAyOrderToPs($ayOrder);
                $repository->saveItems($orderDbId, $mapped['items'] ?? []);
                $updated++;
            } catch (\Throwable) {
                $failed++;
                if ($ayOrderId !== '') {
                    $failedIds[] = $ayOrderId;
                }
            }
        }

        return $this->success([
            'updated' => $updated,
            'requested' => count($orders),
            'failed' => $failed,
            'failed_ids' => array_values(array_unique($failedIds)),
            'since' => $since,
        ], 'orders refetched');
    }
}
