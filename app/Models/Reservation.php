<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

class Reservation extends Model
{
    use Auditable;

    const AUDIT_MODULE = 'reservation';

    protected $fillable = [
        'table_id', 'restaurant_id', 'customer_name', 'customer_phone',
        'covers', 'reserved_at', 'duration_minutes', 'status', 'notes'
    ];

    protected $casts = [
        'reserved_at' => 'datetime',
    ];

    public function table()      { return $this->belongsTo(Table::class); }
    public function restaurant() { return $this->belongsTo(Restaurant::class); }
}
