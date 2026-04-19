<?php

namespace App\Events;

use App\Models\OrderItem;
use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class OrderItemStatusUpdated implements ShouldBroadcast
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(public OrderItem $item, public Order $order) {}

    public function broadcastOn(): array
    {
        return [
            new Channel("restaurant.{$this->order->restaurant_id}"),
            new Channel("floor.{$this->order->restaurant_id}"),
        ];
    }

    public function broadcastAs(): string { return 'order.item.status'; }

    public function broadcastWith(): array
    {
        return [
            'item_id' => $this->item->id, 'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'status' => $this->item->status, 'product_name' => $this->item->product->name,
        ];
    }
}
