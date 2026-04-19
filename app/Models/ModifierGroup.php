<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModifierGroup extends Model
{
    protected $fillable = [
        'product_id', 'name', 'required', 'multiple',
        'min_selections', 'max_selections', 'order'
    ];

    protected $casts = [
        'required' => 'boolean',
        'multiple' => 'boolean'
    ];

    public function product()   { return $this->belongsTo(Product::class); }
    public function modifiers() { return $this->hasMany(Modifier::class); }
}
