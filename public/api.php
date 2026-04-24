<?php

declare(strict_types=1);

/**
 * SyncBridge API  –  public/api.php
 *
 * All endpoints return JSON. Auth via PHP session + CSRF.
 * Endpoints:
 *   POST  action=login
 *   POST  action=logout
 *   GET/POST action=status
 *   POST  action=sync          {command, ps_product_ids?, ps_order_ids?}
 *   GET   action=products      {page, per_page, status, search, source}
 *   GET   action=products_compare {page, per_page, bucket, search}
 *   POST  action=products_recheck_ay {ps_product_ids[]}
 *   GET   action=product_detail {product_id}
 *   GET   action=orders        {page, per_page, status, search}
 *   POST  action=order_save    {order_id, order:{...}, items:[...]}
 *   GET   action=logs          {run_id?, level?, channel?, search?, limit?}
 *   POST  action=logs_delete   {clear_files?}
 *   GET   action=sync_runs
 *   GET   action=images
 *   GET   action=settings
 *   POST  action=settings_save {settings: {}}
 *   POST  action=toggle        {key, value}
 */

use SyncBridge\Database\Database;
use SyncBridge\Database\ProductRepository;
use SyncBridge\Database\OrderRepository;
use SyncBridge\Database\SyncRunRepository;
use SyncBridge\Integration\AboutYouClient;
use SyncBridge\Integration\PrestaShopClient;
use SyncBridge\Services\SyncRunner;
use SyncBridge\Support\AttributeTypeGuesser;
use SyncBridge\Support\HttpClient;
use SyncBridge\Support\AyDocsPolicy;
use SyncBridge\Support\ImageNormalizer;
use SyncBridge\Database\SyncMetricsRepository;

require_once __DIR__ . '/../src/bootstrap.php';

// Increase execution time for API endpoints with multiple requests
ini_set('max_execution_time', '120');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Keep authenticated session cookie for 3 days.
$sessionLifetime = 60 * 60 * 24 * 3;
session_set_cookie_params([
    'lifetime' => $sessionLifetime,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// ----------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------
function json_out(int $code, array $body): never
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function bounded_page_size(mixed $requested, int $default, int $max): int
{
    $value = (int) $requested;
    if ($value <= 0) {
        $value = $default;
    }
    return min($max, max(1, $value));
}

function require_auth(): void
{
    if (empty($_SESSION['authenticated'])) {
        json_out(401, ['ok' => false, 'error' => 'Not authenticated']);
    }
}

function require_csrf(array $input): void
{
    $sent = (string)($input['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    $exp  = (string)($_SESSION['csrf'] ?? '');
    if ($exp === '' || !hash_equals($exp, $sent)) {
        json_out(403, ['ok' => false, 'error' => 'Invalid CSRF token']);
    }
}

function new_csrf(): string
{
    $t = bin2hex(random_bytes(32));
    $_SESSION['csrf'] = $t;
    return $t;
}

function product_detail_remote_cache_ttl(): int
{
    $ttl = (int) ($_ENV['PRODUCT_DETAIL_REMOTE_CACHE_TTL'] ?? 90);
    return max(0, $ttl);
}

function product_detail_remote_cache_dir(): string
{
    return sys_get_temp_dir() . '/syncbridge_product_detail_remote_cache';
}

function product_detail_remote_cache_path(int $psId): string
{
    return product_detail_remote_cache_dir() . '/ps_' . $psId . '.json';
}

function get_product_detail_remote_cache(int $psId): ?array
{
    $ttl = product_detail_remote_cache_ttl();
    if ($ttl <= 0) {
        return null;
    }

    $path = product_detail_remote_cache_path($psId);
    if (!is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    if ($raw === false) {
        return null;
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (\Throwable) {
        return null;
    }

    if (!is_array($decoded)) {
        return null;
    }

    $createdAt = (int) ($decoded['created_at'] ?? 0);
    if ($createdAt <= 0 || (time() - $createdAt) > $ttl) {
        return null;
    }

    $data = $decoded['data'] ?? null;
    return is_array($data) ? $data : null;
}

function set_product_detail_remote_cache(int $psId, array $data): void
{
    $ttl = product_detail_remote_cache_ttl();
    if ($ttl <= 0) {
        return;
    }

    $dir = product_detail_remote_cache_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_dir($dir)) {
        return;
    }

    $payload = json_encode([
        'created_at' => time(),
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        return;
    }

    @file_put_contents(product_detail_remote_cache_path($psId), $payload, LOCK_EX);
}

function clear_product_detail_remote_cache(int $psId): void
{
    @unlink(product_detail_remote_cache_path($psId));
}

// Parse request
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$raw    = file_get_contents('php://input');
$input  = [];
if ($raw) {
    try { $input = json_decode($raw, true, 512, JSON_THROW_ON_ERROR) ?? []; } catch (\Throwable) {}
}
// Allow GET params too
$input = array_merge($_GET, $input);
$action = (string)($input['action'] ?? '');

// ----------------------------------------------------------------
// LOGIN (no auth required)
// ----------------------------------------------------------------
if ($action === 'login') {
    $user = trim((string)($input['username'] ?? ''));
    $pass = (string)($input['password'] ?? '');

    try {
        $row = Database::fetchOne('SELECT * FROM users WHERE username = ?', [$user]);
    } catch (\Throwable $e) {
        json_out(503, ['ok' => false, 'error' => 'Database unavailable: ' . $e->getMessage()]);
    }

    if (!$row || !password_verify($pass, $row['password_hash'])) {
        json_out(401, ['ok' => false, 'error' => 'Invalid credentials']);
    }

    $_SESSION['authenticated'] = true;
    $_SESSION['user_id']        = $row['id'];
    $_SESSION['username']       = $row['username'];

    Database::execute('UPDATE users SET last_login_at=NOW() WHERE id=?', [$row['id']]);

    json_out(200, ['ok' => true, 'data' => ['csrf' => new_csrf(), 'username' => $row['username']]]);
}

// ----------------------------------------------------------------
// All other routes require auth
// ----------------------------------------------------------------
require_auth();

if ($action === 'logout') {
    require_csrf($input);
    session_destroy();
    json_out(200, ['ok' => true, 'data' => []]);
}

if ($action === 'csrf') {
    json_out(200, ['ok' => true, 'data' => ['csrf' => new_csrf()]]);
}

// ----------------------------------------------------------------
// STATUS
// ----------------------------------------------------------------
if ($action === 'status') {
    $runs = new SyncRunRepository();
    $products = new ProductRepository();
    $orders   = new OrderRepository();
    $pidPath = resolveSyncPidPath();
    $syncPid = is_file($pidPath) ? (int) trim((string) @file_get_contents($pidPath)) : 0;

    $policyWarnings = [];
    try {
        $policy = new AyDocsPolicy();
        $configured = (int) ($_ENV['AY_MIN_INTERVAL_MS'] ?? 650);
        $recommended = $policy->minIntervalMsForPath('/products/stocks', 650);
        if ($configured < $recommended) {
            $policyWarnings[] = sprintf(
                'AY_MIN_INTERVAL_MS (%d) is lower than docs-policy recommendation (%d) for stock updates.',
                $configured,
                $recommended
            );
        }
    } catch (\Throwable) {
    }

    json_out(200, ['ok' => true, 'data' => [
        'products'    => $products->getStats(),
        'orders'      => $orders->getStats(),
        'images'      => $products->getImageStats(),
        'current_run' => $runs->getCurrent(),
        'sync_pid'    => $syncPid > 0 ? $syncPid : null,
        'recent_runs' => $runs->getRecent(5),
        'policy_warnings' => $policyWarnings,
        'connections' => [
            'ps_configured' => trim((string) ($_ENV['PS_BASE_URL'] ?? '')) !== '' && trim((string) ($_ENV['PS_API_KEY'] ?? '')) !== '',
            'ay_configured' => trim((string) ($_ENV['AY_BASE_URL'] ?? '')) !== '' && trim((string) ($_ENV['AY_API_KEY'] ?? '')) !== '',
        ],
    ]]);
}

// ----------------------------------------------------------------
// PRODUCTS
// ----------------------------------------------------------------
if ($action === 'products') {
    $repo = new ProductRepository();
    $page    = max(1, (int)($input['page'] ?? 1));
    $perPage = bounded_page_size($input['per_page'] ?? null, 20, 50);
    $result  = $repo->findAll($page, $perPage, [
        'status' => $input['status'] ?? '',
        'search' => $input['search'] ?? '',
    ]);
    json_out(200, ['ok' => true, 'data' => $result]);
}

if ($action === 'products_compare') {
    $repo = new ProductRepository();
    $page    = max(1, (int)($input['page'] ?? 1));
    $perPage = bounded_page_size($input['per_page'] ?? null, 20, 50);
    $bucket = strtolower(trim((string) ($input['bucket'] ?? 'not_synced')));
    if (!in_array($bucket, ['', 'synced', 'not_synced'], true)) {
        json_out(400, ['ok' => false, 'error' => 'bucket must be synced or not_synced']);
    }
    $result = $repo->findComparison($page, $perPage, [
        'bucket' => $bucket,
        'search' => $input['search'] ?? '',
    ]);
    json_out(200, ['ok' => true, 'data' => $result]);
}

if ($action === 'products_recheck_ay') {
    require_csrf($input);
    $repo = new ProductRepository();
    $psIds = array_values(array_unique(array_filter(array_map('intval', (array)($input['ps_product_ids'] ?? [])))));
    if ($psIds === []) {
        json_out(400, ['ok' => false, 'error' => 'Provide at least one ps_product_id']);
    }
    if (count($psIds) > 100) {
        json_out(400, ['ok' => false, 'error' => 'Maximum 100 products per recheck']);
    }

    $products = $repo->findByPsIds($psIds);
    if ($products === []) {
        json_out(404, ['ok' => false, 'error' => 'No matching products found']);
    }

    $styleKeys = [];
    foreach ($products as $product) {
        $styleKey = trim((string) ($product['ay_style_key'] ?? ''));
        if ($styleKey !== '') {
            $styleKeys[$styleKey] = true;
        }
    }

    $found = [];
    if ($styleKeys !== []) {
        try {
            $http = new HttpClient(
                (int) ($_ENV['AY_HTTP_TIMEOUT'] ?? 15),
                (int) ($_ENV['AY_HTTP_CONNECT_TIMEOUT'] ?? 8),
            );
            $ay = new AboutYouClient($http);
            $found = array_fill_keys($ay->findExistingStyleKeys(array_keys($styleKeys)), true);
        } catch (\Throwable $e) {
            json_out(500, ['ok' => false, 'error' => 'AboutYou recheck failed: ' . $e->getMessage()]);
        }
    }

    $results = [];
    foreach ($products as $product) {
        $id = (int) ($product['id'] ?? 0);
        $psId = (int) ($product['ps_id'] ?? 0);
        $styleKey = trim((string) ($product['ay_style_key'] ?? ''));

        if ($styleKey === '') {
            Database::execute(
                "UPDATE products
                 SET sync_status = CASE WHEN sync_status='synced' THEN 'pending' ELSE sync_status END,
                     sync_error = ?,
                     updated_at = NOW()
                 WHERE id = ?",
                ['Missing AY style key for manual recheck', $id]
            );
            $results[] = [
                'ps_id' => $psId,
                'status' => 'not_synced',
                'reason' => 'Missing AY style key',
                'ay_style_key' => null,
            ];
            continue;
        }

        if (!empty($found[$styleKey])) {
            Database::execute(
                "UPDATE products
                 SET sync_status='synced', sync_error=NULL, last_synced_at=NOW(), updated_at=NOW()
                 WHERE id=?",
                [$id]
            );
            $results[] = [
                'ps_id' => $psId,
                'status' => 'synced',
                'reason' => 'Found on AboutYou',
                'ay_style_key' => $styleKey,
            ];
            continue;
        }

        $error = 'AY style key not found during manual recheck';
        Database::execute(
            "UPDATE products SET sync_status='error', sync_error=?, updated_at=NOW() WHERE id=?",
            [$error, $id]
        );
        $results[] = [
            'ps_id' => $psId,
            'status' => 'not_synced',
            'reason' => $error,
            'ay_style_key' => $styleKey,
        ];
    }

    $summary = [
        'checked' => count($results),
        'synced' => count(array_filter($results, static fn (array $row): bool => $row['status'] === 'synced')),
        'not_synced' => count(array_filter($results, static fn (array $row): bool => $row['status'] !== 'synced')),
    ];

    json_out(200, ['ok' => true, 'data' => ['summary' => $summary, 'results' => $results]]);
}

if ($action === 'product_detail') {
    $psId = (int)($input['product_id'] ?? 0);
    if (!$psId) json_out(400, ['ok' => false, 'error' => 'product_id required']);
    $includeRemote = filter_var($input['include_remote'] ?? true, FILTER_VALIDATE_BOOLEAN);

    json_out(200, ['ok' => true, 'data' => build_product_detail_payload($psId, $includeRemote)]);
}

if ($action === 'product_save') {
    require_csrf($input);
    $psId = (int)($input['product_id'] ?? 0);
    if (!$psId) json_out(400, ['ok' => false, 'error' => 'product_id required']);

    $repo = new ProductRepository();
    if (!$repo->findByPsId($psId)) {
        json_out(404, ['ok' => false, 'error' => 'Product not found']);
    }

    $psApiPayloadRaw = trim((string) ($input['ps_api_payload'] ?? ''));
    if ($psApiPayloadRaw !== '') {
        try {
            json_decode($psApiPayloadRaw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            json_out(422, ['ok' => false, 'error' => 'Invalid ps_api_payload JSON: ' . $e->getMessage()]);
        }
    }

    $repo->saveExportOverridesByPsId($psId, [
        'export_title' => $input['export_title'] ?? null,
        'export_description' => $input['export_description'] ?? null,
        'export_material_composition' => $input['export_material_composition'] ?? null,
        'ps_api_payload' => $psApiPayloadRaw !== '' ? $psApiPayloadRaw : null,
        'ay_category_id' => $input['ay_category_id'] ?? null,
        'ay_brand_id' => $input['ay_brand_id'] ?? null,
    ]);
    clear_product_detail_remote_cache($psId);

    json_out(200, ['ok' => true, 'data' => build_product_detail_payload($psId)]);
}

if ($action === 'product_variant_eans_save') {
    require_csrf($input);
    $psId = (int)($input['product_id'] ?? 0);
    if (!$psId) {
        json_out(400, ['ok' => false, 'error' => 'product_id required']);
    }
    $variantEans = $input['variant_eans'] ?? null;
    if (!is_array($variantEans)) {
        json_out(400, ['ok' => false, 'error' => 'variant_eans must be an array']);
    }

    $repo = new ProductRepository();
    if (!$repo->findByPsId($psId)) {
        json_out(404, ['ok' => false, 'error' => 'Product not found']);
    }

    foreach ($variantEans as $row) {
        if (!is_array($row)) {
            continue;
        }
        $comboId = (int) ($row['ps_combo_id'] ?? 0);
        $ean = trim((string) ($row['ean13'] ?? ''));
        if ($comboId <= 0 || $ean === '') {
            continue;
        }
        if (!is_valid_ean13($ean)) {
            json_out(400, ['ok' => false, 'error' => 'Invalid EAN13 for combination #' . $comboId]);
        }
    }

    $saved = $repo->saveVariantEansByPsId($psId, $variantEans);
    clear_product_detail_remote_cache($psId);
    json_out(200, ['ok' => true, 'data' => [
        'saved' => $saved,
        'detail' => build_product_detail_payload($psId),
    ]]);
}

if ($action === 'product_map_attributes_save') {
    require_csrf($input);
    $mappings = $input['mappings'] ?? null;
    if (!is_array($mappings)) {
        json_out(400, ['ok' => false, 'error' => 'Invalid mappings payload']);
    }

    $saved = 0;
    Database::beginTransaction();
    try {
        foreach ($mappings as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = strtolower(trim((string) ($item['map_type'] ?? '')));
            $psLabel = trim((string) ($item['ps_label'] ?? ''));
            $ayId = (int) ($item['ay_id'] ?? 0);
            $ayGroupId = (int) ($item['ay_group_id'] ?? 0);
            $ayGroupName = trim((string) ($item['ay_group_name'] ?? ''));
            if (!in_array($type, ['color', 'size', 'second_size', 'attribute', 'attribute_required'], true)
                || $psLabel === '' || $ayId <= 0) {
                continue;
            }

            Database::execute(
                "INSERT INTO attribute_maps (map_type, ps_label, ay_group_id, ay_group_name, ay_id)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE ay_group_name=VALUES(ay_group_name), ay_id=VALUES(ay_id)",
                [$type, $psLabel, $ayGroupId > 0 ? $ayGroupId : 0, $ayGroupName !== '' ? $ayGroupName : null, $ayId]
            );
            $saved++;
        }
        Database::commit();
    } catch (\Throwable $e) {
        Database::rollback();
        json_out(500, ['ok' => false, 'error' => $e->getMessage()]);
    }

    json_out(200, ['ok' => true, 'data' => ['saved' => $saved]]);
}

if ($action === 'product_auto_map_attributes') {
    require_csrf($input);
    $psId = (int)($input['product_id'] ?? 0);
    if (!$psId) json_out(400, ['ok' => false, 'error' => 'product_id required']);

    $detail = build_product_detail_payload($psId);
    $saved = [];
    foreach (['color', 'size'] as $type) {
        $options = $detail['ay_options'][$type] ?? [];
        if ($options === []) {
            continue;
        }
        $optionMap = [];
        foreach ($options as $option) {
            $optionMap[normalize_option_label((string) ($option['label'] ?? ''))] = (int) ($option['id'] ?? 0);
        }

        foreach ($detail['attribute_rows'] as $row) {
            if (($row['map_type'] ?? '') !== $type || !empty($row['ay_id'])) {
                continue;
            }
            $normalized = normalize_option_label((string) ($row['ps_label'] ?? ''));
            $matchedId = $optionMap[$normalized] ?? 0;
            if ($matchedId <= 0) {
                $matchedId = auto_match_attribute_id((string) ($row['ps_label'] ?? ''), $optionMap, $type);
            }
            if ($matchedId <= 0) {
                continue;
            }

            Database::execute(
                "INSERT INTO attribute_maps (map_type, ps_label, ay_group_id, ay_group_name, ay_id)
                 VALUES (?, ?, 0, NULL, ?)
                 ON DUPLICATE KEY UPDATE ay_id=VALUES(ay_id)",
                [$type, $row['ps_label'], $matchedId]
            );
            $saved[] = ['map_type' => $type, 'ps_label' => $row['ps_label'], 'ay_id' => $matchedId];
        }
    }

    json_out(200, ['ok' => true, 'data' => [
        'saved' => count($saved),
        'mappings' => $saved,
        'detail' => build_product_detail_payload($psId),
    ]]);
}

// ----------------------------------------------------------------
// ORDERS
// ----------------------------------------------------------------
if ($action === 'orders') {
    $repo    = new OrderRepository();
    $page    = max(1, (int)($input['page'] ?? 1));
    $perPage = bounded_page_size($input['per_page'] ?? null, 20, 50);
    $result  = $repo->findAll($page, $perPage, [
        'status' => $input['status'] ?? '',
        'search' => $input['search'] ?? '',
    ]);
    json_out(200, ['ok' => true, 'data' => $result]);
}

if ($action === 'order_items') {
    $orderId = (int)($input['order_id'] ?? 0);
    if (!$orderId) json_out(400, ['ok' => false, 'error' => 'order_id required']);
    $repo = new OrderRepository();
    json_out(200, ['ok' => true, 'data' => $repo->getItems($orderId)]);
}

if ($action === 'order_item_products_resolve') {
    $items = is_array($input['items'] ?? null) ? $input['items'] : [];
    $resolved = [];
    foreach ($items as $row) {
        if (!is_array($row)) {
            continue;
        }
        $itemId = (int) ($row['id'] ?? 0);
        $sku = trim((string) ($row['sku'] ?? ''));
        $ean = trim((string) ($row['ean13'] ?? $row['ean'] ?? ''));
        $product = null;

        if ($sku !== '') {
            $product = Database::fetchOne(
                "SELECT p.ps_id, p.name, p.reference, v.ps_combo_id, v.sku, v.ean13
                 FROM product_variants v
                 JOIN products p ON p.id = v.product_id
                 WHERE UPPER(v.sku) = UPPER(?)
                 LIMIT 1",
                [$sku]
            );
            if (!$product) {
                $product = Database::fetchOne(
                    "SELECT p.ps_id, p.name, p.reference, NULL AS ps_combo_id, p.reference AS sku, p.ean13
                     FROM products p
                     WHERE UPPER(p.reference) = UPPER(?)
                     LIMIT 1",
                    [$sku]
                );
            }
        }

        if (!$product && $ean !== '') {
            $product = Database::fetchOne(
                "SELECT p.ps_id, p.name, p.reference, v.ps_combo_id, v.sku, v.ean13
                 FROM product_variants v
                 JOIN products p ON p.id = v.product_id
                 WHERE v.ean13 = ?
                 LIMIT 1",
                [$ean]
            );
            if (!$product) {
                $product = Database::fetchOne(
                    "SELECT p.ps_id, p.name, p.reference, NULL AS ps_combo_id, p.reference AS sku, p.ean13
                     FROM products p
                     WHERE p.ean13 = ?
                     LIMIT 1",
                    [$ean]
                );
            }
        }

        $resolved[] = [
            'id' => $itemId,
            'sku' => $sku,
            'ean13' => $ean,
            'matched' => (bool) $product,
            'product' => $product ? [
                'ps_id' => (int) ($product['ps_id'] ?? 0),
                'name' => (string) ($product['name'] ?? ''),
                'reference' => (string) ($product['reference'] ?? ''),
                'ps_combo_id' => isset($product['ps_combo_id']) ? (int) $product['ps_combo_id'] : null,
                'matched_sku' => (string) ($product['sku'] ?? ''),
                'matched_ean13' => (string) ($product['ean13'] ?? ''),
            ] : null,
        ];
    }
    json_out(200, ['ok' => true, 'data' => $resolved]);
}

if ($action === 'order_save') {
    require_csrf($input);
    $orderId = (int) ($input['order_id'] ?? 0);
    if ($orderId <= 0) {
        json_out(400, ['ok' => false, 'error' => 'order_id required']);
    }

    $repo = new OrderRepository();
    $existing = Database::fetchOne('SELECT id FROM orders WHERE id = ?', [$orderId]);
    if (!$existing) {
        json_out(404, ['ok' => false, 'error' => 'Order not found']);
    }

    $orderPayload = is_array($input['order'] ?? null) ? $input['order'] : [];
    $itemsPayload = is_array($input['items'] ?? null) ? $input['items'] : [];
    $allowedSyncStatuses = ['pending', 'importing', 'imported', 'status_pushed', 'error', 'quarantined'];

    $syncStatus = strtolower(trim((string) ($orderPayload['sync_status'] ?? 'pending')));
    if (!in_array($syncStatus, $allowedSyncStatuses, true)) {
        json_out(422, ['ok' => false, 'error' => 'Invalid sync_status']);
    }

    $currency = strtoupper(trim((string) ($orderPayload['currency'] ?? 'EUR')));
    if ($currency === '' || strlen($currency) > 3) {
        json_out(422, ['ok' => false, 'error' => 'Invalid currency']);
    }
    $shippingCountryIso = strtoupper(trim((string) ($orderPayload['shipping_country_iso'] ?? '')));
    if ($shippingCountryIso !== '' && strlen($shippingCountryIso) !== 2) {
        json_out(422, ['ok' => false, 'error' => 'Invalid shipping country ISO']);
    }
    $billingCountryIso = strtoupper(trim((string) ($orderPayload['billing_country_iso'] ?? '')));
    if ($billingCountryIso !== '' && strlen($billingCountryIso) !== 2) {
        json_out(422, ['ok' => false, 'error' => 'Invalid billing country ISO']);
    }
    $shippingAddressJson = trim((string) ($orderPayload['shipping_address_json'] ?? ''));
    if ($shippingAddressJson !== '') {
        try { json_decode($shippingAddressJson, true, 512, JSON_THROW_ON_ERROR); } catch (\Throwable $e) {
            json_out(422, ['ok' => false, 'error' => 'Invalid shipping_address_json: ' . $e->getMessage()]);
        }
    }
    $billingAddressJson = trim((string) ($orderPayload['billing_address_json'] ?? ''));
    if ($billingAddressJson !== '') {
        try { json_decode($billingAddressJson, true, 512, JSON_THROW_ON_ERROR); } catch (\Throwable $e) {
            json_out(422, ['ok' => false, 'error' => 'Invalid billing_address_json: ' . $e->getMessage()]);
        }
    }

    $repo->updateOrderDetails($orderId, [
        'customer_email' => trim((string) ($orderPayload['customer_email'] ?? '')),
        'customer_name' => trim((string) ($orderPayload['customer_name'] ?? '')),
        'total_paid' => (float) ($orderPayload['total_paid'] ?? 0),
        'total_products' => (float) ($orderPayload['total_products'] ?? 0),
        'total_shipping' => (float) ($orderPayload['total_shipping'] ?? 0),
        'discount_total' => (float) ($orderPayload['discount_total'] ?? 0),
        'currency' => $currency,
        'shipping_country_iso' => $shippingCountryIso,
        'billing_country_iso' => $billingCountryIso,
        'shipping_method' => trim((string) ($orderPayload['shipping_method'] ?? '')),
        'payment_method' => trim((string) ($orderPayload['payment_method'] ?? '')),
        'shipping_address_json' => $shippingAddressJson,
        'billing_address_json' => $billingAddressJson,
        'ay_status' => trim((string) ($orderPayload['ay_status'] ?? 'open')),
        'sync_status' => $syncStatus,
        'error_message' => (string) ($orderPayload['error_message'] ?? ''),
    ]);

    foreach ($itemsPayload as $row) {
        if (!is_array($row)) {
            continue;
        }
        $itemId = (int) ($row['id'] ?? 0);
        if ($itemId <= 0) {
            continue;
        }
        $repo->updateOrderItemDetails($orderId, $itemId, [
            'sku' => trim((string) ($row['sku'] ?? '')),
            'ean13' => trim((string) ($row['ean13'] ?? '')),
            'quantity' => max(1, (int) ($row['quantity'] ?? 1)),
            'unit_price' => (float) ($row['unit_price'] ?? 0),
            'item_status' => trim((string) ($row['item_status'] ?? 'open')),
            'discount_amount' => (float) ($row['discount_amount'] ?? 0),
        ]);
    }
    $deletedItemIds = is_array($input['deleted_item_ids'] ?? null) ? $input['deleted_item_ids'] : [];
    $repo->deleteOrderItems($orderId, $deletedItemIds);

    $updatedOrder = Database::fetchOne(
        "SELECT o.*, (SELECT COUNT(*) FROM order_items i WHERE i.order_id = o.id) AS item_count
         FROM orders o WHERE o.id = ?",
        [$orderId]
    );
    $updatedItems = $repo->getItems($orderId);
    json_out(200, ['ok' => true, 'data' => ['order' => $updatedOrder, 'items' => $updatedItems]]);
}

if ($action === 'order_push') {
    require_csrf($input);
    $orderId = (int) ($input['order_id'] ?? 0);
    if ($orderId <= 0) {
        json_out(400, ['ok' => false, 'error' => 'order_id required']);
    }
    try {
        $pushResult = push_local_order_to_prestashop($orderId);
    } catch (\Throwable $e) {
        json_out(500, ['ok' => false, 'error' => $e->getMessage()]);
    }
    json_out(200, ['ok' => true, 'data' => $pushResult]);
}

if ($action === 'ps_schema_probe') {
    $resource = trim((string) ($input['resource'] ?? 'orders'));
    if ($resource === '') {
        json_out(400, ['ok' => false, 'error' => 'resource required']);
    }
    try {
        $ps = new \SyncBridge\Integration\PrestaShopClient(new \SyncBridge\Support\HttpClient());
        $raw = $ps->rawSchemaResponse($resource);
        $required = $ps->getResourceRequiredFields($resource);
    } catch (\Throwable $e) {
        json_out(500, ['ok' => false, 'error' => $e->getMessage()]);
    }
    json_out(200, ['ok' => true, 'data' => [
        'resource' => $resource,
        'required' => $required,
        'raw' => $raw,
    ]]);
}

if ($action === 'ps_api_permissions_probe') {
    try {
        $ps = new \SyncBridge\Integration\PrestaShopClient(new \SyncBridge\Support\HttpClient());
        $info = $ps->probeWebserviceAccount();
    } catch (\Throwable $e) {
        json_out(500, ['ok' => false, 'error' => $e->getMessage()]);
    }
    json_out(200, ['ok' => true, 'data' => $info]);
}

if ($action === 'ps_shop_info') {
    try {
        $ps = new \SyncBridge\Integration\PrestaShopClient(new \SyncBridge\Support\HttpClient());
        $info = [
            'order_states' => $ps->listOrderStatesBrief(),
            'modules' => $ps->listModulesBrief(),
            'carriers' => $ps->listCarriersBrief(),
        ];
    } catch (\Throwable $e) {
        json_out(500, ['ok' => false, 'error' => $e->getMessage()]);
    }
    json_out(200, ['ok' => true, 'data' => $info]);
}

// ----------------------------------------------------------------
// LOGS
// ----------------------------------------------------------------
if ($action === 'logs') {
    $repo    = new SyncRunRepository();
    $filters = [
        'run_id'  => $input['run_id']  ?? '',
        'level'   => $input['level']   ?? '',
        'channel' => $input['channel'] ?? '',
        'search'  => $input['search']  ?? '',
    ];
    $page = max(1, (int)($input['page'] ?? 1));
    $perPage = bounded_page_size($input['per_page'] ?? ($input['limit'] ?? null), 50, 200);
    json_out(200, ['ok' => true, 'data' => $repo->getLogsPage(array_filter($filters), $page, $perPage)]);
}

if ($action === 'logs_delete') {
    require_csrf($input);
    $deletedDbRows = Database::execute('DELETE FROM sync_logs');
    $deletedRuns = Database::execute("DELETE FROM sync_runs WHERE status <> 'running'");
    $filesCleared = [];
    $errors = [];

    $clearFiles = filter_var($input['clear_files'] ?? true, FILTER_VALIDATE_BOOLEAN);
    if ($clearFiles) {
        foreach (resolveLogPaths() as $path) {
            if (is_file($path)) {
                if (@file_put_contents($path, '') === false) {
                    $errors[] = "Failed to clear log file: {$path}";
                    continue;
                }
            }
            $filesCleared[] = $path;
        }
    }

    json_out(200, ['ok' => true, 'data' => [
        'deleted_db_logs' => $deletedDbRows,
        'deleted_finished_runs' => $deletedRuns,
        'cleared_files' => $filesCleared,
        'warnings' => $errors,
    ]]);
}

if ($action === 'sync_runs') {
    $repo = new SyncRunRepository();
    json_out(200, ['ok' => true, 'data' => $repo->getRecent(30)]);
}

if ($action === 'metrics') {
    $repo = new SyncMetricsRepository();
    $limit = bounded_page_size($input['limit'] ?? null, 100, 1000);
    json_out(200, ['ok' => true, 'data' => $repo->recentMetrics($limit)]);
}

if ($action === 'policy_snapshot') {
    $latest = Database::fetchOne(
        "SELECT id, source, version_tag, payload_json, created_at
         FROM ay_policy_snapshots
         ORDER BY created_at DESC
         LIMIT 1"
    );
    json_out(200, ['ok' => true, 'data' => $latest]);
}

if ($action === 'policy_snapshot_refresh') {
    require_csrf($input);
    $policy = new AyDocsPolicy();
    $payload = $policy->snapshotPayload();
    Database::execute(
        "INSERT INTO ay_policy_snapshots (source, version_tag, payload_json)
         VALUES (?, ?, ?)",
        [
            'mcp_docs',
            'local-policy-v1',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]
    );
    json_out(200, ['ok' => true, 'data' => ['saved' => true, 'payload' => $payload]]);
}

// ----------------------------------------------------------------
// IMAGES
// ----------------------------------------------------------------
if ($action === 'images') {
    $repo = new ProductRepository();
    $page    = max(1, (int)($input['page'] ?? 1));
    $perPage = bounded_page_size($input['per_page'] ?? null, 24, 50);
    $offset  = ($page - 1) * $perPage;
    $status  = $input['status'] ?? '';

    $where  = $status ? 'WHERE i.status = ?' : '';
    $params = $status ? [$status] : [];

    $total = (int) Database::fetchValue(
        "SELECT COUNT(*) FROM product_images i {$where}", $params
    );
    $rows = Database::fetchAll(
        "SELECT i.*, p.name AS product_name, p.ps_id
         FROM product_images i
         JOIN products p ON p.id = i.product_id
         {$where} ORDER BY i.updated_at DESC LIMIT {$perPage} OFFSET {$offset}",
        array_merge($params, [])
    );

    json_out(200, ['ok' => true, 'data' => [
        'total' => $total, 'rows' => $rows,
        'page' => $page, 'per_page' => $perPage,
        'stats' => $repo->getImageStats(),
    ]]);
}

if ($action === 'image_retry_failed') {
    require_csrf($input);
    $psId = (int) ($input['ps_id'] ?? 0);
    if ($psId <= 0) {
        json_out(400, ['ok' => false, 'error' => 'ps_id required']);
    }

    $repo = new ProductRepository();
    $product = $repo->findByPsId($psId);
    if (!$product) {
        json_out(404, ['ok' => false, 'error' => 'Product not found']);
    }
    $productId = (int) ($product['id'] ?? 0);
    if ($productId <= 0) {
        json_out(404, ['ok' => false, 'error' => 'Product not found']);
    }

    $failedImages = Database::fetchAll(
        "SELECT id, source_url FROM product_images WHERE product_id = ? AND status = 'error' ORDER BY position ASC, id ASC",
        [$productId]
    );
    if ($failedImages === []) {
        json_out(200, ['ok' => true, 'data' => ['retried' => 0, 'ok' => 0, 'failed' => 0]]);
    }

    $baseUrl = rtrim((string) ($_ENV['IMAGE_PUBLIC_BASE_URL'] ?? ''), '/');
    if ($baseUrl === '') {
        $appUrl = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');
        if ($appUrl !== '') {
            $baseUrl = $appUrl . '/ay-normalized';
        }
    }
    if ($baseUrl === '') {
        json_out(422, ['ok' => false, 'error' => 'Image public base URL is not configured']);
    }

    $http = new HttpClient(
        (int) ($_ENV['PS_HTTP_TIMEOUT'] ?? 15),
        (int) ($_ENV['PS_HTTP_CONNECT_TIMEOUT'] ?? 8),
    );
    $normalizer = new ImageNormalizer(
        $http,
        __DIR__ . '/ay-normalized',
        $baseUrl,
        1125,
        1500,
        (int) ($_ENV['IMAGE_JPEG_QUALITY'] ?? 92)
    );

    $ok = 0;
    $failed = 0;
    foreach ($failedImages as $img) {
        $imgId = (int) ($img['id'] ?? 0);
        $url = trim((string) ($img['source_url'] ?? ''));
        if ($imgId <= 0 || $url === '') {
            continue;
        }
        try {
            $result = $normalizer->normalizeSingleUrl($url);
            if ($result !== null) {
                [$localPath, $publicUrl, $w, $h, $bytes] = $result;
                $repo->markImageOk($imgId, $localPath, $publicUrl, (int) $w, (int) $h, (int) $bytes);
                $ok++;
            } else {
                $repo->markImageError($imgId, 'Normalization retry failed');
                $failed++;
            }
        } catch (\Throwable $e) {
            $repo->markImageError($imgId, 'Normalization retry failed: ' . mb_substr($e->getMessage(), 0, 180));
            $failed++;
        }
    }

    json_out(200, ['ok' => true, 'data' => [
        'retried' => count($failedImages),
        'ok' => $ok,
        'failed' => $failed,
    ]]);
}

// ----------------------------------------------------------------
// SETTINGS
// ----------------------------------------------------------------
if ($action === 'settings') {
    $rows = Database::fetchAll('SELECT * FROM settings ORDER BY group_name, `key`');
    // Mask passwords
    foreach ($rows as &$r) {
        if ($r['type'] === 'password' && $r['value']) $r['value'] = '••••••••';
    }
    json_out(200, ['ok' => true, 'data' => $rows]);
}

if ($action === 'category_mappings') {
    try {
        $http = new HttpClient(
            (int) ($_ENV['PS_HTTP_TIMEOUT'] ?? 15),
            (int) ($_ENV['PS_HTTP_CONNECT_TIMEOUT'] ?? 8),
        );
        $ps = new PrestaShopClient($http);
        $categories = $ps->getAllCategories();
        $productsByCategory = Database::fetchAll(
            "SELECT category_ps_id, COUNT(*) AS product_count FROM products
             WHERE category_ps_id IS NOT NULL
             GROUP BY category_ps_id"
        );
        $counts = [];
        foreach ($productsByCategory as $row) {
            $counts[(string) $row['category_ps_id']] = (int) $row['product_count'];
        }

        $mapJson = (string) (Database::fetchValue("SELECT value FROM settings WHERE `key`='ay_category_map'") ?? '{}');
        $map = json_decode($mapJson, true);
        if (!is_array($map)) {
            $map = [];
        }

        $rows = array_map(static function (array $category) use ($counts, $map): array {
            return [
                'ps_category_id' => (int) ($category['id'] ?? 0),
                'ps_category_name' => extract_lang_value($category['name'] ?? ''),
                'product_count' => $counts[(string) ($category['id'] ?? '')] ?? 0,
                'ay_category_id' => $map[(string) ($category['id'] ?? '')]['id'] ?? null,
                'ay_category_path' => $map[(string) ($category['id'] ?? '')]['path'] ?? null,
            ];
        }, $categories);

        json_out(200, ['ok' => true, 'data' => $rows]);
    } catch (\Throwable $e) {
        json_out(500, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

if ($action === 'category_products') {
    $psCategoryId = (int) ($input['ps_category_id'] ?? 0);
    if ($psCategoryId <= 0) {
        json_out(400, ['ok' => false, 'error' => 'ps_category_id required']);
    }

    $rows = Database::fetchAll(
        "SELECT p.ps_id, p.name, p.reference, p.sync_status, p.description_short, p.description, p.ay_category_id,
                (SELECT COALESCE(NULLIF(i.public_url, ''), NULLIF(i.source_url, ''))
                 FROM product_images i
                 WHERE i.product_id = p.id
                 ORDER BY (i.status = 'ok') DESC, i.position ASC, i.id ASC
                 LIMIT 1) AS image_thumb_url
         FROM products p
         WHERE p.category_ps_id = ?
         ORDER BY p.name ASC, p.ps_id ASC
         LIMIT 200",
        [$psCategoryId]
    );

    json_out(200, ['ok' => true, 'data' => [
        'ps_category_id' => $psCategoryId,
        'total' => count($rows),
        'rows' => $rows,
    ]]);
}

if ($action === 'product_assign_ay_category') {
    require_csrf($input);
    $psId = (int) ($input['ps_id'] ?? 0);
    $ayCategoryId = (int) ($input['ay_category_id'] ?? 0);
    if ($psId <= 0) {
        json_out(400, ['ok' => false, 'error' => 'ps_id required']);
    }
    if ($ayCategoryId <= 0) {
        json_out(400, ['ok' => false, 'error' => 'ay_category_id must be > 0']);
    }
    Database::execute(
        "UPDATE products SET ay_category_id = ?, updated_at = NOW() WHERE ps_id = ?",
        [$ayCategoryId, $psId]
    );
    json_out(200, ['ok' => true, 'data' => ['ps_id' => $psId, 'ay_category_id' => $ayCategoryId]]);
}

if ($action === 'category_products_assign_ay_category') {
    require_csrf($input);
    $psCategoryId = (int) ($input['ps_category_id'] ?? 0);
    $ayCategoryId = (int) ($input['ay_category_id'] ?? 0);
    if ($psCategoryId <= 0) {
        json_out(400, ['ok' => false, 'error' => 'ps_category_id required']);
    }
    if ($ayCategoryId <= 0) {
        json_out(400, ['ok' => false, 'error' => 'ay_category_id must be > 0']);
    }

    Database::execute(
        "UPDATE products
         SET ay_category_id = ?, updated_at = NOW()
         WHERE category_ps_id = ?",
        [$ayCategoryId, $psCategoryId]
    );

    $updated = (int) Database::fetchValue(
        "SELECT COUNT(*) FROM products WHERE category_ps_id = ? AND ay_category_id = ?",
        [$psCategoryId, $ayCategoryId]
    );

    json_out(200, ['ok' => true, 'data' => [
        'ps_category_id' => $psCategoryId,
        'ay_category_id' => $ayCategoryId,
        'updated' => $updated,
    ]]);
}

if ($action === 'category_products_suggest_mappings') {
    $psCategoryId = (int) ($input['ps_category_id'] ?? 0);
    $genderFilter = strtolower(trim((string) ($input['gender_filter'] ?? '')));
    if ($psCategoryId <= 0) {
        json_out(400, ['ok' => false, 'error' => 'ps_category_id required']);
    }

    $products = Database::fetchAll(
        "SELECT ps_id, name, description_short, description
         FROM products
         WHERE category_ps_id = ?
         ORDER BY ps_id ASC
         LIMIT 300",
        [$psCategoryId]
    );
    if ($products === []) {
        json_out(200, ['ok' => true, 'data' => ['rows' => []]]);
    }

    $http = new HttpClient(
        (int) ($_ENV['AY_HTTP_TIMEOUT'] ?? 15),
        (int) ($_ENV['AY_HTTP_CONNECT_TIMEOUT'] ?? 8),
    );
    $ay = new AboutYouClient($http);

    $cache = [];
    $suggestions = [];
    $policy = new AyDocsPolicy();
    foreach ($products as $product) {
        $name = trim((string) ($product['name'] ?? ''));
        $desc = trim((string) ($product['description_short'] ?? $product['description'] ?? ''));
        $gender = infer_product_gender($name . ' ' . $desc);
        if ($gender === '' && in_array($genderFilter, ['men', 'women', 'kids'], true)) {
            $gender = $genderFilter;
        }
        $type = infer_product_type($name . ' ' . $desc);
        $query = $type !== '' ? $type : $name;
        $query = trim($query);
        if ($query === '') {
            $suggestions[] = [
                'ps_id' => (int) ($product['ps_id'] ?? 0),
                'suggested' => null,
                'reason' => 'Missing product text for suggestion',
                'confidence' => 0.0,
            ];
            continue;
        }

        $cacheKey = $gender . '|' . strtolower($query);
        if (!isset($cache[$cacheKey])) {
            try {
                $result = $ay->searchCategories($query, 1, 60);
                $items = (array) ($result['items'] ?? []);
            } catch (\Throwable) {
                $items = [];
            }
            $items = filter_categories_by_gender($items, $gender);
            $cache[$cacheKey] = $items;
        }
        $items = $cache[$cacheKey];
        $best = $items[0] ?? null;
        $confidence = 0.0;
        if (is_array($best)) {
            $path = strtolower(trim((string) ($best['path'] ?? $best['name'] ?? '')));
            $confidence = 0.45;
            if ($type !== '' && str_contains($path, strtolower($type))) {
                $confidence += 0.35;
            }
            if ($gender !== '' && preg_match('/(^|[|\/\s_-])' . preg_quote($gender, '/') . '([|\/\s_-]|$)/i', $path)) {
                $confidence += 0.2;
            }
        }

        $suggested = is_array($best) ? [
            'id' => (int) ($best['id'] ?? 0),
            'path' => (string) ($best['path'] ?? $best['name'] ?? ''),
        ] : null;
        $riskLevel = $confidence >= 0.75 ? 'low' : ($confidence >= 0.5 ? 'medium' : 'high');
        $warnings = [];
        if ($suggested !== null && $type !== '') {
            $path = strtolower((string) ($suggested['path'] ?? ''));
            if (!str_contains($path, strtolower($type))) {
                $warnings[] = 'Suggested path does not strongly match inferred product type';
            }
        }
        if ($gender !== '' && $suggested !== null) {
            $path = strtolower((string) ($suggested['path'] ?? ''));
            if (!preg_match('/(^|[|\/\s_-])' . preg_quote($gender, '/') . '([|\/\s_-]|$)/i', $path)) {
                $warnings[] = 'Gender mismatch between product text and suggested category path';
            }
        }
        if ($suggested !== null) {
            $recommendedStockInterval = $policy->minIntervalMsForPath('/products/stocks', 650);
            $configuredInterval = (int) ($_ENV['AY_MIN_INTERVAL_MS'] ?? 650);
            if ($configuredInterval < $recommendedStockInterval) {
                $warnings[] = 'AY_MIN_INTERVAL_MS below docs-policy recommendation';
            }
        }

        $suggestions[] = [
            'ps_id' => (int) ($product['ps_id'] ?? 0),
            'suggested' => $suggested,
            'reason' => trim(implode(' · ', array_filter([
                $gender !== '' ? 'gender=' . $gender : 'gender=unknown',
                $type !== '' ? 'type=' . $type : 'type=generic',
                'query=' . $query,
            ]))),
            'confidence' => round($confidence, 2),
            'risk_level' => $riskLevel,
            'policy_warnings' => $warnings,
        ];
    }

    json_out(200, ['ok' => true, 'data' => ['rows' => $suggestions]]);
}

if ($action === 'category_mapping_validate') {
    $psCategoryId = (int) ($input['ps_category_id'] ?? 0);
    $ayCategoryId = (int) ($input['ay_category_id'] ?? 0);
    if ($psCategoryId <= 0 || $ayCategoryId <= 0) {
        json_out(400, ['ok' => false, 'error' => 'ps_category_id and ay_category_id are required']);
    }

    try {
        $http = new HttpClient(
            (int) ($_ENV['AY_HTTP_TIMEOUT'] ?? 15),
            (int) ($_ENV['AY_HTTP_CONNECT_TIMEOUT'] ?? 8),
        );
        $ay = new AboutYouClient($http);
        $policy = new AyDocsPolicy();
        $metadata = $ay->getRequiredCategoryMetadata($ayCategoryId);
        $requiredGroups = (array) ($metadata['required_groups'] ?? []);
        $requiredTextFields = (array) ($metadata['required_text_fields'] ?? []);

        $products = Database::fetchAll(
            "SELECT ps_id, name, description_short, description
             FROM products
             WHERE category_ps_id = ?
             ORDER BY ps_id ASC
             LIMIT 200",
            [$psCategoryId]
        );
        $sample = array_slice($products, 0, 25);
        $missingDescriptionCount = 0;
        foreach ($sample as $row) {
            $text = trim((string) ($row['description_short'] ?? $row['description'] ?? ''));
            if ($text === '') {
                $missingDescriptionCount++;
            }
        }

        $warnings = [];
        $quickFixes = [];
        if ($requiredGroups === []) {
            $warnings[] = 'No required attribute groups detected for target AY category';
        }
        if ($requiredTextFields !== []) {
            $warnings[] = 'Target AY category requires text fields: ' . implode(', ', $requiredTextFields);
        }
        if ($missingDescriptionCount > 0) {
            $warnings[] = sprintf('%d/%d sampled products have empty description text', $missingDescriptionCount, max(1, count($sample)));
        }
        $defaultsCount = (int) Database::fetchValue(
            "SELECT COUNT(*) FROM ay_required_group_defaults WHERE ay_group_id > 0 AND (ay_category_id = ? OR ay_category_id = 0)",
            [$ayCategoryId]
        );
        $requiredGroupsCount = count($requiredGroups);
        $groupCompletenessScore = $requiredGroupsCount > 0
            ? (int) round(min(100, ($defaultsCount / $requiredGroupsCount) * 100))
            : 100;
        if ($requiredGroupsCount > 0 && $defaultsCount < $requiredGroupsCount) {
            $warnings[] = sprintf(
                'Required group defaults incomplete (%d/%d configured)',
                $defaultsCount,
                $requiredGroupsCount
            );
            $quickFixes[] = 'Add missing defaults in Required Group Defaults for AY category ' . $ayCategoryId;
            $quickFixes[] = 'Map missing attribute_required values in Attribute Mapping before bulk sync';
        }
        if ($missingDescriptionCount > 0) {
            $quickFixes[] = 'Populate export_description for products with empty descriptions';
        }
        $recommendedStockInterval = $policy->minIntervalMsForPath('/products/stocks', 650);
        $configuredInterval = (int) ($_ENV['AY_MIN_INTERVAL_MS'] ?? 650);
        if ($configuredInterval < $recommendedStockInterval) {
            $warnings[] = sprintf(
                'AY_MIN_INTERVAL_MS (%d) is below recommended policy (%d)',
                $configuredInterval,
                $recommendedStockInterval
            );
        }

        $riskScore = 0;
        $riskScore += $requiredGroups === [] ? 15 : 0;
        if ($requiredGroupsCount > 0 && $groupCompletenessScore < 100) {
            $riskScore += (int) round((100 - $groupCompletenessScore) * 0.35);
        }
        $riskScore += $missingDescriptionCount > 0 ? min(35, $missingDescriptionCount * 2) : 0;
        $riskScore += $configuredInterval < $recommendedStockInterval ? 20 : 0;
        $riskLevel = $riskScore >= 45 ? 'high' : ($riskScore >= 20 ? 'medium' : 'low');

        json_out(200, ['ok' => true, 'data' => [
            'ps_category_id' => $psCategoryId,
            'ay_category_id' => $ayCategoryId,
            'required_groups_count' => $requiredGroupsCount,
            'required_text_fields' => $requiredTextFields,
            'group_defaults_count' => $defaultsCount,
            'group_completeness_score' => $groupCompletenessScore,
            'sample_products' => count($sample),
            'missing_description_count' => $missingDescriptionCount,
            'risk_score' => $riskScore,
            'risk_level' => $riskLevel,
            'warnings' => $warnings,
            'quick_fixes' => array_values(array_unique($quickFixes)),
        ]]);
    } catch (\Throwable $e) {
        json_out(500, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

if ($action === 'ay_categories_search') {
    try {
        $http = new HttpClient(
            (int) ($_ENV['AY_HTTP_TIMEOUT'] ?? 15),
            (int) ($_ENV['AY_HTTP_CONNECT_TIMEOUT'] ?? 8),
        );
        $ay = new AboutYouClient($http);
        $query = trim((string) ($input['query'] ?? ''));
        $page = max(1, (int) ($input['page'] ?? 1));
        json_out(200, ['ok' => true, 'data' => $ay->searchCategories($query, $page)]);
    } catch (\Throwable $e) {
        json_out(500, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

if ($action === 'attribute_mappings') {
    try {
        $http = new HttpClient(
            (int) ($_ENV['PS_HTTP_TIMEOUT'] ?? 15),
            (int) ($_ENV['PS_HTTP_CONNECT_TIMEOUT'] ?? 8),
        );
        $ps = new PrestaShopClient($http);
        $values = array_values(array_filter(
            $ps->getAllAttributeValues(),
            static fn (array $row): bool => in_array($row['map_type'], ['color', 'size', 'second_size'], true)
        ));

        $counts = [];
        foreach (Database::fetchAll(
            "SELECT map_type, ps_label, COUNT(*) AS cnt FROM attribute_maps GROUP BY map_type, ps_label"
        ) as $row) {
            $counts[strtolower((string) $row['map_type']) . '|' . strtolower((string) $row['ps_label'])] = (int) $row['cnt'];
        }

        $existing = [];
        foreach (Database::fetchAll("SELECT map_type, ps_label, ay_group_id, ay_group_name, ay_id FROM attribute_maps") as $row) {
            $existing[strtolower((string) $row['map_type']) . '|' . strtolower((string) $row['ps_label'])] = (int) $row['ay_id'];
        }

        $rows = array_map(static function (array $row) use ($existing, $counts): array {
            $key = strtolower((string) $row['map_type']) . '|' . strtolower((string) $row['ps_label']);
            return $row + [
                'ay_id' => $existing[$key] ?? null,
                'usage_count' => $counts[$key] ?? 0,
            ];
        }, $values);

        json_out(200, ['ok' => true, 'data' => $rows]);
    } catch (\Throwable $e) {
        json_out(500, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

if ($action === 'ay_attribute_options') {
    try {
        $http = new HttpClient(
            (int) ($_ENV['AY_HTTP_TIMEOUT'] ?? 15),
            (int) ($_ENV['AY_HTTP_CONNECT_TIMEOUT'] ?? 8),
        );
        $ay = new AboutYouClient($http);
        $type = strtolower(trim((string) ($input['type'] ?? '')));
        $categoryId = (int) ($input['category_id'] ?? Database::fetchValue("SELECT value FROM settings WHERE `key`='ay_category_id'"));
        $query = trim((string) ($input['query'] ?? ''));
        if ($categoryId <= 0) {
            json_out(400, ['ok' => false, 'error' => 'Set ay_category_id or pass category_id first']);
        }
        if (!in_array($type, ['color', 'size', 'second_size'], true)) {
            json_out(400, ['ok' => false, 'error' => 'type must be color, size, or second_size']);
        }
        json_out(200, ['ok' => true, 'data' => $ay->searchAttributeOptions($categoryId, $type, $query)]);
    } catch (\Throwable $e) {
        json_out(500, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

if ($action === 'material_mappings') {
    $isTextile = filter_var($input['is_textile'] ?? true, FILTER_VALIDATE_BOOLEAN);
    $rows = Database::fetchAll(
        "SELECT id, ps_label, ay_material_id, ay_material_label, is_textile, updated_at
         FROM material_component_maps WHERE is_textile = ? ORDER BY ps_label",
        [$isTextile ? 1 : 0]
    );
    $clusters = Database::fetchAll(
        "SELECT id, ps_label, ay_cluster_id, ay_cluster_label, updated_at
         FROM material_cluster_maps ORDER BY ps_label"
    );
    json_out(200, ['ok' => true, 'data' => [
        'components' => $rows,
        'clusters' => $clusters,
    ]]);
}

if ($action === 'material_mappings_save') {
    require_csrf($input);
    $components = $input['components'] ?? [];
    $clusters = $input['clusters'] ?? [];
    if (!is_array($components) && !is_array($clusters)) {
        json_out(400, ['ok' => false, 'error' => 'Provide components or clusters array']);
    }

    Database::beginTransaction();
    try {
        $savedComponents = 0;
        foreach ((array) $components as $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = trim((string) ($item['ps_label'] ?? ''));
            $ayId = (int) ($item['ay_material_id'] ?? 0);
            $ayLabel = trim((string) ($item['ay_material_label'] ?? ''));
            $isTextile = filter_var($item['is_textile'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            if ($label === '' || $ayId <= 0) {
                continue;
            }
            Database::execute(
                "INSERT INTO material_component_maps (ps_label, ay_material_id, ay_material_label, is_textile)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE ay_material_id=VALUES(ay_material_id),
                                        ay_material_label=VALUES(ay_material_label)",
                [$label, $ayId, $ayLabel !== '' ? $ayLabel : null, $isTextile]
            );
            $savedComponents++;
        }

        $savedClusters = 0;
        foreach ((array) $clusters as $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = trim((string) ($item['ps_label'] ?? ''));
            $ayId = (int) ($item['ay_cluster_id'] ?? 0);
            $ayLabel = trim((string) ($item['ay_cluster_label'] ?? ''));
            if ($label === '' || $ayId <= 0) {
                continue;
            }
            Database::execute(
                "INSERT INTO material_cluster_maps (ps_label, ay_cluster_id, ay_cluster_label)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE ay_cluster_id=VALUES(ay_cluster_id),
                                        ay_cluster_label=VALUES(ay_cluster_label)",
                [$label, $ayId, $ayLabel !== '' ? $ayLabel : null]
            );
            $savedClusters++;
        }
        Database::commit();
    } catch (\Throwable $e) {
        Database::rollback();
        json_out(500, ['ok' => false, 'error' => $e->getMessage()]);
    }

    json_out(200, ['ok' => true, 'data' => [
        'components_saved' => $savedComponents,
        'clusters_saved' => $savedClusters,
    ]]);
}

if ($action === 'required_group_defaults') {
    $categoryId = (int) ($input['category_id'] ?? 0);
    $params = [];
    $where = '';
    if ($categoryId > 0) {
        $where = 'WHERE ay_category_id = ? OR ay_category_id = 0';
        $params[] = $categoryId;
    }
    $rows = Database::fetchAll(
        "SELECT id, ay_category_id, ay_group_id, ay_group_name, default_ay_id, default_label, updated_at
         FROM ay_required_group_defaults {$where} ORDER BY ay_category_id DESC, ay_group_id",
        $params
    );
    json_out(200, ['ok' => true, 'data' => $rows]);
}

if ($action === 'required_group_defaults_save') {
    require_csrf($input);
    $items = $input['defaults'] ?? null;
    if (!is_array($items)) {
        json_out(400, ['ok' => false, 'error' => 'Provide defaults array']);
    }

    Database::beginTransaction();
    try {
        $saved = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $categoryId = (int) ($item['ay_category_id'] ?? 0);
            $groupId = (int) ($item['ay_group_id'] ?? 0);
            $defaultId = (int) ($item['default_ay_id'] ?? 0);
            $groupName = trim((string) ($item['ay_group_name'] ?? ''));
            $defaultLabel = trim((string) ($item['default_label'] ?? ''));
            if ($groupId <= 0 || $defaultId <= 0) {
                continue;
            }
            Database::execute(
                "INSERT INTO ay_required_group_defaults (ay_category_id, ay_group_id, ay_group_name, default_ay_id, default_label)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE ay_group_name=VALUES(ay_group_name),
                                        default_ay_id=VALUES(default_ay_id),
                                        default_label=VALUES(default_label)",
                [
                    $categoryId,
                    $groupId,
                    $groupName !== '' ? $groupName : null,
                    $defaultId,
                    $defaultLabel !== '' ? $defaultLabel : null,
                ]
            );
            $saved++;
        }
        Database::commit();
    } catch (\Throwable $e) {
        Database::rollback();
        json_out(500, ['ok' => false, 'error' => $e->getMessage()]);
    }

    json_out(200, ['ok' => true, 'data' => ['saved' => $saved]]);
}

if ($action === 'product_material_composition_save') {
    require_csrf($input);
    $psId = (int) ($input['product_id'] ?? 0);
    if ($psId <= 0) {
        json_out(400, ['ok' => false, 'error' => 'product_id required']);
    }
    $repo = new ProductRepository();
    $product = $repo->findByPsId($psId);
    if (!$product) {
        json_out(404, ['ok' => false, 'error' => 'Product not found']);
    }
    $productId = (int) $product['id'];
    $isTextile = filter_var($input['is_textile'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    $rows = $input['rows'] ?? [];
    if (!is_array($rows)) {
        json_out(400, ['ok' => false, 'error' => 'rows must be an array']);
    }

    Database::beginTransaction();
    try {
        Database::execute(
            'DELETE FROM product_material_composition WHERE product_id = ? AND is_textile = ?',
            [$productId, $isTextile]
        );
        $saved = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $clusterId = max(1, (int) ($row['cluster_id'] ?? 1));
            $materialId = (int) ($row['ay_material_id'] ?? 0);
            $fraction = (int) ($row['fraction'] ?? 0);
            if ($materialId <= 0 || $fraction <= 0 || $fraction > 100) {
                continue;
            }
            Database::execute(
                "INSERT INTO product_material_composition
                    (product_id, is_textile, cluster_id, cluster_label, ay_material_id, material_label, fraction)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [
                    $productId,
                    $isTextile,
                    $clusterId,
                    trim((string) ($row['cluster_label'] ?? '')) ?: null,
                    $materialId,
                    trim((string) ($row['material_label'] ?? '')) ?: null,
                    $fraction,
                ]
            );
            $saved++;
        }
        Database::commit();
    } catch (\Throwable $e) {
        Database::rollback();
        json_out(500, ['ok' => false, 'error' => $e->getMessage()]);
    }

    json_out(200, ['ok' => true, 'data' => ['saved' => $saved]]);
}

if ($action === 'preflight_check') {
    require_csrf($input);
    $psIds = array_values(array_unique(array_filter(
        array_map('intval', (array) ($input['ps_product_ids'] ?? [])),
        static fn (int $id): bool => $id > 0
    )));
    if ($psIds === []) {
        json_out(400, ['ok' => false, 'error' => 'Provide at least one ps_product_id']);
    }
    if (count($psIds) > 25) {
        json_out(400, ['ok' => false, 'error' => 'Maximum 25 products per preflight check']);
    }

    $http = new HttpClient(
        (int) ($_ENV['PS_HTTP_TIMEOUT'] ?? 15),
        (int) ($_ENV['PS_HTTP_CONNECT_TIMEOUT'] ?? 8),
    );
    $ps = new PrestaShopClient($http);
    $ay = new AboutYouClient($http);
    $mapper = new \SyncBridge\Integration\AboutYouMapper();
    $repo = new ProductRepository();

    $results = [];
    foreach ($psIds as $psId) {
        $result = ['ps_id' => $psId, 'ok' => false];
        try {
            $psProduct = $ps->getProduct($psId);
            if (!$psProduct) {
                throw new \RuntimeException('PrestaShop product not found');
            }
            $combinations = $ps->getCombinations($psId);
            $imageUrls = $ps->getProductImageUrls($psId, $psProduct);
            $local = $repo->findByPsId($psId);
            if ($local !== null) {
                $psProduct = array_merge($psProduct, array_filter([
                    'export_title' => $local['export_title'] ?? null,
                    'export_description' => $local['export_description'] ?? null,
                    'export_material_composition' => $local['export_material_composition'] ?? null,
                    'ay_category_id' => $local['ay_category_id'] ?? null,
                    'ay_brand_id' => $local['ay_brand_id'] ?? null,
                    'id' => (int) ($local['id'] ?? $psProduct['id'] ?? 0),
                ], static fn (mixed $value): bool => $value !== null && $value !== ''));
            }
            $psCombinations = apply_variant_ean_overrides($local ? (int) ($local['id'] ?? 0) : 0, $psCombinations);
            $categoryId = (int) ($psProduct['ay_category_id'] ?? $psProduct['id_category_default'] ?? 0);
            if ($categoryId > 0) {
                try {
                    $metadata = $ay->getRequiredCategoryMetadata($categoryId);
                    if (!empty($metadata['required_groups'])) {
                        $psProduct['ay_required_attribute_groups'] = $metadata['required_groups'];
                    }
                    if (!empty($metadata['required_text_fields'])) {
                        $psProduct['ay_required_text_fields'] = $metadata['required_text_fields'];
                    }
                } catch (\Throwable $metadataError) {
                    $result['metadata_warning'] = $metadataError->getMessage();
                }
            }
            $payload = $mapper->mapProductToAy($psProduct, $psCombinations, $imageUrls);
            $result['ok'] = true;
            $result['style_key'] = $payload['style_key'] ?? null;
            $result['variant_count'] = count($payload['variants'] ?? []);
            $result['warnings'] = $payload['warnings'] ?? [];
        } catch (\SyncBridge\Support\ValidationException $e) {
            $result['ok'] = false;
            $result['errors'] = $e->errors();
            $result['reason_code'] = 'local_preflight';
        } catch (\Throwable $e) {
            $result['ok'] = false;
            $result['error'] = $e->getMessage();
            $result['reason_code'] = 'unknown';
        }
        $results[] = $result;
    }

    $summary = [
        'checked' => count($results),
        'passed' => count(array_filter($results, static fn (array $r): bool => !empty($r['ok']))),
        'failed' => count(array_filter($results, static fn (array $r): bool => empty($r['ok']))),
    ];

    json_out(200, ['ok' => true, 'data' => ['summary' => $summary, 'results' => $results]]);
}

if ($action === 'attribute_mappings_save') {
    require_csrf($input);
    $mappings = $input['mappings'] ?? null;
    if (!is_array($mappings)) {
        json_out(400, ['ok' => false, 'error' => 'Invalid mappings payload']);
    }

    Database::beginTransaction();
    try {
        foreach ($mappings as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = strtolower(trim((string) ($item['map_type'] ?? '')));
            $psLabel = trim((string) ($item['ps_label'] ?? ''));
            $ayId = (int) ($item['ay_id'] ?? 0);
            $ayGroupId = (int) ($item['ay_group_id'] ?? 0);
            $ayGroupName = trim((string) ($item['ay_group_name'] ?? ''));
            if (!in_array($type, ['color', 'size', 'second_size', 'attribute', 'attribute_required'], true)
                || $psLabel === '' || $ayId <= 0) {
                continue;
            }

            Database::execute(
                "INSERT INTO attribute_maps (map_type, ps_label, ay_group_id, ay_group_name, ay_id)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE ay_group_name=VALUES(ay_group_name), ay_id=VALUES(ay_id)",
                [$type, $psLabel, $ayGroupId > 0 ? $ayGroupId : 0, $ayGroupName !== '' ? $ayGroupName : null, $ayId]
            );
        }
        Database::commit();
    } catch (\Throwable $e) {
        Database::rollback();
        json_out(500, ['ok' => false, 'error' => $e->getMessage()]);
    }

    json_out(200, ['ok' => true, 'data' => ['saved' => count($mappings)]]);
}

if ($action === 'scheduler_get') {
    $value = (string) (Database::fetchValue("SELECT value FROM settings WHERE `key`='sync_schedules'") ?? '{}');
    $parsed = json_decode($value, true);
    if (!is_array($parsed)) {
        $parsed = [];
    }
    json_out(200, ['ok' => true, 'data' => $parsed]);
}

if ($action === 'scheduler_save') {
    require_csrf($input);
    $schedules = $input['schedules'] ?? null;
    if (!is_array($schedules)) {
        json_out(400, ['ok' => false, 'error' => 'Invalid schedules payload']);
    }

    $normalized = [];
    $allowedCommands = ['products', 'products:inc', 'stock', 'orders', 'order-status'];
    $allowedCadences = ['hourly', 'daily', 'weekly', 'monthly'];
    foreach ($schedules as $command => $schedule) {
        if (!in_array((string) $command, $allowedCommands, true) || !is_array($schedule)) {
            continue;
        }
        $cadence = strtolower((string) ($schedule['cadence'] ?? 'hourly'));
        if (!in_array($cadence, $allowedCadences, true)) {
            $cadence = 'hourly';
        }
        $normalized[$command] = [
            'enabled' => !empty($schedule['enabled']),
            'cadence' => $cadence,
            'minute' => max(0, min(59, (int) ($schedule['minute'] ?? 0))),
            'hour' => max(0, min(23, (int) ($schedule['hour'] ?? 0))),
            'weekday' => max(0, min(6, (int) ($schedule['weekday'] ?? 1))),
            'monthday' => max(1, min(28, (int) ($schedule['monthday'] ?? 1))),
        ];
    }

    $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    Database::execute(
        "INSERT INTO settings (`key`, `value`, `type`, label, group_name)
         VALUES ('sync_schedules', ?, 'json', 'Sync Schedules JSON', 'sync')
         ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), updated_at=NOW()",
        [$json]
    );
    updateEnvFile('sync_schedules', $json);

    json_out(200, ['ok' => true, 'data' => ['saved' => count($normalized)]]);
}

if ($action === 'category_mappings_save') {
    require_csrf($input);
    $mappings = $input['mappings'] ?? null;
    if (!is_array($mappings)) {
        json_out(400, ['ok' => false, 'error' => 'Invalid mappings payload']);
    }

    $normalized = [];
    foreach ($mappings as $psCategoryId => $mapping) {
        $psCategoryId = (string) ((int) $psCategoryId);
        if ($psCategoryId === '0' || !is_array($mapping)) {
            continue;
        }
        $ayId = (int) ($mapping['id'] ?? 0);
        $path = trim((string) ($mapping['path'] ?? ''));
        if ($ayId <= 0) {
            continue;
        }
        $normalized[$psCategoryId] = ['id' => $ayId, 'path' => $path];
    }

    $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    Database::execute(
        "INSERT INTO settings (`key`, `value`, `type`, label, group_name)
         VALUES ('ay_category_map', ?, 'json', 'Category Map JSON', 'aboutyou')
         ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), updated_at=NOW()",
        [$json]
    );
    updateEnvFile('ay_category_map', $json);

    json_out(200, ['ok' => true, 'data' => ['saved' => count($normalized)]]);
}

if ($action === 'settings_save') {
    require_csrf($input);
    $settings = $input['settings'] ?? [];
    if (!is_array($settings)) json_out(400, ['ok' => false, 'error' => 'Invalid settings']);

    Database::beginTransaction();
    try {
        foreach ($settings as $key => $value) {
            // Skip masked password fields
            if ($value === '••••••••') continue;
            Database::execute(
                "UPDATE settings SET `value`=? WHERE `key`=?",
                [(string)$value, (string)$key]
            );
            // Also write to .env file for runtime use
            updateEnvFile((string)$key, (string)$value);
        }
        Database::commit();
    } catch (\Throwable $e) {
        Database::rollback();
        json_out(500, ['ok' => false, 'error' => $e->getMessage()]);
    }
    json_out(200, ['ok' => true, 'data' => ['saved' => count($settings)]]);
}

if ($action === 'toggle') {
    require_csrf($input);
    $key = trim((string) ($input['key'] ?? ''));
    $value = $input['value'] ?? '';
    if ($key === '') {
        json_out(400, ['ok' => false, 'error' => 'key required']);
    }

    Database::execute("UPDATE settings SET `value`=? WHERE `key`=?", [(string) $value, $key]);
    updateEnvFile((string) $key, (string) $value);
    json_out(200, ['ok' => true, 'data' => ['key' => $key, 'value' => $value]]);
}

// ----------------------------------------------------------------
// SYNC (runs in background or inline for small jobs)
// ----------------------------------------------------------------
if ($action === 'sync') {
    require_csrf($input);

    $command  = (string)($input['command'] ?? '');
    $allowed  = ['products', 'products:inc', 'stock', 'orders', 'order-status', 'all', 'retry', 'status'];
    if (!in_array($command, $allowed, true)) {
        json_out(400, ['ok' => false, 'error' => "Unknown command: {$command}"]);
    }

    // Check lock
    $lockPath = resolveSyncLockPath();
    $lockDir = dirname($lockPath);
    if (!is_dir($lockDir)) {
        @mkdir($lockDir, 0775, true);
    }
    $lock     = @fopen($lockPath, 'c+');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        $current = (new SyncRunRepository())->getCurrent();
        json_out(409, [
            'ok'    => false,
            'error' => 'Another sync is already running',
            'data'  => ['current_run' => $current],
        ]);
    }

    $psIds    = array_slice(array_map('intval', (array)($input['ps_product_ids'] ?? [])), 0, 200);
    $orderIds = array_slice(array_map('intval', (array)($input['ps_order_ids'] ?? [])), 0, 50);

    // For long-running syncs, start a background process and return immediately
    $async = filter_var($input['async'] ?? true, FILTER_VALIDATE_BOOLEAN);
    if ($async && in_array($command, ['products', 'products:inc', 'all'])) {
        flock($lock, LOCK_UN);
        fclose($lock);

        $php    = escapeshellarg(PHP_BINARY);
        $script = escapeshellarg(__DIR__ . '/../bin/sync.php');
        $psIdStr = implode(',', $psIds);
        $commandArg = escapeshellarg($command);
        $extraArgs = $psIdStr ? ' --ps-ids=' . escapeshellarg($psIdStr) : '';
        $cmd = "sh -c \"{$php} {$script} {$commandArg}{$extraArgs} > /dev/null 2>&1 & echo \\$!\"";
        $output = [];
        @exec($cmd, $output);
        $pid = isset($output[0]) ? (int) trim((string) $output[0]) : 0;
        if ($pid > 0) {
            @file_put_contents(resolveSyncPidPath(), (string) $pid, LOCK_EX);
        }

        json_out(202, ['ok' => true, 'data' => ['message' => "Sync '{$command}' started in background"]]);
    }

    // Inline sync (stock, orders, order-status run fast enough)
    $runner = new SyncRunner();
    $result = $runner->run($command, [
        'ps_product_ids' => $psIds,
        'ps_order_ids' => $orderIds,
    ]);

    flock($lock, LOCK_UN);
    fclose($lock);

    json_out(200, ['ok' => (bool)($result['ok'] ?? true), 'data' => $result]);
}

if ($action === 'sync_stop') {
    require_csrf($input);
    $repo = new SyncRunRepository();
    $current = $repo->getCurrent();
    $requestedRunId = trim((string) ($input['run_id'] ?? ''));
    $requestedRunIds = array_values(array_unique(array_filter(
        array_map(static fn (mixed $id): string => trim((string) $id), (array) ($input['run_ids'] ?? [])),
        static fn (string $id): bool => $id !== ''
    )));
    if ($requestedRunId !== '' && !in_array($requestedRunId, $requestedRunIds, true)) {
        $requestedRunIds[] = $requestedRunId;
    }

    $targetRuns = [];
    if ($requestedRunIds !== []) {
        $placeholders = implode(',', array_fill(0, count($requestedRunIds), '?'));
        $rows = Database::fetchAll(
            "SELECT * FROM sync_runs WHERE run_id IN ({$placeholders})",
            $requestedRunIds
        );
        $byRunId = [];
        foreach ($rows as $row) {
            $byRunId[(string) ($row['run_id'] ?? '')] = $row;
        }
        foreach ($requestedRunIds as $runId) {
            if (!isset($byRunId[$runId])) {
                json_out(404, ['ok' => false, 'error' => "Run ID not found: {$runId}"]);
            }
            if (($byRunId[$runId]['status'] ?? '') !== 'running') {
                json_out(409, ['ok' => false, 'error' => "Selected run is not running: {$runId}"]);
            }
            $targetRuns[] = $byRunId[$runId];
        }
    } elseif ($current) {
        $targetRuns[] = $current;
    }
    $pidPath = resolveSyncPidPath();
    $pid = 0;
    if (is_file($pidPath)) {
        $pid = (int) trim((string) @file_get_contents($pidPath));
    }

    if ($pid <= 0 && $targetRuns === []) {
        json_out(200, ['ok' => true, 'data' => ['stopped' => false, 'message' => 'No running sync found']]);
    }

    $stopped = false;
    if ($pid > 0) {
        $stopped = tryStopProcess($pid);
    }

    $updatedRunIds = [];
    foreach ($targetRuns as $targetRun) {
        Database::execute(
            "UPDATE sync_runs
             SET status='failed', finished_at=NOW(), last_message=?
             WHERE run_id=? AND status='running'",
            [$stopped ? 'Sync stopped by operator' : 'Stop requested but process was not confirmed terminated', $targetRun['run_id']]
        );
        $repo->log((string) $targetRun['run_id'], 'warning', 'sync', 'Sync stop requested by operator', ['pid' => $pid, 'stopped' => $stopped]);
        $updatedRunIds[] = (string) ($targetRun['run_id'] ?? '');
    }

    if ($stopped && is_file($pidPath)) {
        @unlink($pidPath);
    }

    json_out(200, ['ok' => true, 'data' => [
        'stopped' => $stopped,
        'pid' => $pid > 0 ? $pid : null,
        'run_ids' => $updatedRunIds,
        'run_id' => $updatedRunIds[0] ?? null,
    ]]);
}

// ----------------------------------------------------------------
// HELPERS
// ----------------------------------------------------------------
function updateEnvFile(string $key, string $value): void
{
    $envPath = __DIR__ . '/../.env';
    if (!file_exists($envPath)) return;
    $content = file_get_contents($envPath);
    $envKey  = strtoupper($key);
    $newLine = "{$envKey}=" . (str_contains($value, ' ') ? "\"{$value}\"" : $value);
    $pattern = '/^' . preg_quote($envKey, '/') . '=.*/m';
    if (preg_match($pattern, $content)) {
        $content = preg_replace($pattern, $newLine, $content);
    } else {
        $content .= PHP_EOL . $newLine . PHP_EOL;
    }
    file_put_contents($envPath, $content, LOCK_EX);
}

function resolveSyncLockPath(): string
{
    $raw = (string) ($_ENV['SYNC_LOCK_FILE'] ?? (__DIR__ . '/../logs/sync-run.lock'));
    if (str_starts_with($raw, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $raw)) {
        return $raw;
    }
    return __DIR__ . '/../' . ltrim($raw, './');
}

function resolveSyncPidPath(): string
{
    $raw = (string) ($_ENV['SYNC_PID_FILE'] ?? 'logs/sync-run.pid');
    if (str_starts_with($raw, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $raw)) {
        return $raw;
    }
    return __DIR__ . '/../' . ltrim($raw, './');
}

function resolveLogPaths(): array
{
    $configured = (string) ($_ENV['LOG_PATH'] ?? '');
    $paths = [
        __DIR__ . '/../logs/sync.log',
        __DIR__ . '/logs/sync.log',
    ];
    if ($configured !== '') {
        if (str_starts_with($configured, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $configured)) {
            $paths[] = $configured;
        } else {
            $paths[] = __DIR__ . '/../' . ltrim($configured, './');
        }
    }
    return array_values(array_unique($paths));
}

function tryStopProcess(int $pid): bool
{
    if ($pid <= 0) {
        return false;
    }

    $alive = static function (int $targetPid): bool {
        if (function_exists('posix_kill')) {
            return @posix_kill($targetPid, 0);
        }
        @exec('kill -0 ' . (int) $targetPid, $out, $code);
        return $code === 0;
    };

    if (!$alive($pid)) {
        return true;
    }

    if (function_exists('posix_kill')) {
        @posix_kill($pid, SIGTERM);
    } else {
        @exec('kill -TERM ' . (int) $pid);
    }

    usleep(300000);
    if (!$alive($pid)) {
        return true;
    }

    if (function_exists('posix_kill')) {
        @posix_kill($pid, SIGKILL);
    } else {
        @exec('kill -KILL ' . (int) $pid);
    }
    usleep(200000);

    return !$alive($pid);
}

function extract_lang_value(mixed $value): string
{
    if (is_string($value)) {
        return $value;
    }
    if (!is_array($value)) {
        return '';
    }
    foreach ($value as $entry) {
        if (is_array($entry) && trim((string) ($entry['value'] ?? '')) !== '') {
            return (string) $entry['value'];
        }
    }
    return '';
}

function infer_product_gender(string $text): string
{
    $text = strtolower($text);
    if (preg_match('/\b(women|woman|female|lady|ladies|girl|girls)\b/i', $text)) {
        return 'women';
    }
    if (preg_match('/\b(men|man|male|boy|boys)\b/i', $text)) {
        return 'men';
    }
    if (preg_match('/\b(kid|kids|child|children|junior|youth|baby|infant)\b/i', $text)) {
        return 'kids';
    }
    return '';
}

function infer_product_type(string $text): string
{
    $text = strtolower($text);
    $map = [
        'hoodie' => ['hoodie', 'hooded'],
        'sweater' => ['sweater', 'pullover', 'knit'],
        'jacket' => ['jacket', 'coat', 'blazer', 'parka'],
        'pants' => ['pant', 'pants', 'trouser', 'jean', 'jogger', 'legging'],
        'vest' => ['vest', 'waistcoat'],
        'shirt' => ['shirt', 't-shirt', 'tee', 'top', 'blouse'],
        'cap' => ['cap', 'hat', 'beanie'],
        'gloves' => ['glove', 'mitt'],
    ];
    foreach ($map as $type => $tokens) {
        foreach ($tokens as $token) {
            if (str_contains($text, $token)) {
                return $type;
            }
        }
    }
    return '';
}

function filter_categories_by_gender(array $items, string $gender): array
{
    if (!in_array($gender, ['men', 'women', 'kids'], true)) {
        return $items;
    }
    return array_values(array_filter($items, static function (array $item) use ($gender): bool {
        $path = strtolower((string) ($item['path'] ?? $item['name'] ?? ''));
        return preg_match('/(^|[|\/\s_-])' . preg_quote($gender, '/') . '([|\/\s_-]|$)/i', $path) === 1;
    }));
}

function build_product_detail_payload(int $psId, bool $includeRemote = true): array
{
    $repo = new ProductRepository();
    $product = $repo->findByPsId($psId);
    if (!$product) {
        throw new RuntimeException('Product not found');
    }

    $variants = $repo->getVariants((int) $product['id']);
    $images = $repo->getImages((int) $product['id']);
    $logs = Database::fetchAll(
        "SELECT * FROM sync_logs WHERE message LIKE ? ORDER BY created_at DESC LIMIT 20",
        ['%PS#' . $psId . '%']
    );
    $errorHistory = Database::fetchAll(
        "SELECT id, run_id, phase, reason_code, error_message, error_details, created_at
         FROM product_sync_errors
         WHERE ps_id = ?
         ORDER BY created_at DESC
         LIMIT 50",
        [$psId]
    );

    $psProduct = null;
    $psCombinations = [];
    $psCategory = null;
    $psFetchError = null;
    $ayFetchError = null;
    $effectiveCategoryId = resolve_product_category_id($product, null);
    $effectiveBrandId = resolve_product_brand_id($product, null);
    $ayOptions = ['color' => [], 'size' => []];

    if ($includeRemote) {
        $cachedRemote = get_product_detail_remote_cache($psId);
        if (is_array($cachedRemote)) {
            $psProduct = is_array($cachedRemote['ps_product'] ?? null) ? $cachedRemote['ps_product'] : null;
            $psCombinations = is_array($cachedRemote['ps_combinations'] ?? null) ? $cachedRemote['ps_combinations'] : [];
            $psCategory = is_array($cachedRemote['ps_category'] ?? null) ? $cachedRemote['ps_category'] : null;
            $psFetchError = isset($cachedRemote['ps_fetch_error']) ? (string) $cachedRemote['ps_fetch_error'] : null;
            $ayFetchError = isset($cachedRemote['ay_fetch_error']) ? (string) $cachedRemote['ay_fetch_error'] : null;
            $effectiveCategoryId = (int) ($cachedRemote['effective_ay_category_id'] ?? $effectiveCategoryId);
            $effectiveBrandId = (int) ($cachedRemote['effective_ay_brand_id'] ?? $effectiveBrandId);
            $cachedOptions = $cachedRemote['ay_options'] ?? null;
            if (is_array($cachedOptions)) {
                $ayOptions = [
                    'color' => is_array($cachedOptions['color'] ?? null) ? $cachedOptions['color'] : [],
                    'size' => is_array($cachedOptions['size'] ?? null) ? $cachedOptions['size'] : [],
                ];
            }
        } else {
            $http = null;
            try {
                $http = new HttpClient(
                    (int) ($_ENV['PS_HTTP_TIMEOUT'] ?? 15),
                    (int) ($_ENV['PS_HTTP_CONNECT_TIMEOUT'] ?? 8),
                );
                $ps = new PrestaShopClient($http);
                $psProduct = $ps->getProduct($psId);
                if ($psProduct !== null) {
                    $psCombinations = $ps->getCombinations($psId);
                    $psCombinations = apply_variant_ean_overrides((int) ($product['id'] ?? 0), $psCombinations);
                    $categoryPsId = (int) ($psProduct['id_category_default'] ?? $product['category_ps_id'] ?? 0);
                    if ($categoryPsId > 0) {
                        $psCategory = $ps->getCategory($categoryPsId);
                    }
                }
            } catch (\Throwable $e) {
                $psFetchError = $e->getMessage();
            }

            $effectiveCategoryId = resolve_product_category_id($product, $psProduct);
            $effectiveBrandId = resolve_product_brand_id($product, $psProduct);
            if ($effectiveCategoryId > 0) {
                try {
                    $http ??= new HttpClient(
                        (int) ($_ENV['AY_HTTP_TIMEOUT'] ?? 15),
                        (int) ($_ENV['AY_HTTP_CONNECT_TIMEOUT'] ?? 8),
                    );
                    $ay = new AboutYouClient($http);
                    $ayOptions['color'] = $ay->searchAttributeOptions($effectiveCategoryId, 'color', '');
                    $ayOptions['size'] = $ay->searchAttributeOptions($effectiveCategoryId, 'size', '');
                } catch (\Throwable $e) {
                    $ayFetchError = $e->getMessage();
                }
            }

            set_product_detail_remote_cache($psId, [
                'ps_product' => $psProduct,
                'ps_combinations' => $psCombinations,
                'ps_category' => $psCategory,
                'ps_fetch_error' => $psFetchError,
                'ay_fetch_error' => $ayFetchError,
                'effective_ay_category_id' => $effectiveCategoryId,
                'effective_ay_brand_id' => $effectiveBrandId,
                'ay_options' => $ayOptions,
            ]);
        }
    }

    return [
        'product' => $product,
        'variants' => $variants,
        'images' => $images,
        'logs' => $logs,
        'error_history' => $errorHistory,
        'ps_product' => $psProduct,
        'ps_combinations' => $psCombinations,
        'ps_category' => $psCategory,
        'ps_fetch_error' => $psFetchError,
        'ay_fetch_error' => $ayFetchError,
        'effective_ay_category_id' => $effectiveCategoryId,
        'effective_ay_brand_id' => $effectiveBrandId,
        'attribute_rows' => build_product_attribute_rows($psCombinations),
        'ay_options' => $ayOptions,
    ];
}

function build_product_attribute_rows(array $combinations): array
{
    $rows = [];
    foreach ($combinations as $combo) {
        foreach (($combo['attributes'] ?? []) as $attribute) {
            $groupName = trim((string) ($attribute['group_name'] ?? ''));
            $label = trim((string) ($attribute['value_name'] ?? ''));
            if ($label === '') {
                continue;
            }
            $mapType = null;
            if (AttributeTypeGuesser::isColor($groupName)) {
                $mapType = 'color';
            } elseif (AttributeTypeGuesser::isSize($groupName)) {
                $mapType = 'size';
            } elseif (AttributeTypeGuesser::isSecondSize($groupName)) {
                $mapType = 'second_size';
            }
            if ($mapType === null) {
                continue;
            }

            $groupId = (int) ($attribute['group_id'] ?? 0);
            $key = $mapType . '|' . $groupId . '|' . strtolower($label);
            if (isset($rows[$key])) {
                $rows[$key]['combo_refs'][] = [
                    'id' => (int) ($combo['id'] ?? 0),
                    'reference' => (string) ($combo['reference'] ?? ''),
                ];
                continue;
            }

            $rows[$key] = [
                'map_type' => $mapType,
                'group_id' => $groupId,
                'group_name' => $groupName,
                'ps_label' => $label,
                'ay_id' => (int) (Database::fetchValue(
                    'SELECT ay_id FROM attribute_maps WHERE map_type = ? AND LOWER(ps_label) = LOWER(?) AND (ay_group_id = ? OR ay_group_id = 0) ORDER BY ay_group_id DESC LIMIT 1',
                    [$mapType, $label, $groupId]
                ) ?? 0),
                'combo_refs' => [[
                    'id' => (int) ($combo['id'] ?? 0),
                    'reference' => (string) ($combo['reference'] ?? ''),
                ]],
            ];
        }
    }

    return array_values($rows);
}

function resolve_product_category_id(array $product, ?array $psProduct): int
{
    if (!empty($product['ay_category_id'])) {
        return (int) $product['ay_category_id'];
    }

    $categoryMap = json_decode((string) ($_ENV['AY_CATEGORY_MAP'] ?? '{}'), true);
    $categoryId = (string) ($psProduct['id_category_default'] ?? $product['category_ps_id'] ?? '');
    if (is_array($categoryMap) && $categoryId !== '' && isset($categoryMap[$categoryId])) {
        $mapped = $categoryMap[$categoryId];
        return (int) (is_array($mapped) ? ($mapped['id'] ?? 0) : $mapped);
    }

    return (int) ($_ENV['AY_CATEGORY_ID'] ?? 0);
}

function resolve_product_brand_id(array $product, ?array $psProduct): int
{
    if (!empty($product['ay_brand_id'])) {
        return (int) $product['ay_brand_id'];
    }

    $brandMap = json_decode((string) ($_ENV['AY_BRAND_MAP'] ?? '{}'), true);
    $manufacturerId = (string) ($psProduct['id_manufacturer'] ?? '');
    if (is_array($brandMap) && $manufacturerId !== '' && isset($brandMap[$manufacturerId])) {
        $mapped = $brandMap[$manufacturerId];
        return (int) (is_array($mapped) ? ($mapped['id'] ?? 0) : $mapped);
    }

    return (int) ($_ENV['AY_BRAND_ID'] ?? 0);
}

function normalize_option_label(string $value): string
{
    $value = html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5);
    $value = strtolower($value);
    $value = str_replace(['_', '/', '\\', '-', '.', ','], ' ', $value);
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;

    $map = [
        'zwart' => 'black',
        'schwarz' => 'black',
        'wit' => 'white',
        'weiss' => 'white',
        'weiß' => 'white',
        'blauw' => 'blue',
        'blau' => 'blue',
        'groen' => 'green',
        'grun' => 'green',
        'grün' => 'green',
        'rood' => 'red',
        'rot' => 'red',
        'grijs' => 'grey',
        'grau' => 'grey',
        'beigee' => 'beige',
        'small' => 's',
        'medium' => 'm',
        'large' => 'l',
        'x large' => 'xl',
        'xx large' => 'xxl',
    ];

    return $map[$value] ?? $value;
}

function auto_match_attribute_id(string $psLabel, array $optionMap, string $type): int
{
    $normalized = normalize_option_label($psLabel);
    if (isset($optionMap[$normalized])) {
        return (int) $optionMap[$normalized];
    }

    if ($type === 'size') {
        $aliases = [
            'xsmall' => 'xs',
            'x small' => 'xs',
            'small' => 's',
            'medium' => 'm',
            'large' => 'l',
            'xlarge' => 'xl',
            'x large' => 'xl',
            'xxlarge' => 'xxl',
            'xx large' => 'xxl',
        ];
        $normalized = $aliases[$normalized] ?? $normalized;
        return (int) ($optionMap[$normalized] ?? 0);
    }

    return 0;
}

function apply_variant_ean_overrides(int $productId, array $combinations): array
{
    if ($productId <= 0 || $combinations === []) {
        return $combinations;
    }

    $rows = Database::fetchAll(
        'SELECT ps_combo_id, ean13 FROM product_variants WHERE product_id = ?',
        [$productId]
    );
    if ($rows === []) {
        return $combinations;
    }

    $eanByCombo = [];
    foreach ($rows as $row) {
        $comboId = (int) ($row['ps_combo_id'] ?? 0);
        if ($comboId <= 0) {
            continue;
        }
        $eanByCombo[$comboId] = trim((string) ($row['ean13'] ?? ''));
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

function push_local_order_to_prestashop(int $orderId): array
{
    $order = Database::fetchOne('SELECT * FROM orders WHERE id = ?', [$orderId]);
    if (!$order) {
        throw new RuntimeException('Order not found');
    }
    $items = Database::fetchAll('SELECT * FROM order_items WHERE order_id = ?', [$orderId]);
    if ($items === []) {
        throw new RuntimeException('Order has no items to push');
    }

    $http = new HttpClient(
        (int) ($_ENV['PS_HTTP_TIMEOUT'] ?? 20),
        (int) ($_ENV['PS_HTTP_CONNECT_TIMEOUT'] ?? 8),
    );
    $ps = new PrestaShopClient($http);

    $resolvedItems = [];
    foreach ($items as $item) {
        $resolved = resolve_local_order_item_for_ps($ps, $item);
        if ($resolved !== null) {
            $resolvedItems[] = $resolved;
        }
    }
    if ($resolvedItems === []) {
        throw new RuntimeException('Could not resolve any order items to PrestaShop products');
    }

    [$firstName, $lastName] = split_customer_name((string) ($order['customer_name'] ?? ''));
    $email = trim((string) ($order['customer_email'] ?? ''));
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $base = trim((string) ($order['ay_order_id'] ?? 'order-' . $orderId));
        $base = strtolower($base);
        $base = preg_replace('/[^a-z0-9._-]+/', '-', $base) ?? 'order-' . $orderId;
        $base = trim($base, '-_.');
        if ($base === '') {
            $base = 'order-' . $orderId;
        }
        $email = substr($base, 0, 48) . '@aboutyou.local';
    }
    $customerId = $ps->findOrCreateCustomer([
        'email' => $email,
        'first_name' => $firstName,
        'last_name' => $lastName,
    ]);
    if (!$customerId) {
        throw new RuntimeException('Failed to find/create PrestaShop customer (email=' . $email . ')');
    }

    $shippingAddress = parse_order_address_json(
        (string) ($order['shipping_address_json'] ?? ''),
        strtoupper((string) ($order['shipping_country_iso'] ?? 'DE')),
        $firstName,
        $lastName,
        (string) ($order['ay_order_id'] ?? 'Order')
    );
    $billingAddress = parse_order_address_json(
        (string) ($order['billing_address_json'] ?? ''),
        strtoupper((string) ($order['billing_country_iso'] ?? $order['shipping_country_iso'] ?? 'DE')),
        $firstName,
        $lastName,
        (string) ($order['ay_order_id'] ?? 'Order')
    );

    $addressId = $ps->findOrCreateAddress($customerId, $shippingAddress);
    if (!$addressId) {
        $addressId = $ps->findOrCreateAddress($customerId, $billingAddress);
    }
    if (!$addressId) {
        // Final fallback for strict/unsupported country cases.
        $fallback = $shippingAddress;
        $fallback['country_iso'] = 'DE';
        if (trim((string) ($fallback['address1'] ?? '')) === '') {
            $fallback['address1'] = 'Marketplace Street 1';
        }
        if (trim((string) ($fallback['postcode'] ?? '')) === '') {
            $fallback['postcode'] = '10115';
        }
        if (trim((string) ($fallback['city'] ?? '')) === '') {
            $fallback['city'] = 'Berlin';
        }
        $addressId = $ps->findOrCreateAddress($customerId, $fallback);
    }
    if (!$addressId) {
        throw new RuntimeException(
            'Failed to find/create PrestaShop address (shipping_country='
            . (string) ($shippingAddress['country_iso'] ?? '')
            . ', billing_country=' . (string) ($billingAddress['country_iso'] ?? '') . ')'
        );
    }

    $payload = [
        'id_customer' => $customerId,
        'id_address_delivery' => $addressId,
        'id_address_invoice' => $addressId,
        'items' => $resolvedItems,
        'total_shipping' => (float) ($order['total_shipping'] ?? 0),
        'total_paid' => (float) ($order['total_paid'] ?? 0),
        'external_reference' => (string) ($order['ay_order_id'] ?? $orderId),
    ];
    try {
        $psOrderId = (int) ($ps->createOrder($payload) ?? 0);
    } catch (\Throwable $e) {
        $saved = [];
        $history = method_exists($ps, 'getLastOutboundXmlHistory') ? (array) $ps->getLastOutboundXmlHistory() : [];
        $xml = method_exists($ps, 'getLastOutboundXml') ? (string) $ps->getLastOutboundXml() : '';
        if ($history === [] && $xml !== '') {
            $history = [$xml];
        }
        if ($history !== []) {
            $dir = __DIR__ . '/../logs';
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $stamp = date('Ymd_His');
            foreach ($history as $idx => $attemptXml) {
                $attemptXml = (string) $attemptXml;
                if ($attemptXml === '') {
                    continue;
                }
                $name = 'ps_order_payload_' . $orderId . '_' . $stamp . '_a' . ($idx + 1) . '.xml';
                @file_put_contents($dir . '/' . $name, $attemptXml);
                $saved[] = 'logs/' . $name;
            }
        }
        throw new RuntimeException($e->getMessage() . ($saved !== [] ? ' | payloads_saved=' . implode(',', $saved) : ''));
    }
    if ($psOrderId <= 0) {
        $detail = method_exists($ps, 'getLastApiError') ? $ps->getLastApiError() : null;
        $saved = [];
        $history = method_exists($ps, 'getLastOutboundXmlHistory') ? (array) $ps->getLastOutboundXmlHistory() : [];
        $xml = method_exists($ps, 'getLastOutboundXml') ? (string) $ps->getLastOutboundXml() : '';
        if ($history === [] && $xml !== '') {
            $history = [$xml];
        }
        if ($history !== []) {
            $dir = __DIR__ . '/../logs';
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $stamp = date('Ymd_His');
            foreach ($history as $idx => $attemptXml) {
                $attemptXml = (string) $attemptXml;
                if ($attemptXml === '') {
                    continue;
                }
                $name = 'ps_order_payload_' . $orderId . '_' . $stamp . '_a' . ($idx + 1) . '.xml';
                @file_put_contents($dir . '/' . $name, $attemptXml);
                $saved[] = 'logs/' . $name;
            }
        }
        throw new RuntimeException(
            'PrestaShop order creation failed'
            . ($detail ? '. Details: ' . $detail : '')
            . ($saved !== [] ? ' | payloads_saved=' . implode(',', $saved) : '')
        );
    }

    Database::execute(
        "UPDATE orders SET ps_order_id = ?, sync_status = 'imported', error_message = NULL, last_synced_at = NOW(), updated_at = NOW() WHERE id = ?",
        [$psOrderId, $orderId]
    );

    return [
        'ps_order_id' => $psOrderId,
        'carrier_id' => $ps->getLastResolvedCarrierId(),
    ];
}

function resolve_local_order_item_for_ps(PrestaShopClient $ps, array $item): ?array
{
    $sku = trim((string) ($item['sku'] ?? ''));
    $ean = trim((string) ($item['ean13'] ?? ''));

    $dbResolved = null;
    if ($sku !== '') {
        $dbResolved = Database::fetchOne(
            "SELECT v.product_id AS product_id, v.ps_combo_id AS combo_id
             FROM product_variants v WHERE UPPER(v.sku) = UPPER(?) LIMIT 1",
            [$sku]
        );
    }
    if (!$dbResolved && $ean !== '') {
        $dbResolved = Database::fetchOne(
            "SELECT v.product_id AS product_id, v.ps_combo_id AS combo_id
             FROM product_variants v WHERE v.ean13 = ? LIMIT 1",
            [$ean]
        );
    }
    if ($dbResolved) {
        return [
            'product_id' => (int) ($dbResolved['product_id'] ?? 0),
            'combo_id' => (int) ($dbResolved['combo_id'] ?? 0),
            'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
            'unit_price' => max(0, (float) ($item['unit_price'] ?? 0) - (float) ($item['discount_amount'] ?? 0)),
            'sku' => $sku,
        ];
    }

    if ($sku !== '') {
        $combo = $ps->findCombinationByReference($sku);
        if ($combo) {
            return [
                'product_id' => (int) ($combo['product_id'] ?? 0),
                'combo_id' => (int) ($combo['combo_id'] ?? 0),
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'unit_price' => max(0, (float) ($item['unit_price'] ?? 0) - (float) ($item['discount_amount'] ?? 0)),
                'sku' => $sku,
            ];
        }
        $productId = $ps->findProductIdByReference($sku);
        if ($productId) {
            return [
                'product_id' => (int) $productId,
                'combo_id' => 0,
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'unit_price' => max(0, (float) ($item['unit_price'] ?? 0) - (float) ($item['discount_amount'] ?? 0)),
                'sku' => $sku,
            ];
        }
    }
    if ($ean !== '') {
        $combo = $ps->findCombinationByEan($ean);
        if ($combo) {
            return [
                'product_id' => (int) ($combo['product_id'] ?? 0),
                'combo_id' => (int) ($combo['combo_id'] ?? 0),
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'unit_price' => max(0, (float) ($item['unit_price'] ?? 0) - (float) ($item['discount_amount'] ?? 0)),
                'sku' => $sku,
            ];
        }
        $productId = $ps->findProductIdByEan($ean);
        if ($productId) {
            return [
                'product_id' => (int) $productId,
                'combo_id' => 0,
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'unit_price' => max(0, (float) ($item['unit_price'] ?? 0) - (float) ($item['discount_amount'] ?? 0)),
                'sku' => $sku,
            ];
        }
    }

    return null;
}

function split_customer_name(string $fullName): array
{
    $clean = trim($fullName);
    if ($clean === '') {
        return ['AboutYou', 'Customer'];
    }
    $parts = preg_split('/\s+/', $clean) ?: [];
    $first = $parts[0] ?? 'AboutYou';
    $last = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : 'Customer';
    return [trim($first), trim($last)];
}

function parse_order_address_json(string $json, string $countryIso, string $firstName, string $lastName, string $orderRef): array
{
    $decoded = [];
    if (trim($json) !== '') {
        try {
            $parsed = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($parsed)) {
                $decoded = $parsed;
            }
        } catch (\Throwable) {
            $decoded = [];
        }
    }
    $street = trim((string) ($decoded['address1'] ?? $decoded['street'] ?? 'Manual order street'));
    $postcode = trim((string) ($decoded['postcode'] ?? $decoded['zip'] ?? '0000'));
    $city = trim((string) ($decoded['city'] ?? 'Unknown'));
    return [
        'alias' => 'Manual ' . substr($orderRef, 0, 32),
        'first_name' => trim((string) ($decoded['first_name'] ?? $firstName)),
        'last_name' => trim((string) ($decoded['last_name'] ?? $lastName)),
        'address1' => $street,
        'address2' => trim((string) ($decoded['address2'] ?? '')),
        'postcode' => $postcode,
        'city' => $city,
        'country_iso' => strtoupper(trim((string) ($decoded['country_iso'] ?? $countryIso ?: 'DE'))),
        'phone' => trim((string) ($decoded['phone'] ?? '')),
    ];
}

function is_valid_ean13(string $value): bool
{
    if (!preg_match('/^\d{13}$/', $value)) {
        return false;
    }
    $digits = array_map('intval', str_split($value));
    $checkDigit = array_pop($digits);
    $sum = 0;
    foreach ($digits as $idx => $digit) {
        $sum += $digit * ($idx % 2 === 0 ? 1 : 3);
    }
    $computed = (10 - ($sum % 10)) % 10;
    return $computed === $checkDigit;
}

json_out(400, ['ok' => false, 'error' => "Unknown action: {$action}"]);
