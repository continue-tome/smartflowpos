<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Table;
use App\Models\Product;
use App\Models\Delivery;
use App\Events\OrderCreated;
use App\Events\OrderReady;
use App\Jobs\ProcessStockDeduction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $orders = Order::with([
            'table', 'waiter', 'items.product',
            'cancellations' => fn($q) => $q->where('status', 'pending'),
            'items.cancellations' => fn($q) => $q->where('status', 'pending')
        ])
            ->where('restaurant_id', $request->user()->restaurant_id)
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->table_id, fn($q) => $q->where('table_id', $request->table_id))
            ->when($request->search, fn($q) => $q->where('order_number', 'like', "%{$request->search}%"))
            ->when($request->date, fn($q) => $q->whereDate('created_at', $request->date))
            ->when($request->type, fn($q) => $q->where('type', $request->type))
            ->latest()
            ->paginate(30);

        return response()->json($orders);
    }

    public function currentByTable(Request $request, int $tableId)
    {
        $order = Order::with([
                'items.product',
                'items.modifiers.modifier',
                'items.cancellations' => fn($q) => $q->where('status', 'pending'),
                'waiter:id,first_name,last_name',
                'logs' => fn($q) => $q->latest()->limit(20),
                'cancellations' => fn($q) => $q->where('status', 'pending')
            ])
            ->where('restaurant_id', $request->user()->restaurant_id)
            ->where('table_id', $tableId)
            ->whereIn('status', ['open', 'sent_to_kitchen', 'partially_served', 'served'])
            ->latest()
            ->firstOrFail();

        /** @var Order $order */
        return response()->json([
            'order'        => $order,
            'amount_paid'  => $order->amountPaid(),
            'amount_due'   => $order->amountDue(),
        ]);
    }

    public function show(Request $request, Order $order)
    {
        $this->authorizeOrder($request, $order);

        return response()->json(
            $order->load([
                'items.product', 'items.modifiers.modifier',
                'payments', 'waiter', 'cashier', 'table',
                'logs', 'delivery', 'cancellations',
            ])
        );
    }

    public function store(Request $request)
    {
        $request->validate([
            'table_id'       => 'nullable|exists:tables,id',
            'type'           => 'required|in:dine_in,takeaway,delivery,gozem',
            'covers'         => 'integer|min:1',
            'notes'          => 'nullable|string',
            'customer_name'  => 'nullable|string|max:150',
            'customer_phone' => 'nullable|string|max:20',
            'items'          => 'required|array|min:1',
            'items.*.product_id'   => 'required|exists:products,id',
            'items.*.quantity'     => 'required|integer|min:1',
            'items.*.notes'        => 'nullable|string',
            'items.*.course'       => 'integer|min:1',
            'items.*.modifier_ids' => 'nullable|array',
            'items.*.modifier_ids.*' => 'exists:modifiers,id',
        ]);

        $order = DB::transaction(function () use ($request) {
            $order = Order::create([
                'restaurant_id'  => $request->user()->restaurant_id,
                'table_id'       => $request->table_id,
                'user_id'        => $request->user()->id,
                'order_number'   => Order::generateNumber($request->user()->restaurant_id),
                'type'           => $request->type,
                'covers'         => $request->covers ?? 1,
                'notes'          => $request->notes,
                'customer_name'  => $request->customer_name,
                'customer_phone' => $request->customer_phone,
                'status'         => 'open',
            ]);

            foreach ($request->items as $itemData) {
                $product = Product::findOrFail($itemData['product_id']);

                $item = $order->items()->create([
                    'product_id' => $product->id,
                    'quantity'   => $itemData['quantity'],
                    'unit_price' => $product->price,
                    'subtotal'   => $product->price * $itemData['quantity'],
                    'notes'      => $itemData['notes'] ?? null,
                    'course'     => $itemData['course'] ?? 1,
                    'status'     => 'pending',
                ]);

                if (!empty($itemData['modifier_ids'])) {
                    foreach ($itemData['modifier_ids'] as $modifierId) {
                        $modifier = \App\Models\Modifier::find($modifierId);
                        $item->modifiers()->create([
                            'modifier_id' => $modifierId,
                            'extra_price' => $modifier?->extra_price ?? 0,
                        ]);
                    }
                }
            }

            $order->recalculate();

            if ($request->table_id) {
                Table::where('id', $request->table_id)->update([
                    'status'         => 'occupied',
                    'occupied_since' => now(),
                    'assigned_user_id' => $request->user()->id,
                ]);
            }

            if ($request->type === 'gozem' || $request->type === 'delivery') {
                $order->delivery()->create([
                    'restaurant_id'  => $order->restaurant_id,
                    'customer_name'  => $order->customer_name ?? 'Client Gozem',
                    'customer_phone' => $order->customer_phone ?? '0000',
                    'address'        => 'Via Gozem App', // Valeur par défaut pour Gozem
                    'status'         => 'pending'
                ]);
            }

            $order->logActivity('order_created', "Commande {$order->order_number} créée par {$request->user()->full_name}");

            return $order;
        });

        return response()->json($order->load('items.product'), 201);
    }

    public function sendToKitchen(Request $request, Order $order)
    {
        /** @var \App\Services\TicketPrintService $ticketService */
        $ticketService = app(\App\Services\TicketPrintService::class);
        $this->authorizeOrder($request, $order);

        $request->validate([
            'item_ids' => 'nullable|array',
            'item_ids.*' => 'exists:order_items,id',
        ]);

        abort_if(in_array($order->status, ['paid', 'cancelled']), 422, 'Commande déjà finalisée.');

        $itemsQuery = $order->items()->where('status', 'pending');
        if ($request->item_ids) {
            $itemsQuery->whereIn('id', $request->item_ids);
        }

        $items = $itemsQuery->get();
        abort_if($items->isEmpty(), 422, 'Aucun item à envoyer en cuisine.');

        DB::transaction(function () use ($request, $order, $items) {
            $items->each->update(['status' => 'preparing', 'sent_at' => now()]);

            $order->update([
                'status'             => 'sent_to_kitchen',
                'sent_to_kitchen_at' => $order->sent_to_kitchen_at ?? now(),
            ]);

            $order->logActivity('sent_to_kitchen', count($items) . " article(s) envoyé(s) en cuisine", ['item_ids' => $items->pluck('id')]);
        });

        broadcast(new OrderCreated($order->load('items.product', 'table')))->toOthers();
        
        // Générer les tickets pour l'impression (Cuisine, Bar, Pizza)
        $tickets = [];
        $destinations = ['kitchen', 'bar', 'pizza'];

        foreach ($destinations as $dest) {
            $html = $ticketService->kitchenTicketHtml($order, $dest);
            if ($html) {
                $tickets[] = [
                    'destination' => $dest,
                    'html' => $html
                ];
            }
        }

        $message = 'Commande envoyée en cuisine.';

        return response()->json([
            'message' => $message,
            'tickets' => $tickets
        ]);
    }

    public function updateItems(Request $request, Order $order)
    {
        $this->authorizeOrder($request, $order);

        $request->validate([
            'items'            => 'required|array',
            'items.*.id'       => 'required|exists:order_items,id',
            'items.*.quantity' => 'required|integer|min:0',
            'items.*.notes'    => 'nullable|string|max:300',
        ]);

        abort_if(in_array($order->status, ['paid', 'cancelled']), 422, 'Impossible de modifier une commande finalisée.');

        DB::transaction(function () use ($request, $order) {
            foreach ($request->items as $data) {
                $item = $order->items()->findOrFail($data['id']);

                if ($item->status === 'done' && $data['quantity'] < $item->quantity) {
                    abort_unless($request->user()->isManager(), 403, 'Manager requis pour annuler un article servi.');
                }

                if ($data['quantity'] === 0) {
                    $order->logActivity('item_removed', "{$item->product->name} supprimé de la commande");
                    $item->delete();
                } else {
                    $wasServed = $item->status === 'done';
                    $item->update([
                        'quantity' => $data['quantity'],
                        'subtotal' => $item->unit_price * $data['quantity'],
                        'notes'    => $data['notes'] ?? $item->notes,
                        'status'   => ($data['quantity'] > $item->quantity && $wasServed) ? 'pending' : $item->status,
                    ]);

                    $order->logActivity('item_updated', "{$item->product->name} : qté → {$data['quantity']}");
                }
            }

            $order->recalculate();
        });

        return response()->json($order->fresh(['items.product', 'items.modifiers']));
    }

    public function addItems(Request $request, Order $order)
    {
        $this->authorizeOrder($request, $order);

        $request->validate([
            'items'              => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity'   => 'required|integer|min:1',
            'items.*.notes'      => 'nullable|string',
            'items.*.course'     => 'integer|min:1',
        ]);

        abort_if(in_array($order->status, ['paid', 'cancelled']), 422, 'Commande finalisée.');

        DB::transaction(function () use ($request, $order) {
            foreach ($request->items as $data) {
                $product = Product::findOrFail($data['product_id']);
                $order->items()->create([
                    'product_id' => $product->id,
                    'quantity'   => $data['quantity'],
                    'unit_price' => $product->price,
                    'subtotal'   => $product->price * $data['quantity'],
                    'notes'      => $data['notes'] ?? null,
                    'course'     => $data['course'] ?? 1,
                    'status'     => 'pending',
                ]);
            }
            $order->recalculate();

            $order->logActivity('items_added', count($request->items) . " article(s) ajouté(s)");
        });

        return response()->json($order->fresh('items.product'));
    }

    public function applyDiscount(Request $request, Order $order)
    {
        $this->authorizeOrder($request, $order);
        abort_unless($request->user()->isManager(), 403, 'Manager requis pour appliquer une remise.');

        $request->validate([
            'type'   => 'required|in:percent,fixed',
            'value'  => 'required|numeric|min:0',
            'reason' => 'required|string',
        ]);

        $discount = $request->type === 'percent'
            ? round($order->subtotal * $request->value / 100, 2)
            : min($request->value, $order->subtotal);

        $order->update([
            'discount_amount' => $discount,
            'discount_reason' => $request->reason,
        ]);
        $order->recalculate();

        $order->logActivity('discount_applied', "Remise de {$discount} FCFA appliquée ({$request->reason})");

        return response()->json($order->fresh());
    }

    public function updateStatus(Request $request, Order $order)
    {
        $this->authorizeOrder($request, $order);

        $request->validate([
            'status' => 'required|in:open,sent_to_kitchen,partially_served,served,paid,cancelled,cancelling',
            'reason' => 'required_if:status,cancelled|string|min:5'
        ], [
            'reason.required_if' => 'Un motif d\'annulation est obligatoire.'
        ]);

        $oldStatus = $order->status;
        $order->update(['status' => $request->status]);

        if ($request->status === 'paid' || $request->status === 'served') {
            $order->items()
                ->whereNotIn('status', ['cancelled', 'served'])
                ->update(['status' => 'served', 'served_at' => now()]);
        }
        
        if ($request->status === 'cancelled') {
            $order->logActivity('order_cancelled', "Commande {$order->order_number} annulée. Motif: {$request->reason}");
            // Si la table était occupée, on la libère si c'est la seule commande ouverte
            if ($order->table_id) {
                $otherOpen = Order::where('table_id', $order->table_id)
                    ->where('id', '!=', $order->id)
                    ->whereIn('status', ['open', 'sent_to_kitchen', 'partially_served'])
                    ->exists();
                if (!$otherOpen && $order->table) {
                    $order->table->update(['status' => 'available', 'occupied_since' => null, 'assigned_user_id' => null]);
                }
            }
        } else {
            $order->logActivity('status_updated', "Statut {$oldStatus} → {$request->status}");
        }

        return response()->json($order);
    }

    public function transfer(Request $request, Order $order)
    {
        $this->authorizeOrder($request, $order);
        
        $request->validate([
            'target_table_id' => 'required|exists:tables,id',
        ]);

        $oldTableId = $order->table_id;
        $targetTableId = $request->target_table_id;

        if ($oldTableId == $targetTableId) {
            return response()->json(['message' => 'Même table sélectionnée.'], 422);
        }

        DB::transaction(function () use ($order, $oldTableId, $targetTableId, $request) {
            // Libérer l'ancienne table si c'était sa seule commande
            if ($oldTableId) {
                $otherOrders = Order::where('table_id', $oldTableId)
                    ->where('id', '!=', $order->id)
                    ->whereIn('status', ['open', 'sent_to_kitchen', 'partially_served'])
                    ->exists();
                
                if (!$otherOrders) {
                    Table::where('id', $oldTableId)->update([
                        'status' => 'available',
                        'occupied_since' => null,
                        'assigned_user_id' => null
                    ]);
                }
            }

            // Occuper la nouvelle table
            Table::where('id', $targetTableId)->update([
                'status' => 'occupied',
                'occupied_since' => now(),
                'assigned_user_id' => $request->user()->id
            ]);

            // Transférer la commande
            $order->update(['table_id' => $targetTableId]);

            $order->logActivity('order_transferred', "Commande déplacée de Table {$oldTableId} vers Table {$targetTableId}");
        });

        return response()->json(['message' => 'Commande transférée avec succès.']);
    }

    private function authorizeOrder(Request $request, Order $order): void
    {
        abort_if($order->restaurant_id !== $request->user()->restaurant_id, 403);
    }
}
