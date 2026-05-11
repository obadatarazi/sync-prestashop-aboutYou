<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RetryJob extends Model
{
    protected $fillable = ['job_type','entity_key','payload_json','last_error','attempts','next_retry_at','status'];
    protected $casts = ['payload_json' => 'array', 'next_retry_at' => 'datetime'];
}
