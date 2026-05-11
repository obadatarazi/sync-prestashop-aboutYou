<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ay_order_id' => $this->ay_order_id,
            'ps_order_id' => $this->ps_order_id,
            'sync_status' => $this->sync_status,
            'ay_status' => $this->ay_status,
            'customer_email' => $this->customer_email,
            'customer_name' => $this->customer_name,
            'total_paid' => (float) ($this->total_paid ?? 0),
            'total_products' => (float) ($this->total_products ?? 0),
            'total_shipping' => (float) ($this->total_shipping ?? 0),
            'discount_total' => (float) ($this->discount_total ?? 0),
            'currency' => $this->currency,
            'shipping_country_iso' => $this->shipping_country_iso,
            'billing_country_iso' => $this->billing_country_iso,
            'shipping_method' => $this->shipping_method,
            'payment_method' => $this->payment_method,
            'shipping_address_json' => $this->shipping_address_json,
            'billing_address_json' => $this->billing_address_json,
            'error_message' => $this->error_message,
            'created_at' => optional($this->created_at)?->toISOString(),
        ];
    }
}
