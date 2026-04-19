<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\OrderRoutingService;
use App\Services\TicketPrintService;
use App\Events\OrderItemStatusUpdated;
use App\Events\OrderReady;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class KitchenController extends Controller
{
    public function __construct(
        protected OrderRoutingService $routing,
        protected TicketPrintService  $ticketService
    ) {}

    /**
     * Liste des commandes filtrées par destination
     * ?destination=kitchen|bar|pizza  (défaut: kitchen)
     */
    public function orders(Request $request)
    {
        $destination = $request->get('destination', 'kitchen');
        abort_unless(in_array($destination, ['kitchen', 'bar', 'pizza']), 422, 'Destination invalide.');

        $orders = Order::with([
                'items' => fn($q) => $q
                    ->whereIn('status', ['pending', 'preparing', 'done'])
                    ->with(['product.category', 'cancellations' => fn($c) => $c->where('status', 'pending')])
                    ->orderBy('course')
                    ->orderBy('created_at'),
                'table:id,number',
                'waiter:id,first_name,last_name',
                'cancellations' => fn($q) => $q->where('status', 'pending')
            ])
            ->where('restaurant_id', $request->user()->restaurant_id)
            ->whereIn('status', ['sent_to_kitchen', 'partially_served'])
            ->orderBy('sent_to_kitchen_at')
            ->get()
            ->map(function ($order) use ($destination) {
                // Filtrer uniquement les items de cette destination
                $groups             = $this->routing->groupByDestination($order->items);
                $order->items       = $groups[$destination] ?? collect();

                // Ne garder que les commandes qui ont des items pour cette destination
                $order->minutes_since_sent = $order->sent_to_kitchen_at
                    ? now()->diffInMinutes($order->sent_to_kitchen_at) : 0;
                $order->priority = $order->minutes_since_sent > 15 ? 'urgent'
                    : ($order->minutes_since_sent > 10 ? 'warning' : 'normal');

                return $order;
            })
            ->filter(fn($order) => $order->items->isNotEmpty())
            ->values();

        return response()->json($orders);
    }

    /**
     * Mettre à jour le statut d'un item
     */
    public function updateItemStatus(Request $request, OrderItem $item)
    {
        $this->authorizeItem($request, $item);

        $request->validate(['status' => 'required|in:preparing,done']);
        
        $hasPendingCancellation = $item->cancellations()->where('status', 'pending')->exists();
        abort_if($hasPendingCancellation, 422, 'Cet article est en cours d\'annulation. Action impossible.');

        $item->update([
            'status'      => $request->status,
            'prepared_at' => $request->status === 'done' ? now() : $item->prepared_at,
        ]);

        $order = $item->order;

        // Vérifier si tous les items toutes destinations confondues sont terminés
        $allDone = $order->items()
            ->whereNotIn('status', ['done', 'served', 'cancelled'])
            ->whereDoesntHave('cancellations', fn($q) => $q->where('status', 'pending'))
            ->doesntExist();

        if ($allDone) {
            $hasOrderCancellation = $order->cancellations()->where('status', 'pending')->exists();
            if (!$hasOrderCancellation) {
                $order->update(['status' => 'served', 'served_at' => now()]);
                broadcast(new OrderReady($order->load('table')))->toOthers();
            }

            $order->logs()->create([
                'user_id' => $request->user()->id,
                'action'  => 'order_ready',
                'message' => "Commande {$order->order_number} entièrement prête",
            ]);
        } elseif ($request->status === 'done') {
            $order->update(['status' => 'partially_served']);
        }

        broadcast(new OrderItemStatusUpdated($item, $order))->toOthers();

        return response()->json([
            'item'     => $item,
            'order'    => $order->fresh(),
            'all_done' => $allDone,
        ]);
    }

    /**
     * Valider toute la commande d'un coup
     */
    public function validateAll(Request $request, Order $order)
    {
        abort_if($order->restaurant_id !== $request->user()->restaurant_id, 403);

        DB::transaction(function () use ($order, $request) {
            $order->items()
                ->whereIn('status', ['pending', 'preparing'])
                ->update(['status' => 'done', 'prepared_at' => now()]);

            $order->update(['status' => 'served', 'served_at' => now()]);

            $order->logs()->create([
                'user_id' => $request->user()->id,
                'action'  => 'order_ready',
                'message' => "Commande {$order->order_number} entièrement validée",
            ]);
        });

        broadcast(new OrderReady($order->load('table')))->toOthers();

        return response()->json(['message' => 'Commande validée.', 'order' => $order->fresh()]);
    }

    /**
     * Ticket impression pour une destination donnée — SANS PRIX
     * GET /kitchen/orders/{order}/ticket?destination=kitchen
     */
    public function ticket(Request $request, Order $order)
    {
        abort_if($order->restaurant_id !== $request->user()->restaurant_id, 403);

        $destination = $request->get('destination', 'kitchen');
        abort_unless(in_array($destination, ['kitchen', 'bar', 'pizza']), 422, 'Destination invalide.');

        $order->loadMissing(['items.product.category', 'table', 'waiter']);
        $html = $this->ticketService->kitchenTicketHtml($order, $destination);

        if (!$html) {
            return response()->json(['message' => "Aucun item pour la destination '{$destination}'."], 404);
        }

        return response($html)->header('Content-Type', 'text/html');
    }

    private function authorizeItem(Request $request, OrderItem $item): void
    {
        abort_if($item->order->restaurant_id !== $request->user()->restaurant_id, 403);
    }
}
