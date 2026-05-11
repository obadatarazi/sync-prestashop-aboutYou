<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AyPolicySnapshot extends Model
{
    public $timestamps = false;
    protected $fillable = ['source','version_tag','payload_json','created_at'];
    protected $casts = ['payload_json' => 'array', 'created_at' => 'datetime'];
}
