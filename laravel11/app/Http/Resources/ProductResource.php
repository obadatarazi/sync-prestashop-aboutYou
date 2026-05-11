<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ps_id' => $this->ps_id,
            'name' => $this->name,
            'reference' => $this->reference,
            'sync_status' => $this->sync_status,
            'sync_error' => $this->sync_error,
            'ay_style_key' => $this->ay_style_key,
            'price' => (float) $this->price,
            'export_title' => $this->export_title,
            'export_description' => $this->export_description,
            'export_material_composition' => $this->export_material_composition,
            'ay_category_id' => $this->ay_category_id,
            'ay_category_path' => $this->ay_category_path,
            'ay_brand_id' => $this->ay_brand_id,
            'ay_manual_required_attributes_json' => $this->ay_manual_required_attributes_json,
            'ay_missing_payload_json' => $this->ay_missing_payload_json,
            'updated_at' => optional($this->updated_at)?->toISOString(),
        ];
    }
}
