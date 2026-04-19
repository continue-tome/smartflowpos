<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    use Auditable;

    const AUDIT_MODULE = 'expense';

    protected $fillable = [
        'restaurant_id', 'cash_session_id', 'user_id',
        'category', 'description', 'amount',
        'payment_method', 'receipt_ref', 'notes',
        'beneficiary', 'agent_name'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function restaurant()   { return $this->belongsTo(Restaurant::class); }
    public function cashSession()  { return $this->belongsTo(CashSession::class); }
    public function user()         { return $this->belongsTo(User::class); }

    // Labels lisibles pour les catégories
    public static function categoryLabels(): array
    {
        return [
            'food_supply'  => 'Approvisionnement',
            'equipment'    => 'Équipement / Matériel',
            'fuel'         => 'Carburant',
            'salary'       => 'Salaire avancé',
            'maintenance'  => 'Maintenance',
            'cleaning'     => 'Nettoyage',
            'other'        => 'Autre',
        ];
    }
}
