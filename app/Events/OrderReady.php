<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class OrderReady implements ShouldBroadcast
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(public Order $order) {}

    public function broadcastOn(): array
    {
        return [
            new Channel("restaurant.{$this->order->restaurant_id}"),
            new Channel("floor.{$this->order->restaurant_id}"),
        ];
    }

    public function broadcastAs(): string { return 'order.ready'; }

    public function broadcastWith(): array
    {
        return ['order_id' => $this->order->id, 'order_number' => $this->order->order_number, 'table_number' => $this->order->table?->number];
    }
}
