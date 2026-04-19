<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

class Ingredient extends Model
{
    use Auditable;

    const AUDIT_MODULE = 'stock';

    protected $fillable = [
        'restaurant_id', 'name', 'unit', 'quantity', 'min_quantity',
        'cost_per_unit', 'category', 'supplier', 'active'
    ];

    protected $casts = ['active' => 'boolean'];

    public function restaurant() { return $this->belongsTo(Restaurant::class); }
    public function recipes()    { return $this->hasMany(Recipe::class); }
    public function movements()  { return $this->hasMany(StockMovement::class); }
}
