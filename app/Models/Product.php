<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use Auditable, SoftDeletes;

    const AUDIT_MODULE = 'product';

    protected $fillable = [
        'restaurant_id', 'category_id', 'name', 'description', 'image', 'emoji',
        'price', 'cost_price', 'vat_rate', 'sku', 'available',
        'track_stock', 'quantity', 'min_quantity', 'order', 'active'
    ];

    protected $casts = [
        'available'   => 'boolean',
        'track_stock' => 'boolean',
        'quantity'    => 'decimal:3',
        'min_quantity'=> 'decimal:3',
        'active'      => 'boolean'
    ];

    protected $appends = ['image_url'];

    public function getImageUrlAttribute()
    {
        return $this->image ? url('api/media/' . $this->image) : null;
    }

    public function restaurant()     { return $this->belongsTo(Restaurant::class); }
    public function category()       { return $this->belongsTo(Category::class); }
    public function modifierGroups() { return $this->hasMany(ModifierGroup::class); }
    public function recipes()        { return $this->hasMany(Recipe::class); }
}
