<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function create(User $user): bool { return $user->isManager(); }

    public function update(User $user, User $target): bool
    {
        if ($user->restaurant_id !== $target->restaurant_id) return false;
        if ($user->id === $target->id) return true;
        if ($user->hasRole('manager') && $target->hasRole('admin')) return false;
        return $user->isManager();
    }

    public function delete(User $user, User $target): bool
    {
        if ($user->id === $target->id) return false;
        if ($target->hasRole('admin')) return false;
        return $user->hasRole('admin');
    }
}
