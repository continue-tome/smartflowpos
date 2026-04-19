<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

class Table extends Model
{
    use Auditable;

    const AUDIT_MODULE = 'table';

    protected $fillable = [
        'floor_id', 'number', 'capacity', 'status',
        'assigned_user_id', 'position_x', 'position_y',
        'width', 'height', 'shape', 'occupied_since', 'active'
    ];

    protected $casts = [
        'occupied_since' => 'datetime',
        'active'         => 'boolean'
    ];

    public function floor()        { return $this->belongsTo(Floor::class); }
    public function assignedUser() { return $this->belongsTo(User::class, 'assigned_user_id'); }
    public function reservations(){ return $this->hasMany(Reservation::class); }
    public function orders()       { return $this->hasMany(Order::class); }

    public function currentOrder()
    {
        return $this->hasOne(Order::class)->whereIn('status', ['open', 'sent_to_kitchen', 'partially_served', 'served'])->latest('created_at');
    }
}
