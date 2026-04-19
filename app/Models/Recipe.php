<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Recipe extends Model
{
    protected $fillable = ['product_id', 'ingredient_id', 'quantity'];

    public function product()    { return $this->belongsTo(Product::class); }
    public function ingredient() { return $this->belongsTo(Ingredient::class); }
}
