<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    protected $fillable = [
        'restaurant_id', 'ingredient_id', 'user_id', 'order_id',
        'type', 'quantity', 'quantity_before', 'quantity_after',
        'unit_cost', 'reason', 'reference'
    ];

    public function restaurant() { return $this->belongsTo(Restaurant::class); }
    public function ingredient() { return $this->belongsTo(Ingredient::class); }
    public function user()       { return $this->belongsTo(User::class); }
    public function order()      { return $this->belongsTo(Order::class); }
}
