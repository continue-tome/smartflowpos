<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

class Floor extends Model
{
    use Auditable;

    const AUDIT_MODULE = 'floor';

    protected $fillable = ['restaurant_id', 'name', 'order', 'active'];

    public function restaurant() { return $this->belongsTo(Restaurant::class); }
    public function tables()     { return $this->hasMany(Table::class); }
}
