<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Order;

class OrderPolicy
{
    public function view(User $user, Order $order): bool
    {
        return $order->restaurant_id === $user->restaurant_id;
    }

    public function update(User $user, Order $order): bool
    {
        if ($order->restaurant_id !== $user->restaurant_id) return false;
        if (in_array($order->status, ['paid', 'cancelled'])) return false;
        if ($user->hasRole('waiter')) return $order->user_id === $user->id;
        return $user->hasRole(['admin', 'manager', 'cashier']);
    }

    public function cancel(User $user, Order $order): bool
    {
        return $order->restaurant_id === $user->restaurant_id && $user->isManager();
    }
}
