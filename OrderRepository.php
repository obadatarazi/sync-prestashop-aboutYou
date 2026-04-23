<?php

namespace SyncBridge\Database;

/**
 * OrderRepository
 * All database read/write for orders and order items.
 */
class OrderRepository
{
    public function findAll(int $page = 1, int $perPage = 20, array $filters = []): array
    {
        $offset = ($page - 1) * $perPage;
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[]  = 'o.sync_status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['search'])) {
            $where[]  = '(o.ay_order_id LIKE ? OR o.customer_email LIKE ? OR o.customer_name LIKE ?)';
            $q = '%' . $filters['search'] . '%';
            $params[] = $q; $params[] = $q; $params[] = $q;
        }

        $whereStr = implode(' AND ', $where);

        $total = (int) Database::fetchValue(
            "SELECT COUNT(*) FROM orders o WHERE {$whereStr}",
            $params
        );

        $rows = Database::fetchAll(
            "SELECT o.*,
                (SELECT COUNT(*) FROM order_items i WHERE i.order_id = o.id) AS item_count
             FROM orders o
             WHERE {$whereStr}
             ORDER BY o.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return ['total' => $total, 'rows' => $rows, 'page' => $page, 'per_page' => $perPage];
    }

    public function findByAyOrderId(string $ayOrderId): ?array
    {
        return Database::fetchOne('SELECT * FROM orders WHERE ay_order_id = ?', [$ayOrderId]);
    }

    public function isProcessed(string $ayOrderId): bool
    {
        $row = Database::fetchOne(
            "SELECT id FROM orders WHERE ay_order_id = ? AND sync_status IN ('imported','status_pushed')",
            [$ayOrderId]
        );
        return $row !== null;
    }

    public function createFromAy(array $ayOrder, string $ayOrderId): int
    {
        $total   = $ayOrder['total_price'] ?? $ayOrder['cost_with_tax'] ?? 0;
        $sub     = $ayOrder['subtotal']    ?? $ayOrder['cost_without_tax'] ?? $total;
        $ship    = $ayOrder['shipping_price'] ?? 0;
        $email   = $ayOrder['customer']['email'] ?? $ayOrder['customer_email'] ?? null;
        $fn      = $ayOrder['customer']['first_name'] ?? $ayOrder['shipping_recipient_first_name'] ?? '';
        $ln      = $ayOrder['customer']['last_name']  ?? $ayOrder['shipping_recipient_last_name']  ?? '';
        $name    = trim("{$fn} {$ln}") ?: null;

        Database::execute(
            "INSERT INTO orders (ay_order_id, customer_email, customer_name,
               total_paid, total_products, total_shipping, discount_total, currency, shipping_country_iso, billing_country_iso,
               shipping_method, payment_method, shipping_address_json, billing_address_json, ay_status, ay_created_at, sync_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
             ON DUPLICATE KEY UPDATE
               customer_email=VALUES(customer_email), customer_name=VALUES(customer_name),
               total_paid=VALUES(total_paid), total_products=VALUES(total_products),
               total_shipping=VALUES(total_shipping), discount_total=VALUES(discount_total), currency=VALUES(currency),
               shipping_country_iso=VALUES(shipping_country_iso),
               billing_country_iso=VALUES(billing_country_iso),
               shipping_method=VALUES(shipping_method),
               payment_method=VALUES(payment_method),
               shipping_address_json=VALUES(shipping_address_json),
               billing_address_json=VALUES(billing_address_json),
               ay_status=VALUES(ay_status), ay_created_at=VALUES(ay_created_at), updated_at=NOW()",
            [
                $ayOrderId,
                $email,
                $name,
                (float) $total,
                (float) $sub,
                (float) $ship,
                (float) ($ayOrder['discount_total'] ?? 0),
                $ayOrder['currency'] ?? 'EUR',
                strtoupper((string) ($ayOrder['shipping_country_code'] ?? '')),
                strtoupper((string) ($ayOrder['billing_country_code'] ?? $ayOrder['shipping_country_code'] ?? '')),
                (string) ($ayOrder['carrier_key'] ?? ''),
                (string) ($ayOrder['payment_method'] ?? ''),
                json_encode([
                    'first_name' => (string) ($ayOrder['shipping_recipient_first_name'] ?? ''),
                    'last_name' => (string) ($ayOrder['shipping_recipient_last_name'] ?? ''),
                    'address1' => (string) ($ayOrder['shipping_street'] ?? ''),
                    'address2' => (string) ($ayOrder['shipping_additional'] ?? ''),
                    'postcode' => (string) ($ayOrder['shipping_zip_code'] ?? ''),
                    'city' => (string) ($ayOrder['shipping_city'] ?? ''),
                    'country_iso' => strtoupper((string) ($ayOrder['shipping_country_code'] ?? '')),
                    'phone' => (string) ($ayOrder['shipping_phone_number'] ?? ''),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode([
                    'first_name' => (string) ($ayOrder['billing_recipient_first_name'] ?? ''),
                    'last_name' => (string) ($ayOrder['billing_recipient_last_name'] ?? ''),
                    'address1' => (string) ($ayOrder['billing_street'] ?? ''),
                    'address2' => (string) ($ayOrder['billing_additional'] ?? ''),
                    'postcode' => (string) ($ayOrder['billing_zip_code'] ?? ''),
                    'city' => (string) ($ayOrder['billing_city'] ?? ''),
                    'country_iso' => strtoupper((string) ($ayOrder['billing_country_code'] ?? '')),
                    'phone' => (string) ($ayOrder['billing_phone_number'] ?? ''),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $ayOrder['status'] ?? 'open',
                !empty($ayOrder['created_at']) ? date('Y-m-d H:i:s', strtotime((string) $ayOrder['created_at'])) : null,
            ]
        );

        $row = Database::fetchOne('SELECT id FROM orders WHERE ay_order_id = ?', [$ayOrderId]);
        return (int) ($row['id'] ?? Database::lastInsertId());
    }

    public function markImported(string $ayOrderId, int $psOrderId): void
    {
        Database::execute(
            "UPDATE orders SET sync_status='imported', ps_order_id=?,
             last_synced_at=NOW(), sync_attempts=sync_attempts+1
             WHERE ay_order_id=?",
            [$psOrderId, $ayOrderId]
        );
    }

    public function markError(string $ayOrderId, string $error, bool $permanent = false): void
    {
        $status = $permanent ? 'quarantined' : 'error';
        Database::execute(
            "UPDATE orders SET sync_status=?, error_message=?, is_permanent_failure=?,
             sync_attempts=sync_attempts+1, updated_at=NOW()
             WHERE ay_order_id=?",
            [$status, $error, $permanent ? 1 : 0, $ayOrderId]
        );
    }

    public function markStatusPushed(string $ayOrderId): void
    {
        Database::execute(
            "UPDATE orders SET sync_status='status_pushed', last_synced_at=NOW() WHERE ay_order_id=?",
            [$ayOrderId]
        );
    }

    public function saveItems(int $orderId, array $items): void
    {
        foreach ($items as $item) {
            $price = (float)($item['price'] ?? $item['unit_price'] ?? 0);
            if (is_int($item['price'] ?? null)) $price /= 100;
            Database::execute(
            "INSERT INTO order_items (order_id, ay_order_item_id, sku, ean13, product_id, combo_id, quantity, unit_price, discount_amount, item_status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   sku=VALUES(sku), ean13=VALUES(ean13), product_id=VALUES(product_id),
                   combo_id=VALUES(combo_id), quantity=VALUES(quantity), unit_price=VALUES(unit_price), discount_amount=VALUES(discount_amount),
                   item_status=VALUES(item_status)",
                [
                    $orderId,
                    $item['ay_order_item_id'] ?? null,
                    $item['sku'] ?? null,
                    $item['ean'] ?? $item['ean13'] ?? null,
                    $item['product_id'] ?? null,
                    $item['combo_id'] ?? null,
                    (int)($item['quantity'] ?? 1),
                    $price,
                    (float)($item['discount_amount'] ?? 0),
                    $item['status'] ?? null,
                ]
            );
        }
    }

    public function getItems(int $orderId): array
    {
        return Database::fetchAll('SELECT * FROM order_items WHERE order_id = ?', [$orderId]);
    }

    public function updateOrderDetails(int $orderId, array $payload): void
    {
        Database::execute(
            "UPDATE orders
             SET customer_email = ?, customer_name = ?, total_paid = ?, total_products = ?, total_shipping = ?,
                 discount_total = ?, currency = ?, shipping_country_iso = ?, billing_country_iso = ?, shipping_method = ?, payment_method = ?,
                 shipping_address_json = NULLIF(?, ''), billing_address_json = NULLIF(?, ''),
                 ay_status = ?, sync_status = ?, error_message = NULLIF(?, ''), updated_at = NOW()
             WHERE id = ?",
            [
                trim((string) ($payload['customer_email'] ?? '')),
                trim((string) ($payload['customer_name'] ?? '')),
                (float) ($payload['total_paid'] ?? 0),
                (float) ($payload['total_products'] ?? 0),
                (float) ($payload['total_shipping'] ?? 0),
                (float) ($payload['discount_total'] ?? 0),
                strtoupper(trim((string) ($payload['currency'] ?? 'EUR'))),
                strtoupper(trim((string) ($payload['shipping_country_iso'] ?? ''))),
                strtoupper(trim((string) ($payload['billing_country_iso'] ?? ''))),
                trim((string) ($payload['shipping_method'] ?? '')),
                trim((string) ($payload['payment_method'] ?? '')),
                trim((string) ($payload['shipping_address_json'] ?? '')),
                trim((string) ($payload['billing_address_json'] ?? '')),
                trim((string) ($payload['ay_status'] ?? 'open')),
                trim((string) ($payload['sync_status'] ?? 'pending')),
                (string) ($payload['error_message'] ?? ''),
                $orderId,
            ]
        );
    }

    public function updateOrderItemDetails(int $orderId, int $itemId, array $payload): void
    {
        Database::execute(
            "UPDATE order_items
             SET sku = ?, ean13 = ?, quantity = ?, unit_price = ?, item_status = ?, discount_amount = ?
             WHERE id = ? AND order_id = ?",
            [
                trim((string) ($payload['sku'] ?? '')),
                trim((string) ($payload['ean13'] ?? '')),
                max(1, (int) ($payload['quantity'] ?? 1)),
                (float) ($payload['unit_price'] ?? 0),
                trim((string) ($payload['item_status'] ?? 'open')),
                (float) ($payload['discount_amount'] ?? 0),
                $itemId,
                $orderId,
            ]
        );
    }

    public function deleteOrderItems(int $orderId, array $itemIds): void
    {
        $ids = array_values(array_filter(array_map('intval', $itemIds), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        Database::execute(
            "DELETE FROM order_items WHERE order_id = ? AND id IN ({$placeholders})",
            array_merge([$orderId], $ids)
        );
    }

    public function getStats(): array
    {
        return Database::fetchOne(
            "SELECT COUNT(*) AS total,
               SUM(sync_status='imported') AS imported,
               SUM(sync_status='pending') AS pending,
               SUM(sync_status='error') AS error,
               SUM(sync_status='quarantined') AS quarantined,
               SUM(sync_status='status_pushed') AS status_pushed
             FROM orders"
        ) ?? [];
    }

    public function getLatestAyCreatedAt(): ?string
    {
        return Database::fetchValue(
            "SELECT DATE_FORMAT(MAX(ay_created_at), '%Y-%m-%d %H:%i:%s') FROM orders WHERE ay_created_at IS NOT NULL"
        );
    }
}
