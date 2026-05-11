<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncCommandRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'command' => 'required|string|in:products,products:inc,stock,orders,order-status,all,retry,status',
            'since' => 'nullable|string',
            'ps_product_ids' => 'nullable|array',
            'ps_product_ids.*' => 'integer|min:1',
        ];
    }
}
