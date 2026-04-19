<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrderItem extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'order_id', 'product_id', 'combo_menu_id', 'quantity',
        'unit_price', 'subtotal', 'status', 'notes', 'course',
        'sent_at', 'prepared_at', 'served_at'
    ];

    protected $casts = [
        'sent_at'     => 'datetime',
        'prepared_at' => 'datetime',
        'served_at'   => 'datetime',
    ];

    public function order()     { return $this->belongsTo(Order::class); }
    public function product()   { return $this->belongsTo(Product::class); }
    public function modifiers() { return $this->hasMany(OrderItemModifier::class); }
    public function cancellations(){ return $this->morphMany(Cancellation::class, 'cancellable'); }
}
