<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Order extends Model
{
    use Auditable, SoftDeletes;

    const AUDIT_MODULE = 'order';

    protected $fillable = [
        'restaurant_id', 'table_id', 'user_id', 'cashier_id',
        'order_number', 'type', 'status', 'covers',
        'customer_name', 'customer_phone',
        'subtotal', 'discount_amount', 'discount_reason',
        'vat_rate', 'vat_amount', 'total', 'notes',
        'sent_to_kitchen_at', 'served_at', 'paid_at',
    ];

    protected $casts = [
        'subtotal'        => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'vat_rate'        => 'decimal:2',
        'vat_amount'      => 'decimal:2',
        'total'           => 'decimal:2',
        'sent_to_kitchen_at' => 'datetime',
        'served_at'       => 'datetime',
        'paid_at'         => 'datetime',
    ];

    // Relations
    public function restaurant()   { return $this->belongsTo(Restaurant::class); }
    public function table()        { return $this->belongsTo(Table::class); }
    public function waiter()       { return $this->belongsTo(User::class, 'user_id'); }
    public function cashier()      { return $this->belongsTo(User::class, 'cashier_id'); }
    public function items()        { return $this->hasMany(OrderItem::class); }
    public function payments()     { return $this->hasMany(Payment::class); }
    public function logs()         { return $this->hasMany(OrderLog::class)->latest(); }
    public function delivery()     { return $this->hasOne(Delivery::class); }
    public function cancellations(){ return $this->morphMany(Cancellation::class, 'cancellable'); }
    public function customerTabs() { return $this->belongsToMany(CustomerTab::class, 'customer_tab_orders'); }

    // Scopes
    public function scopeOpen($q)   { return $q->whereIn('status', ['open', 'sent_to_kitchen', 'partially_served', 'served']); }
    public function scopeToday($q)  { return $q->whereDate('created_at', today()); }
    public function scopePaid($q)   { return $q->where('status', 'paid'); }

    // Calculer et mettre à jour les totaux (Mode TTC)
    public function recalculate(): void
    {
        $subtotal = $this->items()
            ->whereNotIn('status', ['cancelled'])
            ->sum(DB::raw('unit_price * quantity'));

        $vatRate = $this->vat_rate ?: ($this->restaurant->settings['default_vat_rate'] ?? 18);
        
        // Mode TTC : Les prix incluent la TVA. 
        // Total = Sous-total - Remise
        $total = max(0, (float) $subtotal - (float) $this->discount_amount);
        
        // Extraction de la TVA du Total
        $vatAmount = 0;
        if ($vatRate > 0) {
            $vatAmount = (float) round($total - ($total / (1 + ($vatRate / 100))), 2);
        }
        
        $this->update([
            'subtotal'   => $subtotal, // Subtotal est TTC
            'vat_rate'   => $vatRate,
            'vat_amount' => $vatAmount,
            'total'      => $total, // Total à payer est exactement le TTC
        ]);
    }

    // Montant déjà payé
    public function amountPaid(): float
    {
        return (float) $this->payments()->sum('amount');
    }

    // Reste à payer
    public function amountDue(): float
    {
        return max(0, $this->total - $this->amountPaid());
    }

    // Générer numéro de commande
    public static function generateNumber(int $restaurantId): string
    {
        $today = now()->format('Ymd');
        $count = static::where('restaurant_id', $restaurantId)
            ->whereDate('created_at', today())
            ->count() + 1;
        return "ORD-{$today}-" . str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Enregistrer une activité pour cette commande
     */
    public function logActivity(string $action, string $message, array $meta = [], ?int $userId = null): OrderLog
    {
        $userId = $userId ?? \Illuminate\Support\Facades\Auth::id();
        
        $orderLog = $this->logs()->create([
            'user_id' => $userId,
            'action'  => $action,
            'message' => $message,
            'meta'    => $meta,
        ]);

        \App\Models\ActivityLog::create([
            'restaurant_id' => $this->restaurant_id,
            'user_id'       => $userId,
            'action'        => $action,
            'module'        => 'pos_order',
            'subject_type'  => self::class,
            'subject_id'    => $this->id,
            'description'   => $message,
            'ip_address'    => \Illuminate\Support\Facades\Request::ip() ?? '127.0.0.1',
        ]);

        return $orderLog;
    }
}
