<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $notifications = [];

        $lowStock = Ingredient::where('restaurant_id', $restaurantId)->whereColumn('quantity', '<=', 'min_quantity')->where('active', true)
            ->get(['id', 'name', 'quantity', 'min_quantity', 'unit'])
            ->map(fn($i) => ['type' => 'stock_low', 'level' => $i->quantity <= 0 ? 'danger' : 'warning', 'message' => $i->quantity <= 0 ? "Rupture : {$i->name}" : "Stock faible : {$i->name} ({$i->quantity} {$i->unit})", 'subject' => $i, 'created_at' => now()]);

        $lateOrders = \App\Models\Order::where('restaurant_id', $restaurantId)->whereIn('status', ['sent_to_kitchen'])->where('sent_to_kitchen_at', '<', now()->subMinutes(15))->with('table:id,number')
            ->get(['id', 'order_number', 'sent_to_kitchen_at', 'table_id'])
            ->map(fn($o) => ['type' => 'order_late', 'level' => 'danger', 'message' => "Commande {$o->order_number} en retard (" . now()->diffInMinutes($o->sent_to_kitchen_at) . " min)", 'subject' => $o, 'created_at' => $o->sent_to_kitchen_at]);

        $pendingCancellations = \App\Models\Cancellation::where('restaurant_id', $restaurantId)->where('status', 'pending')->with('requester:id,first_name,last_name')->get()
            ->map(fn($c) => ['type' => 'cancellation_pending', 'level' => 'warning', 'message' => "Demande d'annulation de {$c->requester->full_name}", 'subject' => $c, 'created_at' => $c->requested_at]);

        $notifications = collect($lowStock)->merge($lateOrders)->merge($pendingCancellations)->sortByDesc('created_at')->values();

        return response()->json(['notifications' => $notifications, 'count' => $notifications->count(), 'has_danger' => $notifications->where('level', 'danger')->isNotEmpty()]);
    }
}
