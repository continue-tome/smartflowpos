<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CakeOrder extends Model
{
    use Auditable, SoftDeletes;

    const AUDIT_MODULE = 'cake_order';

    protected $fillable = [
        'restaurant_id', 'user_id', 'order_number',
        'customer_name', 'customer_phone',
        'items', 'total', 'advance_paid', 'remaining_amount',
        'delivery_date', 'delivery_time',
        'status', 'is_paid', 'paid_at',
        'payment_method', 'payment_reference', 'notes',
    ];

    protected $casts = [
        'items'           => 'array',
        'total'           => 'decimal:2',
        'advance_paid'    => 'decimal:2',
        'remaining_amount'=> 'decimal:2',
        'delivery_date'   => 'date',
        'is_paid'         => 'boolean',
        'paid_at'         => 'datetime',
    ];

    public function restaurant() { return $this->belongsTo(Restaurant::class); }
    public function cashier()    { return $this->belongsTo(User::class, 'user_id'); }

    // Générer numéro de commande gâteau
    public static function generateNumber(int $restaurantId): string
    {
        $today = now()->format('Ymd');
        $count = static::where('restaurant_id', $restaurantId)
            ->whereDate('created_at', today())
            ->count() + 1;
        return "CAKE-{$today}-" . str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    // Calculer le restant
    public function updateRemaining(): void
    {
        $this->update(['remaining_amount' => max(0, $this->total - $this->advance_paid)]);
    }
}
