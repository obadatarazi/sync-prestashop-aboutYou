<?php

namespace SyncBridge\Services;

use SyncBridge\Database\OrderRepository;
use SyncBridge\Database\ProductRepository;
use SyncBridge\Database\RetryJobRepository;
use SyncBridge\Database\SyncRunRepository;

/**
 * OrderSyncService
 *
 * Flow:
 *   Import:       AY orders → local DB → PrestaShop
 *   Status push:  PrestaShop order state → AY order status
 */
class OrderSyncService
{
    private OrderRepository   $orders;
    private ProductRepository $products;
    private SyncRunRepository $runs;
    private RetryJobRepository $retryJobs;
    private DbSyncLogger      $logger;
    private string $runId;
    private mixed $ps;
    private mixed $ay;
    private mixed $mapper;
    private int $maxAttempts;

    private array $stats = [
        'ay_orders_fetched' => 0,
        'orders_imported'   => 0,
        'orders_skipped'    => 0,
        'orders_failed'     => 0,
        'statuses_pushed'   => 0,
        'status_push_failed'=> 0,
    ];

    public function __construct(string $runId, mixed $ps, mixed $ay, mixed $mapper)
    {
        $this->runId       = $runId;
        $this->ps          = $ps;
        $this->ay          = $ay;
        $this->mapper      = $mapper;
        $this->orders      = new OrderRepository();
        $this->products    = new ProductRepository();
        $this->runs        = new SyncRunRepository();
        $this->retryJobs   = new RetryJobRepository();
        $this->logger      = new DbSyncLogger($runId, 'orders');
        $this->maxAttempts = (int)($_ENV['ORDER_IMPORT_MAX_ATTEMPTS'] ?? 3);
    }

    // ----------------------------------------------------------------
    // IMPORT  AY → DB → PS
    // ----------------------------------------------------------------

    public function importNewOrders(?string $since = null): array
    {
        $this->resetStats();
        $this->logger->info('OrderSyncService::importNewOrders started');

        $ayOrders = $this->ay->getNewOrders($since);
        $this->stats['ay_orders_fetched'] = count($ayOrders);
        $this->logger->info("Fetched {$this->stats['ay_orders_fetched']} new orders from AboutYou");

        foreach ($ayOrders as $ayOrder) {
            $this->importSingleOrder($ayOrder);
        }

        $this->logger->info('OrderSyncService::importNewOrders completed', $this->stats);
        return $this->stats;
    }

    private function importSingleOrder(array $ayOrder): void
    {
        $ayId = (string)($ayOrder['id'] ?? $ayOrder['order_id'] ?? $ayOrder['order_number'] ?? '');
        if (!$ayId) { $this->stats['orders_skipped']++; return; }

        // Dedup check
        if ($this->orders->isProcessed($ayId)) {
            $this->stats['orders_skipped']++;
            return;
        }

        // Check quarantine
        $existing = $this->orders->findByAyOrderId($ayId);
        if ($existing && $existing['sync_status'] === 'quarantined') {
            $this->stats['orders_skipped']++;
            $this->logger->warning("Order {$ayId} is quarantined — skipping");
            return;
        }

        try {
            // ── 1. Save to DB ─────────────────────────────────────────
            $orderId = $this->orders->createFromAy($ayOrder, $ayId);

            // ── 2. Map AY order to PS structure ───────────────────────
            $mapped = $this->mapper->mapAyOrderToPs($ayOrder);

            // ── 3. Save order items to DB ──────────────────────────────
            $this->orders->saveItems($orderId, $mapped['items'] ?? []);

            // ── 4. Resolve items to PS product/combo IDs ──────────────
            $resolved = $this->resolveItems($mapped['items'] ?? [], $ayId);
            if (empty($resolved)) {
                throw new \RuntimeException("Could not resolve any items to PS products for AY#{$ayId}");
            }

            // ── 5. Find/create PS customer ────────────────────────────
            $psCustomerId = $this->ps->findOrCreateCustomer($mapped['customer']);
            if (!$psCustomerId) {
                throw new \RuntimeException("Could not find/create PS customer for AY#{$ayId}");
            }

            // ── 6. Create PS address ──────────────────────────────────
            $psAddressId = $this->ps->findOrCreateAddress($psCustomerId, $mapped['address']);
            if (!$psAddressId) {
                throw new \RuntimeException("Could not create PS address for AY#{$ayId}");
            }

            // ── 7. Create PS order ────────────────────────────────────
            $orderPayload = array_merge($mapped, [
                'id_customer'         => $psCustomerId,
                'id_address_delivery' => $psAddressId,
                'id_address_invoice'  => $psAddressId,
                'id_cart'             => 0,
                'items'               => $resolved,
            ]);
            $psOrderId = $this->ps->createOrder($orderPayload);
            if (!$psOrderId) {
                throw new \RuntimeException("Failed to create PS order for AY#{$ayId}");
            }

            // ── 8. Mark imported in DB ────────────────────────────────
            $this->orders->markImported($ayId, (int)$psOrderId);

            // ── 9. Update item product_id/combo_id in DB ──────────────
            foreach ($resolved as $item) {
                \SyncBridge\Database\Database::execute(
                    "UPDATE order_items SET product_id=?, combo_id=? WHERE order_id=? AND sku=?",
                    [$item['product_id'], $item['combo_id'] ?? null, $orderId, $item['sku'] ?? '']
                );
            }

            $this->stats['orders_imported']++;
            $this->logger->info("✓ Imported AY#{$ayId} → PS#{$psOrderId}");
            $this->retryJobs->markDone('order_import', $ayId);

            // Acknowledge in AY
            $this->ay->updateOrderStatus($ayId, 'processing');

        } catch (\Throwable $e) {
            $this->stats['orders_failed']++;
            $permanent = $this->isPermanent($e);
            $this->orders->markError($ayId, $e->getMessage(), $permanent);
            $this->logger->error("✗ Failed AY#{$ayId}: " . $e->getMessage());
            if (!$permanent) {
                $this->retryJobs->enqueue('order_import', $ayId, ['ay_order_id' => $ayId], $e->getMessage());
            }
        }
    }

    // ----------------------------------------------------------------
    // STATUS PUSH  PS → AY
    // ----------------------------------------------------------------

    public function pushOrderStatusUpdates(?string $since = null): array
    {
        $this->resetStats();
        $since = $since ?? date('Y-m-d H:i:s', strtotime('-1 hour'));
        $this->logger->info("OrderSyncService::pushOrderStatusUpdates since {$since}");

        $psOrders = $this->ps->getOrdersModifiedSince($since);
        foreach ($psOrders as $psOrder) {
            $psId  = (int)($psOrder['id'] ?? 0);
            $ayRow = \SyncBridge\Database\Database::fetchOne(
                'SELECT ay_order_id FROM orders WHERE ps_order_id = ?', [$psId]
            );
            if (!$ayRow) continue;

            $ayStatus = $this->mapper->mapPsStatusToAy($psOrder);
            $extra    = [];
            if ($ayStatus === 'shipped' && !empty($psOrder['shipping_number'])) {
                $extra['tracking_number'] = $psOrder['shipping_number'];
            }

            if ($this->ay->updateOrderStatus($ayRow['ay_order_id'], $ayStatus, $extra)) {
                $this->orders->markStatusPushed($ayRow['ay_order_id']);
                $this->retryJobs->markDone('order_status', (string) $ayRow['ay_order_id']);
                $this->stats['statuses_pushed']++;
                $this->logger->info("✓ Status PS#{$psId} → AY: {$ayStatus}");
            } else {
                $this->stats['status_push_failed']++;
                $this->logger->error("✗ Status push failed PS#{$psId}");
                $this->retryJobs->enqueue('order_status', (string) $ayRow['ay_order_id'], [
                    'ps_order_id' => $psId,
                    'ay_status' => $ayStatus,
                ], 'Status push failed');
            }
        }

        $this->logger->info('OrderSyncService::pushOrderStatusUpdates completed', $this->stats);
        return $this->stats;
    }

    // ----------------------------------------------------------------
    // HELPERS
    // ----------------------------------------------------------------

    private function resolveItems(array $items, string $ayId): array
    {
        $resolved = [];
        foreach ($items as $item) {
            $sku = trim((string)($item['sku'] ?? ''));

            // Try DB mapping first (fastest)
            $dbRow = \SyncBridge\Database\Database::fetchOne(
                "SELECT v.product_id AS product_id, v.ps_combo_id AS combo_id
                 FROM product_variants v WHERE UPPER(v.sku) = UPPER(?)",
                [$sku]
            );
            if ($dbRow) {
                $resolved[] = array_merge($item, $dbRow);
                continue;
            }

            // Try PS API by reference
            if ($sku !== '') {
                $combo = $this->ps->findCombinationByReference($sku);
                if ($combo) { $resolved[] = array_merge($item, $combo); continue; }

                $prodId = $this->ps->findProductIdByReference($sku);
                if ($prodId) { $resolved[] = array_merge($item, ['product_id' => $prodId, 'combo_id' => 0]); continue; }

                $comboByEan = $this->ps->findCombinationByEan($sku);
                if ($comboByEan) { $resolved[] = array_merge($item, $comboByEan); continue; }

                $prodByEan = $this->ps->findProductIdByEan($sku);
                if ($prodByEan) { $resolved[] = array_merge($item, ['product_id' => $prodByEan, 'combo_id' => 0]); continue; }
            }

            $this->logger->warning("Could not resolve SKU to PS product", ['sku' => $sku]);
        }
        return $resolved;
    }

    private function isPermanent(\Throwable $e): bool
    {
        $hints = ['validation error','missing or invalid','could not resolve','could not find/create'];
        $msg = strtolower($e->getMessage());
        foreach ($hints as $h) if (str_contains($msg, $h)) return true;
        return false;
    }

    private function resetStats(): void
    {
        $this->stats = [
            'ay_orders_fetched' => 0, 'orders_imported' => 0, 'orders_skipped' => 0,
            'orders_failed' => 0, 'statuses_pushed' => 0, 'status_push_failed' => 0,
        ];
    }

    public function getStats(): array { return $this->stats; }
}
