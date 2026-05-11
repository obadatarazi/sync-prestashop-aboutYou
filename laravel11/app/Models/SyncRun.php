<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncRun extends Model
{
    public $timestamps = false;
    protected $fillable = ['run_id','command','status','total_items','done_items','pushed','skipped','failed','current_product_id','current_phase','last_message','started_at','finished_at','elapsed_sec'];
    protected $casts = ['started_at' => 'datetime', 'finished_at' => 'datetime', 'elapsed_sec' => 'float'];
}
