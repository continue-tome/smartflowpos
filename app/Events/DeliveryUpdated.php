<?php

namespace App\Events;

use App\Models\Delivery;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class DeliveryUpdated implements ShouldBroadcast
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(public Delivery $delivery) {}

    public function broadcastOn(): array
    {
        return [new Channel("restaurant.{$this->delivery->restaurant_id}")];
    }

    public function broadcastAs(): string { return 'delivery.updated'; }

    public function broadcastWith(): array
    {
        return $this->delivery->toArray();
    }
}
