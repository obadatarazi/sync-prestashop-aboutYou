-- ============================================================
--  SyncBridge Database Schema
--  MySQL 8.0+ / MariaDB 10.5+
-- ============================================================

CREATE DATABASE IF NOT EXISTS syncbridge
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE syncbridge;

-- ----------------------------------------------------------------
-- USERS (admin seed included)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(80)  NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  email         VARCHAR(160) NOT NULL,
  role          ENUM('admin','viewer') NOT NULL DEFAULT 'admin',
  last_login_at DATETIME     NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Seed admin user  (password: admin123 — change immediately)
INSERT IGNORE INTO users (username, password_hash, email, role)
VALUES (
  'admin',
  '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- bcrypt of "admin123"
  'admin@example.com',
  'admin'
);

-- ----------------------------------------------------------------
-- PRODUCTS  (master copy from PrestaShop)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS products (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ps_id               INT UNSIGNED NOT NULL UNIQUE,
  ay_style_key        VARCHAR(120) NULL,
  reference           VARCHAR(120) NULL,
  name                VARCHAR(512) NOT NULL DEFAULT '',
  description         TEXT         NULL,
  description_short   TEXT         NULL,
  export_title        VARCHAR(512) NULL,
  export_description  TEXT         NULL,
  export_material_composition TEXT NULL,
  ps_api_payload      LONGTEXT     NULL,
  price               DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  weight              DECIMAL(8,3)  NOT NULL DEFAULT 0.000,
  ean13               VARCHAR(20)  NULL,
  category_ps_id      INT UNSIGNED NULL,
  category_name       VARCHAR(255) NULL,
  ay_category_id      INT UNSIGNED NULL,
  ay_brand_id         INT UNSIGNED NULL,
  country_of_origin   CHAR(2)      NULL DEFAULT 'DE',
  active              TINYINT(1)   NOT NULL DEFAULT 1,
  -- Sync state
  sync_status         ENUM('pending','syncing','synced','error','quarantined') NOT NULL DEFAULT 'pending',
  sync_error          TEXT         NULL,
  last_synced_at      DATETIME     NULL,
  ps_updated_at       DATETIME     NULL,
  -- Timestamps
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_sync_status (sync_status),
  INDEX idx_ay_style_key (ay_style_key),
  INDEX idx_ps_updated (ps_updated_at)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- PRODUCT VARIANTS  (PS combinations)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS product_variants (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id      INT UNSIGNED NOT NULL,
  ps_combo_id     INT UNSIGNED NOT NULL DEFAULT 0,
  sku             VARCHAR(160) NOT NULL,
  ean13           VARCHAR(20)  NULL,
  reference       VARCHAR(120) NULL,
  price_modifier  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  weight          DECIMAL(8,3)  NOT NULL DEFAULT 0.000,
  quantity        INT          NOT NULL DEFAULT 0,
  color_id        INT UNSIGNED NULL,
  size_id         INT UNSIGNED NULL,
  ay_pushed       TINYINT(1)   NOT NULL DEFAULT 0,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_product_combo (product_id, ps_combo_id),
  INDEX idx_sku (sku),
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- PRODUCT IMAGES
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS product_images (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id      INT UNSIGNED NOT NULL,
  ps_image_id     VARCHAR(60)  NULL,
  source_url      VARCHAR(2048) NOT NULL,
  -- Local storage
  local_path      VARCHAR(512) NULL,   -- relative path under public/ay-normalized/
  public_url      VARCHAR(2048) NULL,  -- full public URL sent to AboutYou
  -- Dimensions after normalization
  width           SMALLINT UNSIGNED NULL,
  height          SMALLINT UNSIGNED NULL,
  file_size_bytes INT UNSIGNED NULL,
  -- State
  status          ENUM('pending','processing','ok','error') NOT NULL DEFAULT 'pending',
  error_message   VARCHAR(512) NULL,
  position        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  processed_at    DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_product (product_id),
  INDEX idx_status (status),
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- ORDERS  (AboutYou → PrestaShop)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ay_order_id     VARCHAR(120) NOT NULL UNIQUE,
  ps_order_id     INT UNSIGNED NULL,
  -- Customer snapshot
  customer_email  VARCHAR(255) NULL,
  customer_name   VARCHAR(255) NULL,
  -- Financials
  total_paid      DECIMAL(10,2) NULL,
  total_products  DECIMAL(10,2) NULL,
  total_shipping  DECIMAL(10,2) NULL,
  discount_total  DECIMAL(10,2) NULL,
  currency        CHAR(3)      NOT NULL DEFAULT 'EUR',
  shipping_country_iso CHAR(2) NULL,
  billing_country_iso  CHAR(2) NULL,
  shipping_method VARCHAR(120) NULL,
  payment_method  VARCHAR(120) NULL,
  shipping_address_json LONGTEXT NULL,
  billing_address_json  LONGTEXT NULL,
  -- Status
  ay_status       VARCHAR(60)  NULL,
  ps_state_id     TINYINT UNSIGNED NULL,
  sync_status     ENUM('pending','importing','imported','status_pushed','error','quarantined') NOT NULL DEFAULT 'pending',
  sync_attempts   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  is_permanent_failure TINYINT(1) NOT NULL DEFAULT 0,
  error_message   TEXT NULL,
  -- Timestamps
  ay_created_at   DATETIME NULL,
  last_synced_at  DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_ps_order (ps_order_id),
  INDEX idx_sync_status (sync_status),
  INDEX idx_ay_status (ay_status),
  INDEX idx_ay_created (ay_created_at)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- ORDER ITEMS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS order_items (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id    INT UNSIGNED NOT NULL,
  ay_order_item_id INT UNSIGNED NULL,
  sku         VARCHAR(160) NULL,
  ean13       VARCHAR(20)  NULL,
  product_id  INT UNSIGNED NULL,
  combo_id    INT UNSIGNED NULL,
  quantity    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  unit_price  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  item_status VARCHAR(60) NULL,
  UNIQUE KEY uq_order_item (order_id, ay_order_item_id),
  INDEX idx_order_item_sku (sku),
  INDEX idx_order_item_ean13 (ean13),
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- SYNC RUNS  (one row per CLI/API sync invocation)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sync_runs (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  run_id      CHAR(16)     NOT NULL UNIQUE,
  command     VARCHAR(60)  NOT NULL,
  status      ENUM('running','completed','failed') NOT NULL DEFAULT 'running',
  -- Progress
  total_items INT UNSIGNED NOT NULL DEFAULT 0,
  done_items  INT UNSIGNED NOT NULL DEFAULT 0,
  pushed      INT UNSIGNED NOT NULL DEFAULT 0,
  skipped     INT UNSIGNED NOT NULL DEFAULT 0,
  failed      INT UNSIGNED NOT NULL DEFAULT 0,
  -- Current item
  current_product_id INT UNSIGNED NULL,
  current_phase      VARCHAR(120) NULL,
  last_message       VARCHAR(512) NULL,
  -- Timing
  started_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME     NULL,
  elapsed_sec DECIMAL(8,2) NULL,
  INDEX idx_command (command),
  INDEX idx_status (status),
  INDEX idx_started (started_at)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- SYNC LOGS  (structured per-line log entries)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sync_logs (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  run_id      CHAR(16)     NULL,
  level       ENUM('debug','info','notice','warning','error','critical') NOT NULL DEFAULT 'info',
  channel     VARCHAR(40)  NOT NULL DEFAULT 'sync',
  message     TEXT         NOT NULL,
  context     JSON         NULL,
  created_at  DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  INDEX idx_run (run_id),
  INDEX idx_run_created (run_id, created_at),
  INDEX idx_level (level),
  INDEX idx_created (created_at),
  INDEX idx_channel (channel)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- SYNC METRICS (time-series style counters and timings)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sync_metrics (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  run_id      CHAR(16) NULL,
  command     VARCHAR(60) NOT NULL,
  phase       VARCHAR(60) NOT NULL DEFAULT 'run',
  metric_key  VARCHAR(80) NOT NULL,
  metric_value DOUBLE NOT NULL,
  meta_json   JSON NULL,
  created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  INDEX idx_metric_created (created_at),
  INDEX idx_metric_run (run_id),
  INDEX idx_metric_key (metric_key)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- AY POLICY SNAPSHOTS (docs-policy drift tracking)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ay_policy_snapshots (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source      VARCHAR(80) NOT NULL DEFAULT 'mcp_docs',
  version_tag VARCHAR(80) NULL,
  payload_json JSON NOT NULL,
  created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  INDEX idx_policy_created (created_at)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- ATTRIBUTE MAPS  (PS option value → AY IDs)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS attribute_maps (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  map_type    ENUM('color','size','second_size','attribute','attribute_required') NOT NULL,
  ps_label    VARCHAR(120) NOT NULL,
  ay_group_id INT UNSIGNED NOT NULL DEFAULT 0,
  ay_group_name VARCHAR(120) NULL,
  ay_id       INT UNSIGNED NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_map (map_type, ps_label, ay_group_id)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- MATERIAL COMPONENT MAPS  (PS material label → AY material id)
-- Used to translate a parsed textile/non-textile component label
-- (e.g. "cotton") to the matching AboutYou material_id.
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS material_component_maps (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ps_label      VARCHAR(120) NOT NULL,
  ay_material_id INT UNSIGNED NOT NULL,
  ay_material_label VARCHAR(120) NULL,
  is_textile    TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_material (ps_label, is_textile),
  INDEX idx_material_label (ay_material_label)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- MATERIAL CLUSTER MAPS  (PS cluster label → AY cluster id)
-- Clusters represent logical parts of a garment (e.g. "shell", "lining").
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS material_cluster_maps (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ps_label      VARCHAR(120) NOT NULL,
  ay_cluster_id INT UNSIGNED NOT NULL,
  ay_cluster_label VARCHAR(120) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cluster (ps_label)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- AY REQUIRED GROUP DEFAULTS
-- Category-aware default option per required AY attribute group.
-- Replaces the implicit attribute_maps(map_type='attribute_required',
-- ps_label='__default__') convention; backward compatible alongside it.
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ay_required_group_defaults (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ay_category_id  INT UNSIGNED NOT NULL,
  ay_group_id     INT UNSIGNED NOT NULL,
  ay_group_name   VARCHAR(120) NULL,
  default_ay_id   INT UNSIGNED NOT NULL,
  default_label   VARCHAR(160) NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cat_group (ay_category_id, ay_group_id),
  INDEX idx_group (ay_group_id)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- PRODUCT MATERIAL COMPOSITION  (per-product structured fractions)
-- Allows operators to override parsed PS composition with a curated
-- AY-contract-compliant composition list.
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS product_material_composition (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id      INT UNSIGNED NOT NULL,
  is_textile      TINYINT(1) NOT NULL DEFAULT 1,
  cluster_id      INT UNSIGNED NOT NULL DEFAULT 1,
  cluster_label   VARCHAR(120) NULL,
  ay_material_id  INT UNSIGNED NOT NULL,
  material_label  VARCHAR(120) NULL,
  fraction        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_product_textile (product_id, is_textile),
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- PRODUCT SYNC ERROR EVENTS (historical, per-product)
-- Stores each sync/preflight/API failure so admins can see actionable
-- issues while editing local export overrides, independent of PS state.
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS product_sync_errors (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id      INT UNSIGNED NOT NULL,
  ps_id           INT UNSIGNED NOT NULL,
  run_id          CHAR(16) NULL,
  phase           ENUM('preflight','push','runtime') NOT NULL DEFAULT 'runtime',
  reason_code     VARCHAR(64) NOT NULL DEFAULT 'unknown',
  error_message   TEXT NOT NULL,
  error_details   JSON NULL,
  created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  INDEX idx_product_created (product_id, created_at),
  INDEX idx_ps_created (ps_id, created_at),
  INDEX idx_reason (reason_code),
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- SETTINGS  (key-value config store, editable from UI)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
  `key`       VARCHAR(120) NOT NULL PRIMARY KEY,
  `value`     TEXT         NULL,
  `type`      ENUM('string','boolean','integer','json','password') NOT NULL DEFAULT 'string',
  label       VARCHAR(255) NULL,
  group_name  VARCHAR(80)  NULL,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO settings (`key`, `value`, `type`, label, group_name) VALUES
('ps_base_url',               '',      'string',  'PrestaShop Base URL',        'prestashop'),
('ps_api_key',                '',      'password','PrestaShop API Key',          'prestashop'),
('ps_language_id',            '1',     'integer', 'Language ID',                'prestashop'),
('ps_shop_id',                '1',     'integer', 'Shop ID',                    'prestashop'),
('ay_base_url',               'https://partner.aboutyou.com/api/v1','string','AY Base URL','aboutyou'),
('ay_api_key',                '',      'password','AboutYou API Key',            'aboutyou'),
('ay_brand_id',               '',      'string',  'Brand ID',                   'aboutyou'),
('ay_brand_map',              '{}',    'json',    'Brand Map JSON',             'aboutyou'),
('ay_category_id',            '',      'integer', 'Default Category ID',        'aboutyou'),
('ay_category_map',           '{}',    'json',    'Category Map JSON',          'aboutyou'),
('ay_default_color_id',       '',      'integer', 'Default Color ID',           'aboutyou'),
('ay_default_size_id',        '',      'integer', 'Default Size ID',            'aboutyou'),
('ay_default_second_size_id', '',      'integer', 'Default Second Size ID',     'aboutyou'),
('ay_default_material_composition_textile','', 'string', 'Default Textile Composition', 'aboutyou'),
('ay_material_component_map', '{}',    'json',    'Material Component Map JSON','aboutyou'),
('ay_material_cluster_map',   '{}',    'json',    'Material Cluster Map JSON',  'aboutyou'),
('ay_default_material_cluster_id','1', 'integer', 'Default Material Cluster ID','aboutyou'),
('ay_strict_preflight',       'true',  'boolean', 'Strict AY Preflight Validation', 'aboutyou'),
('ay_require_category_metadata','true','boolean', 'Require AY Category Metadata in Strict Mode', 'aboutyou'),
('ay_assume_category_groups_required','false','boolean', 'Treat AY category groups as required when required flag missing', 'aboutyou'),
('ay_fallback_required_text_fields','material_composition_textile','string', 'Fallback required text fields CSV when AY metadata unavailable', 'aboutyou'),
('ay_max_images',             '7',     'integer', 'Maximum images per AY variant payload', 'aboutyou'),
('ay_allow_description_fallback','false','boolean','Allow fallback description from short description/title', 'aboutyou'),
('ay_description_locale',     'en',    'string',  'Description Locale',         'aboutyou'),
('sync_batch_size',           '50',    'integer', 'Batch Size',                 'sync'),
('sync_incremental',          'true',  'boolean', 'Incremental Sync',           'sync'),
('sync_schedules',            '{}',    'json',    'Sync Schedules JSON',        'sync'),
('ui_auto_refresh_enabled',   'true',  'boolean', 'UI Auto Refresh',            'sync'),
('ui_auto_refresh_interval_sec','3600','integer', 'UI Refresh Interval (sec)',  'sync'),
('test_mode',                 'false', 'boolean', 'Test Mode',                  'sync'),
('dry_run',                   'false', 'boolean', 'Dry Run',                    'sync'),
('image_normalize_enabled',   'true',  'boolean', 'Image Normalization',        'images'),
('image_public_base_url',     '',      'string',  'Image Public Base URL',      'images'),
('image_jpeg_quality',        '92',    'integer', 'JPEG Quality (60-100)',      'images'),
('notify_slack_enabled',      'false', 'boolean', 'Slack Notifications',        'notifications'),
('notify_slack_webhook',      '',      'string',  'Slack Webhook URL',          'notifications'),
('notify_email_enabled',      'false', 'boolean', 'Email Notifications',        'notifications'),
('notify_email_to',           '',      'string',  'Notification Email',         'notifications'),
('ps_default_carrier_id',     '',      'integer', 'Default Carrier ID',         'prestashop'),
('ps_default_currency_id',    '1',     'integer', 'Default Currency ID',        'prestashop'),
('ps_order_state_id',         '3',     'integer', 'Imported Order State ID',    'prestashop'),
('ay_auto_publish',           'true',  'boolean', 'Auto Publish Products',      'aboutyou'),
('ay_country_codes',          'DE',    'string',  'Country Codes CSV',          'aboutyou'),
('ay_batch_poll_attempts',    '10',    'integer', 'Batch Poll Attempts',        'aboutyou'),
('ay_batch_poll_ms',          '1500',  'integer', 'Batch Poll Interval (ms)',   'aboutyou'),
('feature_ay_adaptive_throttle','true','boolean','Feature: AY adaptive throttle','features'),
('feature_idempotent_status_push','true','boolean','Feature: idempotent status push','features'),
('feature_sync_metrics','true','boolean','Feature: sync metrics storage','features');

-- ----------------------------------------------------------------
-- RETRY JOBS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS retry_jobs (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_type     VARCHAR(80) NOT NULL,
  entity_key   VARCHAR(160) NOT NULL,
  payload_json JSON NULL,
  last_error   TEXT NULL,
  attempts     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  next_retry_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status       ENUM('pending','done','dead') NOT NULL DEFAULT 'pending',
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_retry_job (job_type, entity_key),
  INDEX idx_retry_status (status, next_retry_at)
) ENGINE=InnoDB;
