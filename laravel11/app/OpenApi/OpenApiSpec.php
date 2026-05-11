<?php

namespace App\OpenApi;

/**
 * @OA\Info(
 *     title="SyncBridge API",
 *     version="1.0.0",
 *     description="Production-grade API documentation for SyncBridge integration endpoints."
 * )
 *
 * @OA\Server(
 *     url=L5_SWAGGER_CONST_HOST,
 *     description="Primary API server"
 * )
 *
 * @OA\SecurityScheme(
 *     securityScheme="bearerAuth",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="Token",
 *     description="Paste SYNCBRIDGE_API_TOKEN as Bearer token."
 * )
 *
 * @OA\SecurityScheme(
 *     securityScheme="apiTokenHeader",
 *     type="apiKey",
 *     in="header",
 *     name="X-Api-Token",
 *     description="Alternative auth header for sync-protected endpoints."
 * )
 *
 * @OA\Tag(name="Products", description="Product listing and product details.")
 * @OA\Tag(name="Orders", description="Order listing and order details.")
 * @OA\Tag(name="Settings", description="SyncBridge settings management.")
 * @OA\Tag(name="Sync", description="Sync command execution endpoints.")
 * @OA\Tag(name="Legacy", description="Legacy compatibility action endpoint.")
 *
 * @OA\Schema(
 *     schema="ApiSuccessBase",
 *     type="object",
 *     required={"ok","message"},
 *     @OA\Property(property="ok", type="boolean", example=true),
 *     @OA\Property(property="message", type="string", example="ok")
 * )
 *
 * @OA\Schema(
 *     schema="ApiError",
 *     type="object",
 *     required={"ok","error","errors"},
 *     @OA\Property(property="ok", type="boolean", example=false),
 *     @OA\Property(property="error", type="string", example="Invalid payload"),
 *     @OA\Property(
 *         property="errors",
 *         type="object",
 *         additionalProperties=@OA\Schema(
 *             type="array",
 *             @OA\Items(type="string", example="The field is required.")
 *         )
 *     )
 * )
 *
 * @OA\Schema(
 *     schema="PaginationMeta",
 *     type="object",
 *     required={"total","page","per_page"},
 *     @OA\Property(property="total", type="integer", example=245),
 *     @OA\Property(property="page", type="integer", example=1),
 *     @OA\Property(property="per_page", type="integer", example=20)
 * )
 *
 * @OA\Schema(
 *     schema="Product",
 *     type="object",
 *     required={"id","ps_id","name","sync_status"},
 *     @OA\Property(property="id", type="integer", example=10),
 *     @OA\Property(property="ps_id", type="integer", example=12345),
 *     @OA\Property(property="name", type="string", example="Slim Fit Denim Jacket"),
 *     @OA\Property(property="reference", type="string", nullable=true, example="REF-123"),
 *     @OA\Property(property="sync_status", type="string", example="synced"),
 *     @OA\Property(property="sync_error", type="string", nullable=true, example=null),
 *     @OA\Property(property="ay_style_key", type="string", nullable=true, example="BY-STYLE-123"),
 *     @OA\Property(property="price", type="number", format="float", example=89.99),
 *     @OA\Property(property="updated_at", type="string", format="date-time", nullable=true)
 * )
 *
 * @OA\Schema(
 *     schema="ProductVariant",
 *     type="object",
 *     @OA\Property(property="id", type="integer", example=7),
 *     @OA\Property(property="product_id", type="integer", example=10),
 *     @OA\Property(property="ps_combo_id", type="integer", nullable=true, example=1001),
 *     @OA\Property(property="sku", type="string", nullable=true, example="SKU-BLACK-M"),
 *     @OA\Property(property="ean13", type="string", nullable=true, example="4006381333931"),
 *     @OA\Property(property="reference", type="string", nullable=true, example="REF-BLK-M"),
 *     @OA\Property(property="price_modifier", type="number", format="float", example=0),
 *     @OA\Property(property="weight", type="number", format="float", example=0.2),
 *     @OA\Property(property="quantity", type="integer", example=5),
 *     @OA\Property(property="color_id", type="integer", nullable=true, example=2),
 *     @OA\Property(property="size_id", type="integer", nullable=true, example=4),
 *     @OA\Property(property="ay_pushed", type="boolean", example=true)
 * )
 *
 * @OA\Schema(
 *     schema="ProductImage",
 *     type="object",
 *     @OA\Property(property="id", type="integer", example=3),
 *     @OA\Property(property="product_id", type="integer", example=10),
 *     @OA\Property(property="ps_image_id", type="integer", example=9001),
 *     @OA\Property(property="source_url", type="string", nullable=true, example="https://cdn.example.com/img/source.jpg"),
 *     @OA\Property(property="public_url", type="string", nullable=true, example="https://cdn.example.com/img/public.jpg"),
 *     @OA\Property(property="status", type="string", nullable=true, example="processed"),
 *     @OA\Property(property="error_message", type="string", nullable=true, example=null),
 *     @OA\Property(property="position", type="integer", nullable=true, example=1),
 *     @OA\Property(property="processed_at", type="string", format="date-time", nullable=true)
 * )
 *
 * @OA\Schema(
 *     schema="Order",
 *     type="object",
 *     required={"id","sync_status"},
 *     @OA\Property(property="id", type="integer", example=14),
 *     @OA\Property(property="ay_order_id", type="string", nullable=true, example="AY-ORDER-123"),
 *     @OA\Property(property="ps_order_id", type="integer", nullable=true, example=345),
 *     @OA\Property(property="sync_status", type="string", example="synced"),
 *     @OA\Property(property="ay_status", type="string", nullable=true, example="approved"),
 *     @OA\Property(property="total_paid", type="number", format="float", example=129.49),
 *     @OA\Property(property="created_at", type="string", format="date-time", nullable=true)
 * )
 *
 * @OA\Schema(
 *     schema="OrderItem",
 *     type="object",
 *     @OA\Property(property="id", type="integer", example=55),
 *     @OA\Property(property="order_id", type="integer", example=14),
 *     @OA\Property(property="ay_order_item_id", type="string", nullable=true, example="item-001"),
 *     @OA\Property(property="sku", type="string", nullable=true, example="SKU-TEE-M"),
 *     @OA\Property(property="ean13", type="string", nullable=true, example="4006381333931"),
 *     @OA\Property(property="product_id", type="integer", nullable=true, example=100),
 *     @OA\Property(property="combo_id", type="integer", nullable=true, example=200),
 *     @OA\Property(property="quantity", type="integer", example=2),
 *     @OA\Property(property="unit_price", type="number", format="float", example=19.99),
 *     @OA\Property(property="discount_amount", type="number", format="float", example=0),
 *     @OA\Property(property="item_status", type="string", nullable=true, example="shipped")
 * )
 *
 * @OA\Schema(
 *     schema="Setting",
 *     type="object",
 *     required={"key","value","type","group_name"},
 *     @OA\Property(property="key", type="string", example="sync.batch_size"),
 *     @OA\Property(property="value", type="string", nullable=true, example="100"),
 *     @OA\Property(property="type", type="string", nullable=true, example="int"),
 *     @OA\Property(property="label", type="string", nullable=true, example="Batch Size"),
 *     @OA\Property(property="group_name", type="string", example="sync"),
 *     @OA\Property(property="updated_at", type="string", format="date-time", nullable=true)
 * )
 *
 * @OA\Schema(
 *     schema="SyncCommandRequest",
 *     type="object",
 *     required={"command"},
 *     @OA\Property(
 *         property="command",
 *         type="string",
 *         enum={"products","products:inc","stock","orders","order-status","all","retry","status"},
 *         example="products"
 *     ),
 *     @OA\Property(property="since", type="string", nullable=true, example="2026-05-01T00:00:00Z"),
 *     @OA\Property(
 *         property="ps_product_ids",
 *         type="array",
 *         @OA\Items(type="integer", example=123)
 *     )
 * )
 *
 * @OA\Schema(
 *     schema="ProductSyncRequest",
 *     type="object",
 *     required={"ps_product_ids"},
 *     @OA\Property(
 *         property="ps_product_ids",
 *         type="array",
 *         @OA\Items(type="integer", minimum=1, example=92)
 *     ),
 *     @OA\Property(
 *         property="sync_command",
 *         type="string",
 *         enum={"products","products:inc"},
 *         example="products:inc"
 *     ),
 *     @OA\Property(property="since", type="string", nullable=true, example="2026-05-01 00:00:00")
 * )
 *
 * @OA\Schema(
 *     schema="SettingsSaveRequest",
 *     type="object",
 *     @OA\Property(
 *         property="settings",
 *         type="object",
 *         additionalProperties=true,
 *         example={"sync.batch_size":100,"sync.mode":"incremental"}
 *     )
 * )
 */
class OpenApiSpec
{
}
