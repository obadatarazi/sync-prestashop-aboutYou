#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use SyncBridge\Database\Database;
use SyncBridge\Database\ProductRepository;
use SyncBridge\Database\SyncRunRepository;
use SyncBridge\Services\SyncRunner;

$pidPath = resolveSyncPidPath();
registerPidFile($pidPath);

$command = $argv[1] ?? 'status';

echo "SyncBridge CLI\n";
echo "Command: {$command}\n\n";

try {
    switch ($command) {
        case 'status':
            showStatus();
            break;
        case 'products':
            runSyncCommand('products', parseOptions($argv));
            break;
        case 'products:inc':
            runSyncCommand('products:inc', parseOptions($argv));
            break;
        case 'stock':
            runSyncCommand('stock', parseOptions($argv));
            break;
        case 'orders':
            runSyncCommand('orders', parseOptions($argv));
            break;
        case 'order-status':
            runSyncCommand('order-status', parseOptions($argv));
            break;
        case 'all':
            runSyncCommand('all', parseOptions($argv));
            break;
        case 'retry':
            runSyncCommand('retry', parseOptions($argv));
            break;
        default:
            echo "Unknown command: {$command}\n";
            showHelp();
            exit(1);
    }
} catch (Throwable $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

function showStatus(): void {
    try {
        $db = Database::connect();
        
        $products = $db->query("SELECT COUNT(*) as cnt FROM products")->fetch();
        $orders = $db->query("SELECT COUNT(*) as cnt FROM orders")->fetch();
        $runs = $db->query("SELECT COUNT(*) as cnt FROM sync_runs")->fetch();
        
        echo "📊 Database Status\n";
        echo "  Products: {$products['cnt']}\n";
        echo "  Orders: {$orders['cnt']}\n";
        echo "  Sync Runs: {$runs['cnt']}\n";
        
        $psUrl = $_ENV['PS_BASE_URL'] ?? '(not set)';
        $ayUrl = $_ENV['AY_BASE_URL'] ?? '(not set)';
        
        echo "\n🔗 API Connections\n";
        echo "  PrestaShop: {$psUrl}\n";
        echo "  AboutYou: {$ayUrl}\n";
        
        echo "\n✓ System ready\n";
    } catch (Throwable $e) {
        echo "✗ Database error: " . $e->getMessage() . "\n";
        exit(1);
    }
}

function runSyncCommand(string $command, array $options): void {
    $runner = new SyncRunner();
    $result = $runner->run($command, $options);

    if (!($result['ok'] ?? false)) {
        echo "✗ Sync failed\n";
        echo "  Run ID: " . ($result['run_id'] ?? 'n/a') . "\n";
        echo "  Error: " . ($result['error'] ?? 'Unknown error') . "\n";
        exit(1);
    }

    echo "✓ Sync completed\n";
    echo "  Run ID: " . ($result['run_id'] ?? 'n/a') . "\n";
    echo json_encode($result['result'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}

function parseOptions(array $argv): array {
    $options = [];
    foreach (array_slice($argv, 2) as $arg) {
        if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
            continue;
        }
        [$key, $value] = explode('=', substr($arg, 2), 2);
        $options[str_replace('-', '_', $key)] = $value;
    }
    if (!empty($options['ps_ids'])) {
        $options['ps_product_ids'] = array_values(array_filter(array_map('intval', explode(',', (string) $options['ps_ids']))));
    }
    return $options;
}

function resolveSyncPidPath(): string
{
    $raw = (string) ($_ENV['SYNC_PID_FILE'] ?? 'logs/sync-run.pid');
    if (str_starts_with($raw, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $raw)) {
        return $raw;
    }
    return __DIR__ . '/../' . ltrim($raw, './');
}

function registerPidFile(string $pidPath): void
{
    $dir = dirname($pidPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $pid = getmypid();
    if ($pid === false) {
        return;
    }
    @file_put_contents($pidPath, (string) $pid, LOCK_EX);

    register_shutdown_function(static function () use ($pidPath, $pid): void {
        $existing = is_file($pidPath) ? (int) trim((string) @file_get_contents($pidPath)) : 0;
        if ($existing === (int) $pid) {
            @unlink($pidPath);
        }
    });
}

function showHelp(): void {
    echo "Usage: php bin/sync.php <command>\n\n";
    echo "Commands:\n";
    echo "  status           - Show system status\n";
    echo "  products         - Full product sync (PS → DB → AY)\n";
    echo "  products:inc     - Incremental products (changed only)\n";
    echo "  stock            - Sync stock & prices\n";
    echo "  orders           - Import new orders from AY\n";
    echo "  order-status     - Push order status to AY\n";
    echo "  all              - Run products incrementally + stock + orders + order status\n";
    echo "  retry            - Process retry queue\n";
}
