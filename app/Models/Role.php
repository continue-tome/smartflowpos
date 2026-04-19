<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use Auditable;

    const AUDIT_MODULE = 'role';

    protected $fillable = [
        'restaurant_id', 'name', 'display_name', 'permissions', 'is_system'
    ];

    protected $casts = [
        'permissions' => 'json',
        'is_system'   => 'boolean'
    ];

    public function restaurant() { return $this->belongsTo(Restaurant::class); }
    public function users()      { return $this->hasMany(User::class); }
}
