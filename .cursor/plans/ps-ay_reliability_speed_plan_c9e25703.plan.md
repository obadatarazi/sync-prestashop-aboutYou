---
name: PS-AY Reliability Speed Plan
overview: Fix mapping errors shown in your sync logs (category-required AY attributes, textile composition, duplicate variants), unify PS↔AY field resolution, and remove the main performance bottlenecks—with tests and observability.
todos:
  - id: ay-category-required-attrs
    content: Load AY category attribute groups, persist per-group PS→AY mappings, merge required option ids into variant payload + pre-validate
    status: completed
  - id: material-composition-textile
    content: Add material_composition_textile (PS features primary, product override + env fallback, warnings on fallback)
    status: completed
  - id: variant-uniqueness-second-size
    content: Resolve second_size from PS; detect (style_key,color,size,second_size) collisions with actionable errors
    status: completed
  - id: fix-map-resolution
    content: Unify category/brand ID resolution for scalar vs {id} map entries in AboutYouMapper
    status: completed
  - id: unify-price-calculation
    content: Single source of truth for variant retail price across mapper and stock/price sync
    status: completed
  - id: optimize-combination-fetch
    content: Cache PS product_option_values/product_options during sync to remove N+1 GETs
    status: completed
  - id: optimize-images-logging
    content: Skip unchanged image normalization; throttle bulk DB logging/progress updates
    status: completed
  - id: add-regression-tests
    content: Mapper + minimal service tests for new validation paths
    status: completed
isProject: false
---

# PrestaShop↔AboutYou Stabilization Plan (updated with your runtime errors)

## What your log is telling us

The failures for **PS#72** are **AboutYou-side validation**, not PrestaShop fetch errors:

1. **Missing attribute for group … (ids 1400, 1712, …)**  
   Your selected AboutYou **category** requires many attribute groups (quantity_per_pack, fitting, pattern, etc.). The mapper today only sends a flat `attributes` array built in [`AboutYouMapper::resolveAttributes`](file:///Users/obadaal-tarazi/Downloads/Sync%20Prestashop:AboutYou/src/Integration/AboutYouMapper.php) from `attribute_maps` rows with `map_type = 'attribute'`. It does **not** guarantee that **every required group** for that category has a chosen AboutYou option id.

2. **`material_composition_textile is required`**  
   The payload built in [`AboutYouMapper::mapVariant`](file:///Users/obadaal-tarazi/Downloads/Sync%20Prestashop:AboutYou/src/Integration/AboutYouMapper.php) has **no** field for textile composition. AboutYou expects this (at least for your category path).

3. **`Duplicate variant combination (style_key, color, size, second_size=None)`**  
   Two PrestaShop combinations are mapping to the **same** AboutYou tuple `(style_key, color, size, second_size)`. The mapper does not set `second_size` at all, so distinct PS combinations that share the same color+size collapse into one AboutYou variant key.

4. **Performance note (same log)**  
   `Fetching combinations PS#72` took ~**21s** — consistent with **N+1 PrestaShop webservice calls** inside [`PrestaShopClient::loadCombinationAttributes`](file:///Users/obadaal-tarazi/Downloads/Sync%20Prestashop:AboutYou/src/Integration/PrestaShopClient.php) (per option value / per group requests), not “mapping logic” alone.

---

## Design decisions (aligned with your choices)

- **Balanced** correctness + speed.
- **“Best” composition sourcing**: prefer **real PrestaShop data** (features / structured attributes) per product, with **safe fallbacks** (env/DB + warnings) when missing — not a blind global string for every SKU unless PS truly has nothing.

---

## Phase A — Stop bad payloads before they hit AboutYou (highest ROI)

### A1. Category requirement awareness (pre-push validation + guided mapping)

- Use existing AboutYou metadata loading in [`AboutYouClient::getCategoryAttributeGroups`](file:///Users/obadaal-tarazi/Downloads/Sync%20Prestashop:AboutYou/src/Integration/AboutYouClient.php) (already used by [`searchAttributeOptions`](file:///Users/obadaal-tarazi/Downloads/Sync%20Prestashop:AboutYou/src/Integration/AboutYouClient.php)) to determine **which groups are required** for the resolved `categoryId`.
- Extend mapping data model so each required group can be satisfied predictably:
  - **Preferred**: extend [`attribute_maps`](file:///Users/obadaal-tarazi/Downloads/Sync%20Prestashop:AboutYou/schema.sql) (or add a sibling table) to key mappings by **`ay_group_id` + PS label/value** (not only `map_type='attribute'` + label), because multiple groups can share similar labels.
  - **UI**: reuse patterns in [`public/api.php`](file:///Users/obadaal-tarazi/Downloads/Sync%20Prestashop:AboutYou/public/api.php) (category-scoped search + save) so operators can map PS values → AboutYou option ids per group.
- **Mapper**: merge resolved option ids into the variant payload in the shape AboutYou expects (today: `attributes: int[]` in [`AboutYouMapper::mapVariant`](file:///Users/obadaal-tarazi/Downloads/Sync%20Prestashop:AboutYou/src/Integration/AboutYouMapper.php); adjust if the API expects grouped structures — validated against real API responses during implementation).

### A2. Textile composition (`material_composition_textile`)

- Add a dedicated mapping path:
  - **Primary**: extract from PrestaShop product data (typically **features** / selected attributes — exact extraction follows what [`PrestaShopClient`](file:///Users/obadaal-tarazi/Downloads/Sync%20Prestashop:AboutYou/src/Integration/PrestaShopClient.php) already returns for the product).
  - **Fallback**: product-level override columns in [`products`](file:///Users/obadaal-tarazi/Downloads/Sync%20Prestashop:AboutYou/schema.sql) (e.g. `export_material_composition`) and/or env defaults, emitting **warnings** when fallback is used.

### A3. Duplicate variant tuple detection + disambiguation

- Before building the final batch items in [`ProductSyncService`](file:///Users/obadaal-tarazi/Downloads/Sync%20Prestashop:AboutYou/ProductSyncService.php) / [`AboutYouMapper`](file:///Users/obadaal-tarazi/Downloads/Sync%20Prestashop:AboutYou/src/Integration/AboutYouMapper.php):
  - Detect collisions on `(style_key, color, size, second_size)`.
  - **Fix path**: implement **`second_size` resolution** analogous to color/size (new `map_type` + `AttributeTypeGuesser` tuning / explicit admin mapping for which PS group is “second size”).
  - If still colliding: fail with a **clear validation error listing SKUs/combination ids** (better than silent wrong merges).

---

## Phase B — Correctness fixes already identified in code review

- **Unify category/brand JSON map handling** so `{ "id": ... }` entries work in [`AboutYouMapper`](file:///Users/obadaal-tarazi/Downloads/Sync%20Prestashop:AboutYou/src/Integration/AboutYouMapper.php) the same way as [`public/api.php`](file:///Users/obadaal-tarazi/Downloads/Sync%20Prestashop:AboutYou/public/api.php).
- **Unify price math** between [`AboutYouMapper::mapVariant`](file:///Users/obadaal-tarazi/Downloads/Sync%20Prestashop:AboutYou/src/Integration/AboutYouMapper.php) and stock/price sync in [`ProductSyncService`](file:///Users/obadaal-tarazi/Downloads/Sync%20Prestashop:AboutYou/ProductSyncService.php).

---

## Phase C — Performance (directly addresses your slow combination step)

- **In-memory caches** for `product_option_values` / `product_options` in [`PrestaShopClient::loadCombinationAttributes`](file:///Users/obadaal-tarazi/Downloads/Sync%20Prestashop:AboutYou/src/Integration/PrestaShopClient.php) to remove repeated GETs.
- **Image normalization short-circuit** when unchanged (hash/ps image id) in [`ProductSyncService`](file:///Users/obadaal-tarazi/Downloads/Sync%20Prestashop:AboutYou/ProductSyncService.php) + [`ImageNormalizer`](file:///Users/obadaal-tarazi/Downloads/Sync%20Prestashop:AboutYou/src/Support/ImageNormalizer.php).
- **Tune hot-path DB logging** ([`DbSyncLogger`](file:///Users/obadaal-tarazi/Downloads/Sync%20Prestashop:AboutYou/DbSyncLogger.php) / progress updates) for bulk runs.

---

## Phase D — Tests + observability

- Extend [`tests/AboutYouMapperTest.php`](file:///Users/obadaal-tarazi/Downloads/Sync%20Prestashop:AboutYou/tests/AboutYouMapperTest.php) with cases covering:
  - required attribute merge,
  - composition text presence,
  - duplicate tuple detection.
- Add lightweight service-level tests for [`ProductSyncService`](file:///Users/obadaal-tarazi/Downloads/Sync%20Prestashop:AboutYou/ProductSyncService.php) push behavior.
- Use existing [`sync_runs`](file:///Users/obadaal-tarazi/Downloads/Sync%20Prestashop:AboutYou/schema.sql) / [`sync_logs`](file:///Users/obadaal-tarazi/Downloads/Sync%20Prestashop:AboutYou/schema.sql) for SLO queries (failure rate, p95 duration, validation spikes).

---

## Acceptance criteria (for your specific log)

- For a category like the one behind PS#72, a product cannot be pushed unless **all required attribute groups** are mapped (or explicitly filled via approved fallbacks with warnings).
- `material_composition_textile` is populated from PS where possible; otherwise fallback is visible in logs.
- No AboutYou “duplicate variant combination” without a deliberate `second_size` mapping or an explicit operator decision.
- Combination enrichment time drops materially on multi-combination products due to PS client caching.
