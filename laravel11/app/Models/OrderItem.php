<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    public $timestamps = false;
    protected $fillable = ['order_id','ay_order_item_id','sku','ean13','product_id','combo_id','quantity','unit_price','discount_amount','item_status'];
    protected $casts = ['quantity' => 'integer', 'unit_price' => 'float', 'discount_amount' => 'float'];
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
}
