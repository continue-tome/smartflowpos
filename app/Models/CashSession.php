<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

class CashSession extends Model
{
    use Auditable;

    const AUDIT_MODULE = 'cash';

    protected $fillable = [
        'restaurant_id', 'user_id', 'opening_amount', 'closing_amount',
        'expected_amount', 'difference', 'closing_notes', 'opened_at', 'closed_at',
        'cash_total', 'card_total', 'wave_total', 'orange_money_total',
        'momo_total', 'other_total', 'total_expenses',
        'report_sent_at', 'report_email',
        'amount_to_bank', 'remaining_amount',
    ];

    protected $casts = [
        'opening_amount'      => 'decimal:2',
        'closing_amount'      => 'decimal:2',
        'expected_amount'     => 'decimal:2',
        'difference'          => 'decimal:2',
        'cash_total'          => 'decimal:2',
        'card_total'          => 'decimal:2',
        'wave_total'          => 'decimal:2',
        'orange_money_total'  => 'decimal:2',
        'momo_total'          => 'decimal:2',
        'other_total'         => 'decimal:2',
        'total_expenses'      => 'decimal:2',
        'amount_to_bank'      => 'decimal:2',
        'remaining_amount'    => 'decimal:2',
        'opened_at'           => 'datetime',
        'closed_at'           => 'datetime',
        'report_sent_at'      => 'datetime',
    ];

    public function restaurant() { return $this->belongsTo(Restaurant::class); }
    public function user()       { return $this->belongsTo(User::class); }
    public function payments()   { return $this->hasMany(Payment::class); }
    public function expenses()   { return $this->hasMany(Expense::class); }
}
