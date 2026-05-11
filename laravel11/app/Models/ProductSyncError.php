<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSyncError extends Model
{
    public $timestamps = false;
    protected $fillable = ['product_id','ps_id','run_id','phase','reason_code','error_message','error_details','created_at'];
    protected $casts = ['error_details' => 'array', 'created_at' => 'datetime'];
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
