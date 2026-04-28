<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Table;
use App\Models\Product;
use App\Models\Restaurant;
use App\Events\OrderCreated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PublicOrderController extends Controller
{
    /**
     * Récupère les tables libres pour un restaurant donné.
     */
    public function availableTables(string $restaurantSlug)
    {
        $restaurant = Restaurant::where('slug', $restaurantSlug)->first() ?? Restaurant::first();
        if (!$restaurant) return response()->json(['message' => 'Aucun restaurant configuré'], 404);
        
        $tables = Table::whereHas('floor', fn($q) => $q->where('restaurant_id', $restaurant->id))
            ->where('status', 'free')
            ->where('active', true)
            ->orderBy('number')
            ->get();

        return response()->json($tables);
    }

    /**
     * Enregistre une commande venant du menu public (QR Code ou Distant).
     */
    public function store(Request $request, string $restaurantSlug, \App\Services\TicketPrintService $ticketService)
    {
        $restaurant = Restaurant::where('slug', $restaurantSlug)->first() ?? Restaurant::first();
        if (!$restaurant) return response()->json(['message' => 'Aucun restaurant configuré'], 404);

        $request->validate([
            'table_id'       => 'nullable|exists:tables,id',
            'type'           => 'required|in:dine_in,takeaway',
            'customer_name'  => 'nullable|string|max:150',
            'customer_phone' => 'nullable|string|max:20',
            'items'          => 'required|array|min:1',
            'items.*.product_id'   => 'required|exists:products,id',
            'items.*.quantity'     => 'required|integer|min:1',
            'items.*.notes'        => 'nullable|string',
            'items.*.modifier_ids' => 'nullable|array',
        ]);

        $order = DB::transaction(function () use ($request, $restaurant) {
            // Un utilisateur système par défaut ou null pour les commandes QR
            $defaultUser = $restaurant->users()->whereHas('role', fn($q) => $q->where('slug', 'manager'))->first() 
                           ?? $restaurant->users()->first();

            $order = Order::create([
                'restaurant_id'  => $restaurant->id,
                'table_id'       => $request->table_id,
                'user_id'        => $defaultUser?->id,
                'order_number'   => Order::generateNumber($restaurant->id),
                'type'           => $request->type,
                'status'         => 'open',
                'notes'          => $request->notes ?? "Commande passée par QR Code / Client Web",
                'customer_name'  => $request->customer_name ?? 'Client Web',
                'customer_phone' => $request->customer_phone,
            ]);

            foreach ($request->items as $itemData) {
                $product = Product::findOrFail($itemData['product_id']);

                $item = $order->items()->create([
                    'product_id' => $product->id,
                    'quantity'   => $itemData['quantity'],
                    'unit_price' => $product->price,
                    'subtotal'   => $product->price * $itemData['quantity'],
                    'notes'      => $itemData['notes'] ?? null,
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
                ]);
            }

            $order->logActivity('order_created', "Nouvelle commande client {$order->order_number} ({$order->type})");

            return $order;
        });

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

        // Déclencher l'événement pour la sonnerie et l'impression automatique en caisse
        broadcast(new OrderCreated($order->load('items.product', 'table'), $tickets))->toOthers();

        return response()->json([
            'message'      => 'Commande envoyée avec succès !',
            'order_number' => $order->order_number,
            'order'        => $order->load('items.product'),
            'tickets'      => $tickets
        ], 201);
    }
}
