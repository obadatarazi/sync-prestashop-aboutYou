# SyncBridge Domain Inventory

## CLI Commands (bin/sync.php)
- `status`
- `products`
- `products:inc`
- `stock`
- `orders`
- `order-status`
- `all`
- `retry`

## API Actions (public/api.php)
- auth/session: `login`, `logout`, `csrf`, `status`
- products: `products`, `products_export_csv`, `products_compare`, `product_detail`, `product_save`, `products_recheck_ay`
- product attributes/composition: `product_variant_eans_save`, `product_map_attributes_save`, `product_auto_map_attributes`, `product_material_composition_save`, `preflight_check`
- categories: `ps_category_path`, `ps_product_categories`, `ay_category_suggest_for_product`, `category_mappings`, `category_mappings_save`, `category_products`, `category_products_assign_ay_category`, `category_products_suggest_mappings`, `product_assign_ay_category`, `product_assign_ay_category_bulk`, `category_mapping_validate`, `ay_categories_search`, `ay_categories_catalog_sync`, `ay_categories_catalog`
- attributes/materials: `attribute_mappings`, `attribute_mappings_save`, `ay_attribute_options`, `ay_attribute_options_by_group`, `material_mappings`, `material_mappings_save`, `required_group_defaults`, `required_group_defaults_save`, `required_group_default_options`, `required_group_defaults_autofill`
- orders: `orders`, `order_items`, `order_item_products_resolve`, `order_save`, `order_push`
- platform diagnostics: `ps_schema_probe`, `ps_api_permissions_probe`, `ps_shop_info`
- observability: `logs`, `logs_delete`, `sync_runs`, `sync_spawn_status`, `metrics`, `policy_snapshot`, `policy_snapshot_refresh`, `images`, `image_retry_failed`
- settings/scheduler/sync: `settings`, `settings_save`, `toggle`, `scheduler_get`, `scheduler_save`, `sync`, `sync_stop`

## Core Modules
- Product sync orchestration: `ProductSyncService.php`
- Order sync orchestration: `OrderSyncService.php`
- Runtime orchestrator: `SyncRunner.php`
- Integrations: `src/Integration/PrestaShopClient.php`, `src/Integration/AboutYouClient.php`, `src/Integration/AboutYouMapper.php`
- Persistence: root-level repositories (`ProductRepository.php`, `OrderRepository.php`, `RetryJobRepository.php`, `SyncRunRepository.php`, `SyncMetricsRepository.php`)

## Target Migration Mapping
- HTTP actions -> versioned REST API in `routes/api.php`
- CLI commands -> Artisan commands in `app/Console/Commands`
- Sync flows -> service layer in `app/Services`
- SQL/repositories -> Eloquent models + query-focused repositories in `app/Repositories`
- Contract responses -> resources in `app/Http/Resources`
