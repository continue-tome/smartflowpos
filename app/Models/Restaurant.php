<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

class Restaurant extends Model
{
    use Auditable;

    const AUDIT_MODULE = 'restaurant';

    protected $fillable = [
        'name', 'slug', 'logo', 'address', 'phone', 'email',
        'vat_number', 'currency', 'timezone', 'settings', 'active'
    ];

    protected $appends = ['logo_url'];

    protected $casts = [
        'settings' => 'json',
        'active'   => 'boolean'
    ];

    public function getLogoUrlAttribute()
    {
        if (!$this->logo) return null;
        if (str_starts_with($this->logo, 'http')) return $this->logo;
        return url('api/media/' . $this->logo);
    }

    public function users() { return $this->hasMany(User::class); }
    public function floors() { return $this->hasMany(Floor::class); }
}
