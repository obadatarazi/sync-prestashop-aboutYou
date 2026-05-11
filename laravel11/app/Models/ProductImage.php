<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImage extends Model
{
    protected $fillable = ['product_id','ps_image_id','source_url','local_path','public_url','width','height','file_size_bytes','status','error_message','position','processed_at'];
    protected $casts = ['width' => 'integer', 'height' => 'integer', 'file_size_bytes' => 'integer', 'processed_at' => 'datetime'];
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
