<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use SoftDeletes;

    protected $fillable = ['restaurant_id', 'name', 'phone', 'email', 'address', 'notes'];

    public function restaurant() { return $this->belongsTo(Restaurant::class); }
}
