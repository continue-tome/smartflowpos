<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComboMenu extends Model
{
    protected $fillable = ['restaurant_id', 'name', 'description', 'price', 'active'];

    protected $casts = ['active' => 'boolean'];

    public function restaurant() { return $this->belongsTo(Restaurant::class); }
    public function items()      { return $this->hasMany(ComboMenuItem::class); }
}
