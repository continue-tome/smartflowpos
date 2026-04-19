<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

class CustomerTab extends Model
{
    use Auditable;

    const AUDIT_MODULE = 'customer_tab';

    protected $fillable = [
        'restaurant_id', 'created_by', 'last_name', 'first_name', 'phone',
        'notes', 'total_amount', 'paid_amount', 'status',
        'opened_at', 'closed_at', 'paid_at',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'paid_amount'  => 'decimal:2',
        'opened_at'    => 'datetime',
        'closed_at'    => 'datetime',
        'paid_at'      => 'datetime',
    ];

    public function restaurant() { return $this->belongsTo(Restaurant::class); }
    public function creator()    { return $this->belongsTo(User::class, 'created_by'); }
    public function tabOrders()  { return $this->hasMany(CustomerTabOrder::class); }
    public function orders()     { return $this->belongsToMany(Order::class, 'customer_tab_orders'); }

    // Montant restant à payer
    public function remainingAmount(): float
    {
        return max(0, $this->total_amount - $this->paid_amount);
    }

    // Recalculer le total depuis les commandes liées
    public function recalculate(): void
    {
        $total = $this->orders()->sum('total');
        $this->update(['total_amount' => $total]);
    }

    // Nom complet du client
    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }
}
