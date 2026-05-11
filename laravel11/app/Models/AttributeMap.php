<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttributeMap extends Model
{
    public $timestamps = false;
    protected $fillable = ['map_type','ps_label','ay_group_id','ay_group_name','ay_id','created_at'];
}
