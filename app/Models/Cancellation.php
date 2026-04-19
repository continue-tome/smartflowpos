<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

class Cancellation extends Model
{
    use Auditable;

    const AUDIT_MODULE = 'cancellation';

    protected $fillable = [
        'restaurant_id', 'cancellable_type', 'cancellable_id',
        'requested_by', 'approved_by', 'status', 'reason', 'notes',
        'refund_amount', 'refund_method', 'requested_at', 'approved_at'
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'approved_at'  => 'datetime',
    ];

    public function cancellable() { return $this->morphTo(); }
    public function requester()   { return $this->belongsTo(User::class, 'requested_by'); }
    public function approver()    { return $this->belongsTo(User::class, 'approved_by'); }
    public function restaurant()  { return $this->belongsTo(Restaurant::class); }
}
