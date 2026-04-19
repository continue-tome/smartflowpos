<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

class Delivery extends Model
{
    use Auditable;

    const AUDIT_MODULE = 'delivery';

    protected $fillable = [
        'order_id', 'driver_id', 'restaurant_id', 'customer_name',
        'customer_phone', 'address', 'lat', 'lng', 'status',
        'delivery_fee', 'notes', 'estimated_at', 'picked_up_at', 'delivered_at'
    ];

    protected $casts = [
        'estimated_at'  => 'datetime',
        'picked_up_at'  => 'datetime',
        'delivered_at'  => 'datetime',
    ];

    public function order()      { return $this->belongsTo(Order::class); }
    public function driver()     { return $this->belongsTo(User::class, 'driver_id'); }
    public function restaurant() { return $this->belongsTo(Restaurant::class); }
}
