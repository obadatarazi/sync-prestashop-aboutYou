#!/usr/bin/env php
<?php
/**
 * migrate.php
 * Run:  php migrate.php
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

$host = $_ENV['DB_HOST']     ?? '127.0.0.1';
$port = $_ENV['DB_PORT']     ?? '3306';
$user = $_ENV['DB_USER']     ?? 'root';
$pass = $_ENV['DB_PASSWORD'] ?? '';

echo "Running SyncBridge migrations...\n";

try {
    // Connect without database first to create it
    $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    // Create database first
    $dbName = $_ENV['DB_NAME'] ?? 'syncbridge';
    try {
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        echo "  ✓ Database '{$dbName}' ready\n";
    } catch (PDOException $e) {
        echo "  ✗ Could not create database: " . $e->getMessage() . "\n";
        exit(1);
    }

    // Now connect to the specific database
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $schema = file_get_contents(__DIR__ . '/schema.sql');
    if ($schema === false) {
        throw new RuntimeException('Cannot read schema.sql');
    }

    // Parse SQL statements more carefully
    $statements = parseSqlStatements($schema);
    
    $count = 0;
    foreach ($statements as $stmt) {
        try {
            $pdo->exec($stmt);
            $count++;
        } catch (PDOException $e) {
            // Log errors but continue
            if (!str_contains($e->getMessage(), 'already exists') &&
                !str_contains($e->getMessage(), 'Duplicate')) {
                echo "  ⚠ " . substr($e->getMessage(), 0, 50) . "\n";
            }
        }
    }

    applyPostSchemaAdjustments($pdo);

    echo "✓ Migration complete. Executed {$count} statements.\n";
    echo "  Admin: admin / admin123 (change immediately!)\n";

} catch (Exception $e) {
    echo "✗ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}

/**
 * Parse SQL file into individual statements
 */
function parseSqlStatements(string $sql): array {
    $statements = [];
    $current = '';
    $inString = false;
    $stringChar = null;
    
    $lines = explode("\n", $sql);
    
    foreach ($lines as $line) {
        // Skip full-line comments
        $trimmed = ltrim($line);
        if (strpos($trimmed, '--') === 0 || empty($trimmed)) {
            continue;
        }
        
        // Remove inline comments
        if (($pos = strpos($line, '--')) !== false) {
            $line = substr($line, 0, $pos);
        }
        
        $current .= $line . ' ';
        
        // Check if statement ends with semicolon
        if (strpos($line, ';') !== false) {
            $stmt = trim($current);
            // Remove trailing semicolon
            $stmt = rtrim($stmt, ';');
            $stmt = trim($stmt);
            
            // Skip CREATE DATABASE and USE statements
            if (!preg_match('/^(CREATE\s+DATABASE|USE\s+)/i', $stmt)) {
                if (!empty($stmt)) {
                    $statements[] = $stmt;
                }
            }
            
            $current = '';
        }
    }
    
    // Add any remaining statement
    if (!empty(trim($current))) {
        $stmt = trim($current);
        $stmt = rtrim($stmt, ';');
        $stmt = trim($stmt);
        if (!preg_match('/^(CREATE\s+DATABASE|USE\s+)/i', $stmt) && !empty($stmt)) {
            $statements[] = $stmt;
        }
    }
    
    return $statements;

}

function applyPostSchemaAdjustments(PDO $pdo): void {
    ensureColumn($pdo, 'order_items', 'ay_order_item_id', "ALTER TABLE order_items ADD COLUMN ay_order_item_id INT UNSIGNED NULL AFTER order_id");
    ensureColumn($pdo, 'order_items', 'item_status', "ALTER TABLE order_items ADD COLUMN item_status VARCHAR(60) NULL AFTER unit_price");
    ensureColumn($pdo, 'settings', 'label', "ALTER TABLE settings ADD COLUMN label VARCHAR(255) NULL AFTER type");
    ensureColumn($pdo, 'settings', 'group_name', "ALTER TABLE settings ADD COLUMN group_name VARCHAR(80) NULL AFTER label");
    ensureColumn($pdo, 'products', 'export_title', "ALTER TABLE products ADD COLUMN export_title VARCHAR(512) NULL AFTER description_short");
    ensureColumn($pdo, 'products', 'export_description', "ALTER TABLE products ADD COLUMN export_description TEXT NULL AFTER export_title");
    ensureColumn($pdo, 'products', 'export_material_composition', "ALTER TABLE products ADD COLUMN export_material_composition TEXT NULL AFTER export_description");
    ensureColumn($pdo, 'products', 'ps_api_payload', "ALTER TABLE products ADD COLUMN ps_api_payload LONGTEXT NULL AFTER export_material_composition");
    ensureColumn($pdo, 'orders', 'shipping_country_iso', "ALTER TABLE orders ADD COLUMN shipping_country_iso CHAR(2) NULL AFTER currency");
    ensureColumn($pdo, 'orders', 'discount_total', "ALTER TABLE orders ADD COLUMN discount_total DECIMAL(10,2) NULL AFTER total_shipping");
    ensureColumn($pdo, 'orders', 'billing_country_iso', "ALTER TABLE orders ADD COLUMN billing_country_iso CHAR(2) NULL AFTER shipping_country_iso");
    ensureColumn($pdo, 'orders', 'shipping_method', "ALTER TABLE orders ADD COLUMN shipping_method VARCHAR(120) NULL AFTER billing_country_iso");
    ensureColumn($pdo, 'orders', 'payment_method', "ALTER TABLE orders ADD COLUMN payment_method VARCHAR(120) NULL AFTER shipping_method");
    ensureColumn($pdo, 'orders', 'shipping_address_json', "ALTER TABLE orders ADD COLUMN shipping_address_json LONGTEXT NULL AFTER payment_method");
    ensureColumn($pdo, 'orders', 'billing_address_json', "ALTER TABLE orders ADD COLUMN billing_address_json LONGTEXT NULL AFTER shipping_address_json");
    ensureColumn($pdo, 'order_items', 'discount_amount', "ALTER TABLE order_items ADD COLUMN discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER unit_price");
    ensureColumn($pdo, 'attribute_maps', 'ay_group_id', "ALTER TABLE attribute_maps ADD COLUMN ay_group_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER ps_label");
    ensureColumn($pdo, 'attribute_maps', 'ay_group_name', "ALTER TABLE attribute_maps ADD COLUMN ay_group_name VARCHAR(120) NULL AFTER ay_group_id");

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS retry_jobs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            job_type VARCHAR(80) NOT NULL,
            entity_key VARCHAR(160) NOT NULL,
            payload_json JSON NULL,
            last_error TEXT NULL,
            attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            next_retry_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            status ENUM('pending','done','dead') NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_retry_job (job_type, entity_key),
            INDEX idx_retry_status (status, next_retry_at)
        ) ENGINE=InnoDB");
    } catch (PDOException $e) {
        echo "  ⚠ Post-migration: " . substr($e->getMessage(), 0, 120) . "\n";
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS material_component_maps (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ps_label VARCHAR(120) NOT NULL,
            ay_material_id INT UNSIGNED NOT NULL,
            ay_material_label VARCHAR(120) NULL,
            is_textile TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_material (ps_label, is_textile),
            INDEX idx_material_label (ay_material_label)
        ) ENGINE=InnoDB");
    } catch (PDOException $e) {
        echo "  ⚠ Post-migration: " . substr($e->getMessage(), 0, 120) . "\n";
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS material_cluster_maps (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ps_label VARCHAR(120) NOT NULL,
            ay_cluster_id INT UNSIGNED NOT NULL,
            ay_cluster_label VARCHAR(120) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_cluster (ps_label)
        ) ENGINE=InnoDB");
    } catch (PDOException $e) {
        echo "  ⚠ Post-migration: " . substr($e->getMessage(), 0, 120) . "\n";
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ay_required_group_defaults (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ay_category_id INT UNSIGNED NOT NULL,
            ay_group_id INT UNSIGNED NOT NULL,
            ay_group_name VARCHAR(120) NULL,
            default_ay_id INT UNSIGNED NOT NULL,
            default_label VARCHAR(160) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_cat_group (ay_category_id, ay_group_id),
            INDEX idx_group (ay_group_id)
        ) ENGINE=InnoDB");
    } catch (PDOException $e) {
        echo "  ⚠ Post-migration: " . substr($e->getMessage(), 0, 120) . "\n";
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS product_material_composition (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_id INT UNSIGNED NOT NULL,
            is_textile TINYINT(1) NOT NULL DEFAULT 1,
            cluster_id INT UNSIGNED NOT NULL DEFAULT 1,
            cluster_label VARCHAR(120) NULL,
            ay_material_id INT UNSIGNED NOT NULL,
            material_label VARCHAR(120) NULL,
            fraction SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_product_textile (product_id, is_textile),
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
    } catch (PDOException $e) {
        echo "  ⚠ Post-migration: " . substr($e->getMessage(), 0, 120) . "\n";
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS product_sync_errors (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_id INT UNSIGNED NOT NULL,
            ps_id INT UNSIGNED NOT NULL,
            run_id CHAR(16) NULL,
            phase ENUM('preflight','push','runtime') NOT NULL DEFAULT 'runtime',
            reason_code VARCHAR(64) NOT NULL DEFAULT 'unknown',
            error_message TEXT NOT NULL,
            error_details JSON NULL,
            created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            INDEX idx_product_created (product_id, created_at),
            INDEX idx_ps_created (ps_id, created_at),
            INDEX idx_reason (reason_code),
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
    } catch (PDOException $e) {
        echo "  ⚠ Post-migration: " . substr($e->getMessage(), 0, 120) . "\n";
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sync_metrics (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            run_id CHAR(16) NULL,
            command VARCHAR(60) NOT NULL,
            phase VARCHAR(60) NOT NULL DEFAULT 'run',
            metric_key VARCHAR(80) NOT NULL,
            metric_value DOUBLE NOT NULL,
            meta_json JSON NULL,
            created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            INDEX idx_metric_created (created_at),
            INDEX idx_metric_run (run_id),
            INDEX idx_metric_key (metric_key)
        ) ENGINE=InnoDB");
    } catch (PDOException $e) {
        echo "  ⚠ Post-migration: " . substr($e->getMessage(), 0, 120) . "\n";
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ay_policy_snapshots (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            source VARCHAR(80) NOT NULL DEFAULT 'mcp_docs',
            version_tag VARCHAR(80) NULL,
            payload_json JSON NOT NULL,
            created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            INDEX idx_policy_created (created_at)
        ) ENGINE=InnoDB");
    } catch (PDOException $e) {
        echo "  ⚠ Post-migration: " . substr($e->getMessage(), 0, 120) . "\n";
    }

    ensureIndex($pdo, 'order_items', 'uq_order_item', 'ALTER TABLE order_items ADD UNIQUE KEY uq_order_item (order_id, ay_order_item_id)');
    try {
        $pdo->exec("ALTER TABLE attribute_maps MODIFY ay_group_id INT UNSIGNED NOT NULL DEFAULT 0");
    } catch (PDOException $e) {
        echo "  ⚠ Post-migration: " . substr($e->getMessage(), 0, 120) . "\n";
    }
    try {
        $pdo->exec("ALTER TABLE attribute_maps DROP INDEX uq_map, ADD UNIQUE KEY uq_map (map_type, ps_label, ay_group_id)");
    } catch (PDOException $e) {
        if (!str_contains($e->getMessage(), 'Duplicate')) {
            echo "  ⚠ Post-migration: " . substr($e->getMessage(), 0, 120) . "\n";
        }
    }

    $settings = [
        ['ps_default_carrier_id', '', 'integer', 'Default Carrier ID', 'prestashop'],
        ['ps_default_currency_id', '1', 'integer', 'Default Currency ID', 'prestashop'],
        ['ps_order_state_id', '3', 'integer', 'Imported Order State ID', 'prestashop'],
        ['ay_auto_publish', 'true', 'boolean', 'Auto Publish Products', 'aboutyou'],
        ['ay_brand_map', '{}', 'json', 'Brand Map JSON', 'aboutyou'],
        ['ay_category_map', '{}', 'json', 'Category Map JSON', 'aboutyou'],
        ['ay_default_color_id', '', 'integer', 'Default Color ID', 'aboutyou'],
        ['ay_default_size_id', '', 'integer', 'Default Size ID', 'aboutyou'],
        ['ay_default_second_size_id', '', 'integer', 'Default Second Size ID', 'aboutyou'],
        ['ay_default_material_composition_textile', '', 'string', 'Default Textile Composition', 'aboutyou'],
        ['ay_material_component_map', '{}', 'json', 'Material Component Map JSON', 'aboutyou'],
        ['ay_material_cluster_map', '{}', 'json', 'Material Cluster Map JSON', 'aboutyou'],
        ['ay_default_material_cluster_id', '1', 'integer', 'Default Material Cluster ID', 'aboutyou'],
        ['ay_strict_preflight', 'true', 'boolean', 'Strict AY Preflight Validation', 'aboutyou'],
        ['ay_require_category_metadata', 'true', 'boolean', 'Require AY Category Metadata in Strict Mode', 'aboutyou'],
        ['ay_assume_category_groups_required', 'false', 'boolean', 'Treat AY category groups as required when required flag missing', 'aboutyou'],
        ['ay_fallback_required_text_fields', 'material_composition_textile', 'string', 'Fallback required text fields CSV when AY metadata unavailable', 'aboutyou'],
        ['ay_max_images', '7', 'integer', 'Maximum images per AY variant payload', 'aboutyou'],
        ['ay_allow_description_fallback', 'false', 'boolean', 'Allow fallback description from short description/title', 'aboutyou'],
        ['sync_schedules', '{}', 'json', 'Sync Schedules JSON', 'sync'],
        ['ui_auto_refresh_enabled', 'true', 'boolean', 'UI Auto Refresh', 'sync'],
        ['ui_auto_refresh_interval_sec', '3600', 'integer', 'UI Refresh Interval (sec)', 'sync'],
        ['ay_country_codes', 'DE', 'string', 'Country Codes CSV', 'aboutyou'],
        ['ay_batch_poll_attempts', '10', 'integer', 'Batch Poll Attempts', 'aboutyou'],
        ['ay_batch_poll_ms', '1500', 'integer', 'Batch Poll Interval (ms)', 'aboutyou'],
        ['feature_ay_adaptive_throttle', 'true', 'boolean', 'Feature: AY adaptive throttle', 'features'],
        ['feature_idempotent_status_push', 'true', 'boolean', 'Feature: idempotent status push', 'features'],
        ['feature_sync_metrics', 'true', 'boolean', 'Feature: sync metrics storage', 'features'],
    ];

    $stmt = $pdo->prepare(
        "INSERT IGNORE INTO settings (`key`, `value`, `type`, label, group_name) VALUES (?, ?, ?, ?, ?)"
    );
    foreach ($settings as $row) {
        $stmt->execute($row);
    }
}

function ensureColumn(PDO $pdo, string $table, string $column, string $sql): void {
    $dbName = $_ENV['DB_NAME'] ?? 'syncbridge';
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$dbName, $table, $column]);
    if ((int) $stmt->fetchColumn() > 0) {
        return;
    }

    try {
        $pdo->exec($sql);
    } catch (PDOException $e) {
        echo "  ⚠ Post-migration: " . substr($e->getMessage(), 0, 120) . "\n";
    }
}

function ensureIndex(PDO $pdo, string $table, string $index, string $sql): void {
    $dbName = $_ENV['DB_NAME'] ?? 'syncbridge';
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $stmt->execute([$dbName, $table, $index]);
    if ((int) $stmt->fetchColumn() > 0) {
        return;
    }

    try {
        $pdo->exec($sql);
    } catch (PDOException $e) {
        echo "  ⚠ Post-migration: " . substr($e->getMessage(), 0, 120) . "\n";
    }
}
