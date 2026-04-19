<?php

namespace App\Events;

use App\Models\Table;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class TableStatusChanged implements ShouldBroadcast
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(public Table $table) {}

    public function broadcastOn(): array
    {
        return [new Channel("restaurant.{$this->table->floor->restaurant_id}")];
    }

    public function broadcastAs(): string { return 'table.status'; }

    public function broadcastWith(): array
    {
        return $this->table->only(['id', 'number', 'status', 'occupied_since', 'assigned_user_id']);
    }
}
