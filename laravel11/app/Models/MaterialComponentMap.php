<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaterialComponentMap extends Model
{
    protected $fillable = ['ps_label','ay_material_id','ay_material_label','is_textile'];
    protected $casts = ['is_textile' => 'boolean'];
}
