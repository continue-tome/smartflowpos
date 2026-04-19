<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerTab;
use App\Models\Order;
use App\Services\TicketPrintService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerTabController extends Controller
{
    public function __construct(protected TicketPrintService $ticketService) {}

    /** Liste des ardoises ouvertes */
    public function index(Request $request)
    {
        $tabs = CustomerTab::where('restaurant_id', $request->user()->restaurant_id)
            ->with('creator:id,first_name,last_name')
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->search, fn($q) => $q->where(function ($q) use ($request) {
                $q->where('last_name', 'like', "%{$request->search}%")
                  ->orWhere('first_name', 'like', "%{$request->search}%")
                  ->orWhere('phone', 'like', "%{$request->search}%");
            }))
            ->withCount('orders')
            ->latest()
            ->paginate(20);

        return response()->json($tabs);
    }

    /** Créer une nouvelle ardoise */
    public function store(Request $request)
    {
        $request->validate([
            'last_name'  => 'required|string|max:100',
            'first_name' => 'required|string|max:100',
            'phone'      => 'required|string|max:20',
            'notes'      => 'nullable|string',
        ]);

        abort_unless($request->user()->isManager(), 403, 'Seul un administrateur peut créer de nouveaux clients.');

        // Vérifier si une ardoise ouverte existe déjà pour ce téléphone
        $existing = CustomerTab::where('restaurant_id', $request->user()->restaurant_id)
            ->where('phone', $request->phone)
            ->where('status', 'open')
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Une ardoise ouverte existe déjà pour ce numéro.',
                'tab'     => $existing,
            ], 422);
        }

        $tab = CustomerTab::create([
            'restaurant_id' => $request->user()->restaurant_id,
            'created_by'    => $request->user()->id,
            'last_name'     => $request->last_name,
            'first_name'    => $request->first_name,
            'phone'         => $request->phone,
            'notes'         => $request->notes,
            'opened_at'     => now(),
        ]);

        $tab->logActivity('tab_opened', "Ardoise ouverte pour {$tab->full_name} ({$tab->phone})");

        return response()->json($tab, 201);
    }

    /** Détail d'une ardoise avec ses commandes */
    public function show(Request $request, CustomerTab $tab)
    {
        abort_if($tab->restaurant_id !== $request->user()->restaurant_id, 403);

        $tab->load([
            'orders.items.product',
            'orders.payments',
            'orders.table',
            'creator:id,first_name,last_name',
        ]);

        $tab->remaining_amount = $tab->remainingAmount();

        return response()->json($tab);
    }

    /** Attacher une commande à une ardoise */
    public function attachOrder(Request $request, CustomerTab $tab)
    {
        abort_if($tab->restaurant_id !== $request->user()->restaurant_id, 403);
        abort_if($tab->status === 'paid', 422, 'Ardoise déjà payée.');

        $request->validate(['order_id' => 'required|exists:orders,id']);

        $order = Order::findOrFail($request->order_id);
        abort_if($order->restaurant_id !== $request->user()->restaurant_id, 403);

        // Vérifier que la commande n'est pas déjà sur une ardoise
        if ($order->customerTabs()->count() > 0) {
            return response()->json(['message' => 'Cette commande est déjà sur une ardoise.'], 422);
        }

        DB::transaction(function () use ($tab, $order, $request) {
            $tab->orders()->attach($order->id);
            $tab->recalculate();

            // La commande passe en payée (dette) pour disparaître des commandes en cours
            $order->update([
                'status' => 'paid',
                'paid_at' => now(),
                'cashier_id' => $request->user()->id,
            ]);

            // Marquer tous les articles comme servis
            $order->items()
                ->whereNotIn('status', ['cancelled', 'served'])
                ->update(['status' => 'served', 'served_at' => now()]);

            // Libérer la table si elle était assignée
            if ($order->table_id) {
                \App\Models\Table::where('id', $order->table_id)->update([
                    'status' => 'free',
                    'occupied_since' => null,
                    'assigned_user_id' => null,
                ]);
            }

            // Déduire les stocks
            \App\Jobs\ProcessStockDeduction::dispatch($order);
        });

        $tab->logActivity('order_attached', "Commande #{$order->order_number} ajoutée à l'ardoise");

        return response()->json([
            'message' => 'Commande ajoutée à l\'ardoise.',
            'tab'     => $tab->fresh(['orders']),
        ]);
    }

    /** Paiement d'une ardoise (total ou partiel) */
    public function pay(Request $request, CustomerTab $tab)
    {
        abort_if($tab->restaurant_id !== $request->user()->restaurant_id, 403);
        abort_if(in_array($tab->status, ['paid', 'cancelled']), 422, 'Ardoise déjà soldée ou annulée.');

        $request->validate([
            'amount'            => 'required|numeric|min:0.01',
            'payment_method'    => 'required|in:cash,card,wave,orange_money,momo,moov,mixx,bank,other',
            'payment_reference' => 'nullable|string|max:100',
            'notes'             => 'nullable|string',
        ]);

        DB::transaction(function () use ($tab, $request) {
            $amountToDistribute = $request->amount;
            $session = \App\Models\CashSession::where('restaurant_id', $request->user()->restaurant_id)
                ->whereNull('closed_at')->latest()->first();

            $orders = $tab->orders()->orderBy('created_at')->get();
            foreach ($orders as $o) {
                if ($amountToDistribute <= 0) break;
                
                $paidOnOrder = $o->payments()->sum('amount');
                $remainingOnOrder = max(0, $o->total - $paidOnOrder);
                
                if ($remainingOnOrder > 0) {
                    $apply = min($amountToDistribute, $remainingOnOrder);
                    
                    \App\Models\Payment::create([
                        'order_id'        => $o->id,
                        'cash_session_id' => $session?->id,
                        'user_id'         => $request->user()->id,
                        'amount'          => $apply,
                        'method'          => $request->payment_method,
                        'reference'       => $request->payment_reference,
                        'is_partial'      => $apply < $remainingOnOrder,
                    ]);
                    
                    $amountToDistribute -= $apply;
                }
            }

            $tab->increment('paid_amount', $request->amount);
            $tab->recalculate();
            $tab->refresh();

            // Les ardoises restent perpétuellement ouvertes pour ce client (compte client global)
            $newStatus = 'open';
            $tab->update([
                'status'  => $newStatus,
                'paid_at' => $tab->paid_amount >= $tab->total_amount ? now() : null,
                // 'closed_at' remains null
            ]);
        });

        $remaining = $tab->remainingAmount();
        $tab->logActivity('tab_payment', "Paiement de {$request->amount} FCFA ({$request->payment_method}) sur ardoise. Reste: {$remaining} FCFA");

        return response()->json([
            'message'          => $tab->remainingAmount() <= 0 ? 'Ardoise soldée ! (Le compte reste ouvert pour de futures commandes)' : 'Paiement partiel enregistré.',
            'tab'              => $tab->fresh(),
            'remaining_amount' => $remaining,
        ]);
    }

    /** Facture HTML A4 de l'ardoise */
    public function invoice(Request $request, CustomerTab $tab)
    {
        $pdfBinary = $this->ticketService->generateTabInvoicePdf($tab);
        
        return response($pdfBinary)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="ardoise_' . $tab->id . '.pdf"');
    }

    /** Annuler une ardoise */
    public function cancel(Request $request, CustomerTab $tab)
    {
        abort_if($tab->restaurant_id !== $request->user()->restaurant_id, 403);
        abort_unless($request->user()->isManager(), 403, 'Manager requis.');
        abort_if($tab->status === 'paid', 422, 'Ardoise déjà payée.');

        $request->validate(['reason' => 'required|string|min:5']);

        $tab->update(['status' => 'cancelled', 'closed_at' => now()]);
        $tab->logActivity('tab_cancelled', "Ardoise annulée. Raison: {$request->reason}");

        return response()->json(['message' => 'Ardoise annulée.']);
    }

    /**
     * Impression directe sur imprimante réseau pour l'ardoise (relevé)
     */
    public function printNetwork(\Illuminate\Http\Request $request, \App\Models\CustomerTab $tab, \App\Services\EscPosPrintService $printService)
    {
        abort_if($tab->restaurant_id !== $request->user()->restaurant_id, 403);

        $result = $printService->printCustomerTab($tab);
        
        if ($result['success']) {
            return response()->json(['message' => 'Impression lancée avec succès.']);
        }
        
        return response()->json([
            'message' => 'L\'impression a échoué : ' . ($result['message'] ?? 'Erreur inconnue'),
        ], 500);
    }
}
