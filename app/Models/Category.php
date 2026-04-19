<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use Auditable;

    const AUDIT_MODULE = 'category';

    protected $fillable = ['restaurant_id', 'parent_id', 'name', 'image', 'order', 'active', 'destination', 'color'];

    protected $casts = [
        'active' => 'boolean',
    ];

    // Labels destination
    public static function destinationLabels(): array
    {
        return [
            'kitchen' => 'Cuisine 🍳',
            'bar'     => 'Bar 🍺',
            'pizza'   => 'Pizza 🍕',
        ];
    }

    // Couleurs par défaut si non renseignées
    public static function destinationColors(): array
    {
        return [
            'kitchen' => '#FF6B35',
            'bar'     => '#2196F3',
            'pizza'   => '#E53935',
        ];
    }

    public function restaurant() { return $this->belongsTo(Restaurant::class); }
    public function parent()     { return $this->belongsTo(Category::class, 'parent_id'); }
    public function children()   { return $this->hasMany(Category::class, 'parent_id'); }
    public function products()   { return $this->hasMany(Product::class); }
}
