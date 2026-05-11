/** Laravel ApiResponseTrait envelope */
export type ApiSuccess<T extends Record<string, unknown> = Record<string, never>> = {
  ok: true
  message?: string
} & T

export type ApiErrorBody = {
  ok: false
  error: string
  errors?: Record<string, string[]>
}

export type ProductSyncStatus =
  | 'synced'
  | 'pending'
  | 'error'
  | 'syncing'
  | 'quarantined'

export type ProductRow = {
  id: number
  ps_id: number
  name: string
  reference: string
  sync_status: ProductSyncStatus
  sync_error: string | null
  ay_style_key: string | null
  /** Per-product About You category override (optional on older API responses) */
  ay_category_id?: number | null
  ay_category_path?: string | null
  price: number
  updated_at: string | null
}

export type ProductsListResponse = ApiSuccess<{
  total: number
  page: number
  per_page: number
  rows: ProductRow[]
}>

export type ProductVariantRow = {
  id: number
  product_id: number
  ps_combo_id: number | null
  sku: string | null
  ean13: string | null
  reference: string | null
  price_modifier: number
  weight: number
  quantity: number
  color_id: number | null
  size_id: number | null
  ay_pushed: boolean
}

export type ProductImageRow = {
  id: number
  product_id: number
  ps_image_id: number | null
  source_url: string | null
  local_path: string | null
  public_url: string | null
  width: number | null
  height: number | null
  file_size_bytes: number | null
  status: string | null
  error_message: string | null
  position: number | null
  processed_at: string | null
}

export type ProductSyncErrorRow = {
  id: number
  product_id: number
  ps_id: number | null
  run_id: string | null
  phase: string | null
  reason_code: string | null
  error_message: string | null
  error_details: Record<string, unknown> | null
  created_at: string | null
}

export type ProductDetailResponse = ApiSuccess<{
  product: ProductRow & {
    last_synced_at?: string | null
    ps_updated_at?: string | null
    export_title?: string | null
    export_description?: string | null
    export_material_composition?: string | null
    ay_brand_id?: number | null
    ay_manual_required_attributes_json?: string | null
    ay_missing_payload_json?: string | null
  }
  variants: ProductVariantRow[]
  images: ProductImageRow[]
  /** Optional when backend includes relation in JSON */
  sync_errors?: ProductSyncErrorRow[]
}>

export type ProductDraftUpdateRequest = {
  export_title?: string | null
  export_description?: string | null
  export_material_composition?: string | null
  ay_category_id?: number | null
  ay_category_path?: string | null
  ay_brand_id?: number | null
  ay_manual_required_attributes_json?: string | null
}

export type ProductDraftUpdateResponse = ApiSuccess<{
  product: ProductDetailResponse['product']
}>

export type ProductPayloadPreviewResponse = ApiSuccess<{
  ready: boolean
  payload: Record<string, unknown> | null
  errors: string[]
}>

export type ProductRefetchResponse = ApiSuccess<{
  updated: number
  requested: number
  not_found_ids: number[]
}>

export type OrderRow = {
  id: number
  ay_order_id: string
  ps_order_id: number | null
  sync_status: string
  ay_status: string | null
  customer_email?: string | null
  customer_name?: string | null
  total_paid: number
  total_products?: number
  total_shipping?: number
  discount_total?: number
  currency?: string | null
  shipping_country_iso?: string | null
  billing_country_iso?: string | null
  shipping_method?: string | null
  payment_method?: string | null
  shipping_address_json?: string | null
  billing_address_json?: string | null
  error_message?: string | null
  created_at: string | null
}

export type OrdersListResponse = ApiSuccess<{
  total: number
  page: number
  per_page: number
  rows: OrderRow[]
}>

export type OrderItemRow = {
  id: number
  order_id: number
  ay_order_item_id: string | null
  sku: string | null
  ean13: string | null
  product_id: number | null
  combo_id: number | null
  quantity: number
  unit_price: number
  discount_amount: number
  item_status: string | null
}

export type OrderDetailResponse = ApiSuccess<{
  order: OrderRow
  items: OrderItemRow[]
}>

export type OrderRepushResponse = ApiSuccess<{
  order: OrderRow
  items: OrderItemRow[]
  stock_sync: Record<string, unknown> | null
}>

export type OrderRefetchResponse = ApiSuccess<{
  updated: number
  requested: number
  failed: number
  failed_ids: string[]
  since: string | null
}>

export type OrderUpdateRequest = {
  customer_email?: string
  customer_name?: string
  total_paid?: number
  total_products?: number
  total_shipping?: number
  discount_total?: number
  currency?: string
  shipping_country_iso?: string
  billing_country_iso?: string
  shipping_method?: string
  payment_method?: string
  shipping_address_json?: string
  billing_address_json?: string
  ay_status?: string
  sync_status?: string
  error_message?: string
}

export type SettingRow = {
  key: string
  value: string | null
  type: string
  label: string | null
  group_name: string
  updated_at: string | null
}

export type SettingsListResponse = ApiSuccess<{
  rows: SettingRow[]
}>

export type SettingsSaveResponse = ApiSuccess<{
  saved: string[]
}>

export type MappingCategoryRow = {
  ps_category_id: number
  ps_category_name: string
  product_count: number
  ay_category_id: number | null
  ay_category_path: string | null
}

export type MappingCategoriesResponse = ApiSuccess<{
  rows: MappingCategoryRow[]
}>

export type MappingSaveResponse = ApiSuccess<{
  saved: number
}>

export type AyCategorySearchItem = {
  id: number
  name: string
  path: string
  /** Present when API returns hierarchy metadata */
  parent_id?: number | null
}

export type AyCategorySearchResponse = ApiSuccess<{
  items: AyCategorySearchItem[]
}>

export type MappingsOverviewResponse = ApiSuccess<{
  attribute_maps_count: number
  material_component_maps_count: number
  material_cluster_maps_count: number
  required_group_defaults_count: number
}>

export type SyncCommand =
  | 'products'
  | 'products:inc'
  | 'stock'
  | 'orders'
  | 'order-status'
  | 'all'
  | 'retry'
  | 'status'

export type SyncRunRequest = {
  command: SyncCommand
  since?: string | null
  ps_product_ids?: number[]
}

export type SyncRunResponse = ApiSuccess<{
  run_id: string | number | null
  result: Record<string, unknown>
}>

export type SyncProductsRequest = {
  ps_product_ids: number[]
  sync_command?: 'products' | 'products:inc'
  since?: string | null
}

export type StatusSnapshot = {
  database?: { products: number; orders: number; sync_runs: number }
  database_error?: string
  api?: { prestashop: string; aboutyou: string }
  flags?: { dry_run: boolean; test_mode: boolean }
  message?: string
}

export type ImageDiagnosticsSummary = {
  products_without_image_rows: number
  products_with_images_but_no_ok: number
  products_missing_usable_images: number
  images_not_ay_ready: number
  images_duplicate_source_rows: number
  images_duplicate_local_path_rows: number
  images_duplicate_rows_union: number
  images_failed: number
  images_pending: number
  images_processing: number
  images_ok: number
  image_rows_total: number
  normalization_available: boolean
}

export type ImageDiagnosticsProductSample = {
  id: number
  ps_id: number
  name: string | null
}

export type ImageDiagnosticsSamples = {
  products_without_image_rows: ImageDiagnosticsProductSample[]
  products_with_images_but_no_ok: ImageDiagnosticsProductSample[]
  top_duplicate_sources: { source_url: string; count: number }[]
  top_duplicate_local_paths: { local_path: string; count: number }[]
}

export type ImageDiagnosticsResponse = ApiSuccess<{
  summary: ImageDiagnosticsSummary
  samples?: ImageDiagnosticsSamples
}>

export type ImageGalleryProduct = {
  id: number
  ps_id: number
  name: string | null
  reference: string | null
}

export type ImageGalleryImage = {
  id: number
  product_id: number
  ps_image_id: string | null
  source_url: string
  local_path: string | null
  public_url: string | null
  width: number | null
  height: number | null
  status: string
  error_message: string | null
  position: number
  processed_at: string | null
}

export type ImageGalleryFlags = {
  no_images: boolean
  has_error: boolean
  has_pending: boolean
  not_ay_ready: boolean
  needs_attention: boolean
}

export type ImageGalleryRow = {
  product: ImageGalleryProduct
  images: ImageGalleryImage[]
  flags: ImageGalleryFlags
}

export type ImageGalleryResponse = ApiSuccess<{
  filter: string
  normalization_available: boolean
  rows: ImageGalleryRow[]
}>

export type NormalizeImagesResultRow = {
  product_id: number
  ok: boolean
  normalized?: number
  failed?: number
  skipped?: number
  error?: string
  message?: string
}

export type NormalizeImagesResponse = ApiSuccess<{
  totals: { normalized: number; failed: number; skipped: number; errors: number }
  results: NormalizeImagesResultRow[]
}>
