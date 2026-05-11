<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = ['ay_order_id','ps_order_id','customer_email','customer_name','total_paid','total_products','total_shipping','discount_total','currency','shipping_country_iso','billing_country_iso','shipping_method','payment_method','shipping_address_json','billing_address_json','ay_status','ps_state_id','sync_status','sync_attempts','is_permanent_failure','error_message','ay_created_at','last_synced_at'];
    protected $casts = ['total_paid' => 'float','total_products' => 'float','total_shipping' => 'float','discount_total' => 'float','is_permanent_failure' => 'boolean','ay_created_at' => 'datetime','last_synced_at' => 'datetime'];
    public function items(): HasMany { return $this->hasMany(OrderItem::class); }
}
