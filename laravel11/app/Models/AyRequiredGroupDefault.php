<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AyRequiredGroupDefault extends Model
{
    protected $fillable = ['ay_category_id','ay_group_id','ay_group_name','default_ay_id','default_label'];
}
