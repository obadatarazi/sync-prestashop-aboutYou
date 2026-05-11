<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariant extends Model
{
    protected $fillable = ['product_id','ps_combo_id','sku','ean13','reference','price_modifier','weight','quantity','color_id','size_id','ay_pushed'];
    protected $casts = ['price_modifier' => 'float', 'weight' => 'float', 'quantity' => 'integer', 'ay_pushed' => 'boolean'];
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
