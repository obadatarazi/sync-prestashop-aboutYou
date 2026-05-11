<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'ps_id', 'ay_style_key', 'reference', 'name', 'description', 'description_short',
        'export_title', 'export_description', 'export_material_composition', 'ps_api_payload',
        'ay_manual_required_attributes_json', 'ay_missing_payload_json', 'price', 'weight',
        'ean13', 'category_ps_id', 'category_name', 'ay_category_id', 'ay_category_path', 'ay_brand_id',
        'country_of_origin', 'active', 'sync_status', 'sync_error', 'last_synced_at', 'ps_updated_at',
    ];

    protected $casts = [
        'price' => 'float',
        'weight' => 'float',
        'active' => 'boolean',
        'last_synced_at' => 'datetime',
        'ps_updated_at' => 'datetime',
    ];

    public function variants(): HasMany { return $this->hasMany(ProductVariant::class); }
    public function images(): HasMany { return $this->hasMany(ProductImage::class); }
    public function syncErrors(): HasMany { return $this->hasMany(ProductSyncError::class); }
    public function materialCompositions(): HasMany { return $this->hasMany(ProductMaterialComposition::class); }
}
