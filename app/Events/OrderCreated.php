<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class OrderCreated implements ShouldBroadcast
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(public Order $order) {}

    public function broadcastOn(): array
    {
        return [
            new Channel("restaurant.{$this->order->restaurant_id}"),
            new Channel("kitchen.{$this->order->restaurant_id}"),
        ];
    }

    public function broadcastAs(): string { return 'order.created'; }

    public function broadcastWith(): array
    {
        return [
            'order_id'     => $this->order->id,
            'order_number' => $this->order->order_number,
            'table'        => $this->order->table?->only(['id', 'number']),
            'items'        => $this->order->items->map(fn($i) => [
                'id' => $i->id, 'name' => $i->product->name,
                'quantity' => $i->quantity, 'notes' => $i->notes, 'course' => $i->course,
            ]),
        ];
    }
}
