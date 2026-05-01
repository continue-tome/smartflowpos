<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CakeOrder;
use App\Models\CashSession;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CakeOrderController extends Controller
{
    /** Liste des commandes gâteaux */
    public function index(Request $request)
    {
        $orders = CakeOrder::where('restaurant_id', $request->user()->restaurant_id)
            ->with('cashier:id,first_name,last_name')
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->date, fn($q) => $q->where('delivery_date', $request->date))
            ->when($request->is_paid !== null, fn($q) => $q->where('is_paid', (bool)$request->is_paid))
            ->when($request->search, fn($q) => $q->where(function ($q) use ($request) {
                $q->where('customer_name', 'like', "%{$request->search}%")
                  ->orWhere('customer_phone', 'like', "%{$request->search}%")
                  ->orWhere('order_number', 'like', "%{$request->search}%");
            }))
            ->orderBy('delivery_date')
            ->orderBy('delivery_time')
            ->paginate(25);

        return response()->json($orders);
    }

    /** Créer une commande gâteau */
    public function store(Request $request)
    {
        $request->validate([
            'customer_name'   => 'required|string|max:150',
            'customer_phone'  => 'required|string|max:20',
            'delivery_date'   => 'required|date|after_or_equal:today',
            'delivery_time'   => 'nullable|date_format:H:i',
            'items'           => 'required|array|min:1',
            'items.*.name'    => 'required|string',
            'items.*.qty'     => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.notes'   => 'nullable|string',
            'advance_paid'    => 'nullable|numeric|min:0',
            'payment_method'  => 'nullable|string|in:cash,card,wave,orange_money,momo,moov,mixx,bank,other',
            'notes'           => 'nullable|string',
        ]);

        $total = collect($request->items)->sum(fn($i) => $i['qty'] * $i['unit_price']);
        $advance = $request->advance_paid ?? 0;
        $pm = $request->payment_method ?? 'cash';

        $session = CashSession::where('restaurant_id', $request->user()->restaurant_id)
            ->whereNull('closed_at')->latest()->first();

        $order = DB::transaction(function() use ($request, $total, $advance, $session, $pm) {
            $order = CakeOrder::create([
                'restaurant_id'   => $request->user()->restaurant_id,
                'user_id'         => $request->user()->id,
                'cash_session_id' => $session?->id,
                'order_number'    => CakeOrder::generateNumber($request->user()->restaurant_id),
                'customer_name'   => $request->customer_name,
                'customer_phone'  => $request->customer_phone,
                'items'           => $request->items,
                'total'           => $total,
                'advance_paid'    => $advance,
                'remaining_amount'=> max(0, $total - $advance),
                'delivery_date'   => $request->delivery_date,
                'delivery_time'   => $request->delivery_time,
                'notes'           => $request->notes,
                'is_paid'         => $advance >= $total && $total > 0,
                'paid_at'         => $advance >= $total && $total > 0 ? now() : null,
                'payment_method'  => $advance >= $total ? $pm : null,
            ]);

            if ($advance > 0) {
                Payment::create([
                    'cake_order_id'   => $order->id,
                    'cash_session_id' => $session?->id,
                    'user_id'         => $request->user()->id,
                    'amount'          => $advance,
                    'method'          => $pm,
                ]);
            }

            return $order;
        });

        $order->logActivity('cake_order_created', "Commande gâteau #{$order->order_number} pour {$order->customer_name}");

        return response()->json($order, 201);
    }

    /** Détail d'une commande gâteau */
    public function show(Request $request, CakeOrder $cakeOrder)
    {
        abort_if($cakeOrder->restaurant_id !== $request->user()->restaurant_id, 403);
        return response()->json($cakeOrder->load('cashier:id,first_name,last_name'));
    }

    /** Modifier une commande gâteau */
    public function update(Request $request, CakeOrder $cakeOrder)
    {
        abort_if($cakeOrder->restaurant_id !== $request->user()->restaurant_id, 403);

        $request->validate([
            'customer_name'   => 'required|string|max:150',
            'customer_phone'  => 'nullable|string|max:20',
            'delivery_date'   => 'required|date',
            'delivery_time'   => 'nullable|date_format:H:i',
            'items'           => 'required|array|min:1',
            'items.*.name'    => 'required|string',
            'items.*.qty'     => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.notes'   => 'nullable|string',
            'advance_paid'    => 'nullable|numeric|min:0',
            'notes'           => 'nullable|string',
        ]);

        $total = collect($request->items)->sum(fn($i) => $i['qty'] * $i['unit_price']);
        $advance = $request->advance_paid ?? $cakeOrder->advance_paid ?? 0;

        $cakeOrder->update([
            'customer_name'   => $request->customer_name,
            'customer_phone'  => $request->customer_phone,
            'items'           => $request->items,
            'total'           => $total,
            'advance_paid'    => $advance,
            'remaining_amount'=> max(0, $total - $advance),
            'delivery_date'   => $request->delivery_date,
            'delivery_time'   => $request->delivery_time,
            'notes'           => $request->notes,
            'is_paid'         => $advance >= $total && $total > 0,
            'paid_at'         => $advance >= $total && $total > 0 ? now() : $cakeOrder->paid_at,
        ]);

        $cakeOrder->logActivity('cake_order_updated', "Commande gâteau #{$cakeOrder->order_number} modifiée");

        return response()->json($cakeOrder->fresh());
    }

    /** Mettre à jour le statut d'une commande gâteau */
    public function updateStatus(Request $request, CakeOrder $cakeOrder)
    {
        abort_if($cakeOrder->restaurant_id !== $request->user()->restaurant_id, 403);

        $request->validate([
            'status' => 'required|in:confirmed,preparing,ready,collected,cancelled',
        ]);

        $cakeOrder->update(['status' => $request->status]);
        $cakeOrder->logActivity('cake_status_updated', "Commande #{$cakeOrder->order_number} → {$request->status}");

        return response()->json(['message' => 'Statut mis à jour.', 'order' => $cakeOrder]);
    }

    /** Encaisser une commande gâteau */
    public function collect(Request $request, CakeOrder $cakeOrder)
    {
        abort_if($cakeOrder->restaurant_id !== $request->user()->restaurant_id, 403);
        abort_if($cakeOrder->is_paid, 422, 'Commande déjà encaissée.');

        $request->validate([
            'payment_method'    => 'required_without:method|in:cash,card,wave,orange_money,momo,moov,tmoney,mixx,bank,other',
            'method'            => 'required_without:payment_method|in:cash,card,wave,orange_money,momo,moov,tmoney,mixx,bank,other',
            'payment_reference' => 'nullable|string|max:100',
            'amount_paid'       => 'required_without:amount|numeric|min:0',
            'amount'            => 'required_without:amount_paid|numeric|min:0',
        ]);

        $pm = $request->input('payment_method') ?? $request->input('method');
        $am = $request->input('amount_paid') ?? $request->input('amount');

        DB::transaction(function () use ($cakeOrder, $pm, $am, $request) {
            $totalPaid = $cakeOrder->advance_paid + $am;

            $session = CashSession::where('restaurant_id', $cakeOrder->restaurant_id)
                ->whereNull('closed_at')->latest()->first();

            $cakeOrder->update([
                'is_paid'           => true,
                'paid_at'           => now(),
                'payment_method'    => $pm,
                'payment_reference' => $request->payment_reference,
                'advance_paid'      => $totalPaid,
                'remaining_amount'  => 0,
                'status'            => 'collected',
                'cash_session_id'   => $session?->id,
            ]);

            Payment::create([
                'cake_order_id'   => $cakeOrder->id,
                'cash_session_id' => $session?->id,
                'user_id'         => $request->user()->id,
                'amount'          => $am,
                'method'          => $pm,
                'reference'       => $request->payment_reference,
            ]);
        });

        $cakeOrder->logActivity('cake_order_paid', "Commande #{$cakeOrder->order_number} encaissée ({$pm})");

        // Impression automatique sur l'IP de la caisse
        try {
            app(\App\Services\EscPosPrintService::class)->printCakeOrder($cakeOrder->fresh());
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Échec impression gâteau auto: " . $e->getMessage());
        }

        return response()->json([
            'message' => 'Commande encaissée avec succès.',
            'order'   => $cakeOrder->fresh(),
        ]);
    }

    /** Ticket de confirmation gâteau — format 58mm */
    public function ticket(Request $request, CakeOrder $cakeOrder)
    {
        abort_if($cakeOrder->restaurant_id !== $request->user()->restaurant_id, 403);
        $cakeOrder->load('restaurant');

        $html = app(\App\Services\TicketPrintService::class)->cakeOrderHtml($cakeOrder);

        if ($request->query('format') === 'html') {
            return response()->json(['html' => $html]);
        }

        return response($html)->header('Content-Type', 'text/html');
    }

    /**
     * Générer un PDF professionnel (Bon de commande) via DomPDF
     */
    public function receiptPdf(CakeOrder $cakeOrder)
    {
        $cakeOrder->load(['restaurant', 'cashier']);
        $pdfContent = app(\App\Services\TicketPrintService::class)->generateCakeOrderA4Pdf($cakeOrder);
        
        return response($pdfContent)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', "attachment; filename=\"Confirmation_Gateau_{$cakeOrder->order_number}.pdf\"");
    }

    /**
     * Impression directe sur imprimante réseau pour les gâteaux
     */
    public function printNetwork(\Illuminate\Http\Request $request, \App\Models\CakeOrder $cakeOrder, \App\Services\EscPosPrintService $printService)
    {
        abort_if($cakeOrder->restaurant_id !== $request->user()->restaurant_id, 403);

        $result = $printService->printCakeOrder($cakeOrder);
        
        if ($result['success']) {
            return response()->json(['message' => 'Impression lancée avec succès.']);
        }
        
        return response()->json([
            'message' => 'L\'impression a échoué : ' . ($result['message'] ?? 'Erreur inconnue'),
        ], 500);
    }
}
