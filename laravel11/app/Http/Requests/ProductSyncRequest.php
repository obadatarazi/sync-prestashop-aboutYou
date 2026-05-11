<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ps_product_ids' => 'required|array|min:1',
            'ps_product_ids.*' => 'integer|min:1',
            'sync_command' => 'nullable|string|in:products,products:inc',
            'since' => 'nullable|string',
        ];
    }
}
