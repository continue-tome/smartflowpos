<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Auditable, SoftDeletes;

    const AUDIT_MODULE = 'user';

    protected $fillable = [
        'restaurant_id', 'role_id', 'first_name', 'last_name',
        'email', 'password', 'pin', 'avatar', 'active', 'last_login_at'
    ];

    protected $hidden = ['password', 'pin', 'remember_token'];

    protected $casts = [
        'active'        => 'boolean',
        'last_login_at' => 'datetime',
    ];

    // Relations
    public function restaurant() { return $this->belongsTo(Restaurant::class); }
    public function role()       { return $this->belongsTo(Role::class); }

    // Vérifier une permission
    public function hasPermission(string $permission): bool
    {
        $permissions = $this->role->permissions ?? [];
        return in_array($permission, $permissions) || in_array('*', $permissions);
    }

    // Vérifier un rôle
    public function hasRole(string|array $roles): bool
    {
        $roleName = $this->role->name;
        return is_array($roles)
            ? in_array($roleName, $roles)
            : $roleName === $roles;
    }

    public function isManager(): bool
    {
        return $this->hasRole(['admin', 'manager']);
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }
}
