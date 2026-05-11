<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncMetric extends Model
{
    public $timestamps = false;
    protected $fillable = ['run_id','command','phase','metric_key','metric_value','meta_json','created_at'];
    protected $casts = ['metric_value' => 'float', 'meta_json' => 'array', 'created_at' => 'datetime'];
}
