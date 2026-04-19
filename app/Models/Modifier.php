<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Modifier extends Model
{
    protected $fillable = ['modifier_group_id', 'name', 'extra_price', 'active', 'order'];

    public function group() { return $this->belongsTo(ModifierGroup::class, 'modifier_group_id'); }
}
