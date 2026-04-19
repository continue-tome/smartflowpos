<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Cancellation;

class CancellationPolicy
{
    public function request(User $user): bool { return true; }

    public function approve(User $user, Cancellation $cancellation): bool
    {
        return $cancellation->restaurant_id === $user->restaurant_id && $user->isManager();
    }

    public function reject(User $user, Cancellation $cancellation): bool { return $this->approve($user, $cancellation); }

    public function viewAny(User $user): bool { return $user->isManager(); }
}
