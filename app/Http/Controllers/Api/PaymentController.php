<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\CashSession;
use App\Jobs\ProcessStockDeduction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class PaymentController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'order_id'     => 'required|exists:orders,id',
            'amount'       => 'required|numeric|min:0.01',
            'method'       => 'required|in:cash,card,wave,orange_money,momo,moov,mixx,other',
            'reference'    => 'nullable|string',
            'amount_given' => 'nullable|numeric|min:0',
            'customer_name' => 'nullable|string|max:150',
            'customer_phone' => 'nullable|string|max:20',
        ]);

        $order = Order::findOrFail($request->order_id);
        abort_if($order->restaurant_id !== $request->user()->restaurant_id, 403);
        abort_if($order->status === 'paid', 422, 'Commande déjà payée.');
        abort_if($order->status === 'cancelled', 422, 'Commande annulée.');

        $session = CashSession::where('restaurant_id', $request->user()->restaurant_id)
            ->whereNull('closed_at')->latest()->first();

        abort_if(!$session, 422, 'Aucune session de caisse ouverte. Veuillez ouvrir une session de caisse avant d\'encaisser.');

        DB::transaction(function () use ($request, $order, $session) {
            $changeGiven = null;
            if ($request->input('method') === 'cash' && $request->amount_given) {
                $changeGiven = max(0, $request->amount_given - $request->amount);
            }

            // Update customer name if provided during payment
            if ($request->customer_name) {
                $order->update([
                    'customer_name' => $request->customer_name,
                    'customer_phone' => $request->customer_phone ?? $order->customer_phone
                ]);
            }

            $payment = Payment::create([
                'order_id'        => $order->id,
                'cash_session_id' => $session?->id,
                'user_id'         => Auth::id(),
                'amount'          => $request->amount,
                'method'          => $request->input('method'),
                'reference'       => $request->reference,
                'amount_given'    => $request->amount_given,
                'change_given'    => $changeGiven,
                'is_partial'      => $request->amount < $order->amountDue(),
            ]);

            $amountPaid = (float) $order->payments()->sum('amount');
            // Ajout d'une tolérance de rounding (1 unité de devise) pour marquer comme payé
            if ($amountPaid >= ($order->total - 1)) {
                $order->update(['status' => 'paid', 'paid_at' => now(), 'cashier_id' => Auth::id()]);

                if ($order->table_id) {
                    \App\Models\Table::where('id', $order->table_id)->update([
                        'status'           => 'free',
                        'occupied_since'   => null,
                        'assigned_user_id' => null,
                    ]);
                }

                // Marquer tous les articles comme servis puisque la commande est payée
                $order->items()
                    ->whereNotIn('status', ['cancelled', 'served'])
                    ->update(['status' => 'served', 'served_at' => now()]);

                ProcessStockDeduction::dispatch($order);
            }

            $order->logActivity('payment', "Paiement de {$request->amount} FCFA par {$request->input('method')}");
        });

        return response()->json([
            'order'   => $order->fresh(['items', 'payments']),
            'message' => 'Paiement enregistré.',
        ], 201);
    }

    public function split(Request $request)
    {
        $request->validate([
            'order_id'     => 'required|exists:orders,id',
            'payments'     => 'required|array|min:1',
            'payments.*.amount' => 'required|numeric|min:0.01',
            'payments.*.method' => 'required|in:cash,card,wave,orange_money,momo,moov,mixx,other',
            'payments.*.reference' => 'nullable|string',
            'customer_name' => 'nullable|string|max:150',
        ]);

        $order = Order::findOrFail($request->order_id);
        abort_if($order->restaurant_id !== $request->user()->restaurant_id, 403);

        $totalPayments = collect($request->payments)->sum('amount');
        abort_if($totalPayments < $order->total, 422, "Montant insuffisant. Requis: {$order->total} FCFA");

        DB::transaction(function () use ($request, $order) {
            $session = CashSession::where('restaurant_id', $request->user()->restaurant_id)
                ->whereNull('closed_at')->latest()->first();

            foreach ($request->payments as $p) {
                Payment::create([
                    'order_id'        => $order->id,
                    'cash_session_id' => $session?->id,
                    'user_id'         => Auth::id(),
                    'amount'          => $p['amount'],
                    'method'          => $p['method'],
                    'reference'       => $p['reference'] ?? null,
                    'is_partial'      => true,
                ]);
            }

            $updateData = ['status' => 'paid', 'paid_at' => now(), 'cashier_id' => Auth::id()];
            if ($request->customer_name) {
                $updateData['customer_name'] = $request->customer_name;
            }
            $order->update($updateData);

            if ($order->table_id) {
                $order->table->update(['status' => 'free', 'occupied_since' => null, 'assigned_user_id' => null]);
            }

            ProcessStockDeduction::dispatch($order);

            $order->logActivity('payment_split', "Paiement multiple : " . collect($request->payments)->map(fn($p) => "{$p['amount']} FCFA par {$p['method']}")->join(', '));
        });

        return response()->json(['message' => 'Paiement divisé enregistré.']);
    }
}
