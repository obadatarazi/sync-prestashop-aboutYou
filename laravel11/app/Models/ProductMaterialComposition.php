<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductMaterialComposition extends Model
{
    protected $table = 'product_material_composition';
    protected $fillable = ['product_id','is_textile','cluster_id','cluster_label','ay_material_id','material_label','fraction'];
    protected $casts = ['is_textile' => 'boolean', 'fraction' => 'integer'];
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
