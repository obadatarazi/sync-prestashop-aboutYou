<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncLog extends Model
{
    public $timestamps = false;
    protected $fillable = ['run_id','level','channel','message','context','created_at'];
    protected $casts = ['context' => 'array', 'created_at' => 'datetime'];
}
