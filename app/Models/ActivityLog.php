<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    protected $fillable = [
        'restaurant_id', 'user_id', 'action', 'module',
        'subject_type', 'subject_id', 'description',
        'old_values', 'new_values', 'reason',
        'ip_address', 'user_agent'
    ];

    protected $casts = [
        'old_values' => 'json',
        'new_values' => 'json',
    ];

    protected $appends = ['created_at_human'];

    public function getCreatedAtHumanAttribute()
    {
        return $this->created_at ? $this->created_at->diffForHumans() : 'Maintenant';
    }

    public function restaurant() { return $this->belongsTo(Restaurant::class); }
    public function user()       { return $this->belongsTo(User::class); }
    public function subject()    { return $this->morphTo(); }
}
