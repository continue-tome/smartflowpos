<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use Auditable, SoftDeletes;

    const AUDIT_MODULE = 'payment';

    protected $fillable = [
        'order_id', 'cake_order_id', 'cash_session_id', 'user_id', 'amount',
        'method', 'reference', 'amount_given', 'change_given', 'is_partial'
    ];

    protected $casts = ['is_partial' => 'boolean'];

    public function order()       { return $this->belongsTo(Order::class); }
    public function cakeOrder()   { return $this->belongsTo(CakeOrder::class); }
    public function cashSession() { return $this->belongsTo(CashSession::class); }
    public function user()        { return $this->belongsTo(User::class); }
}
