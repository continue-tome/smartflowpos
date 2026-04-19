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
            'notes'           => 'nullable|string',
        ]);

        $total = collect($request->items)->sum(fn($i) => $i['qty'] * $i['unit_price']);
        $advance = $request->advance_paid ?? 0;

        $session = CashSession::where('restaurant_id', $request->user()->restaurant_id)
            ->whereNull('closed_at')->latest()->first();

        $order = DB::transaction(function() use ($request, $total, $advance, $session) {
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
            ]);

            if ($advance > 0) {
                Payment::create([
                    'cake_order_id'   => $order->id,
                    'cash_session_id' => $session?->id,
                    'user_id'         => $request->user()->id,
                    'amount'          => $advance,
                    'method'          => 'cash',
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
            'payment_method'    => 'required_without:method|in:cash,card,wave,orange_money,momo,moov,mixx,bank,other',
            'method'            => 'required_without:payment_method|in:cash,card,wave,orange_money,momo,moov,mixx,bank,other',
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

        return response()->json([
            'message' => 'Commande encaissée avec succès.',
            'order'   => $cakeOrder->fresh(),
        ]);
    }

    /** Ticket de confirmation gâteau — format 58mm */
    public function ticket(Request $request, CakeOrder $cakeOrder)
    {
        abort_if($cakeOrder->restaurant_id !== $request->user()->restaurant_id, 403);

        $items = collect($cakeOrder->items);
        $itemsHtml = '';
        foreach ($items as $item) {
            $note = isset($item['notes']) && $item['notes']
                ? "<div style='font-size:10px;font-style:italic;padding-left:22px'>{$item['notes']}</div>"
                : '';
            $price = number_format($item['unit_price'], 0, ',', ' ');
            $total = number_format($item['qty'] * $item['unit_price'], 0, ',', ' ');
            $itemsHtml .= "
            <div style='display:flex;justify-content:space-between;padding:3px 0'>
                <span><strong>x{$item['qty']}</strong> {$item['name']}</span>
                <span>{$total} FCFA</span>
            </div>{$note}";
        }

        $total     = number_format((float)$cakeOrder->total, 0, ',', ' ');
        $advance   = number_format((float)$cakeOrder->advance_paid, 0, ',', ' ');
        $remaining = number_format((float)$cakeOrder->remaining_amount, 0, ',', ' ');
        $dDate     = \Carbon\Carbon::parse($cakeOrder->delivery_date)->format('d/m/Y');
        $dTime     = $cakeOrder->delivery_time ? " à {$cakeOrder->delivery_time}" : '';

        $html = "<!DOCTYPE html>
<html>
<head>
<meta charset='UTF-8'>
<style>
  body { font-family:'Courier New',monospace; font-size:12px; width:58mm; padding:4px; }
  .header { text-align:center; border-bottom:2px dashed #000; padding-bottom:6px; margin-bottom:6px; }
  .divider { border-top:1px dashed #000; margin:4px 0; }
  .footer { text-align:center; margin-top:8px; font-size:10px; }
  @media print { @page { margin:0; } }
</style>
</head>
<body>
  <div class='header'>
    <div style='font-size:14px;font-weight:bold'>🎂 COMMANDE GÂTEAU</div>
    <div>#{$cakeOrder->order_number}</div>
  </div>
  <div><strong>Client:</strong> {$cakeOrder->customer_name}</div>
  <div><strong>Tél:</strong> {$cakeOrder->customer_phone}</div>
  <div><strong>Livraison:</strong> {$dDate}{$dTime}</div>
  <div class='divider'></div>
  {$itemsHtml}
  <div class='divider'></div>
  <div style='display:flex;justify-content:space-between'><span>TOTAL</span><span>{$total} FCFA</span></div>
  <div style='display:flex;justify-content:space-between'><span>Acompte</span><span>{$advance} FCFA</span></div>
  <div style='display:flex;justify-content:space-between;font-weight:bold'><span>RESTE</span><span>{$remaining} FCFA</span></div>
  <div class='footer'>Merci pour votre commande !<br>Certifié par Omega POS</div>
</body>
</html>";

        return response($html)->header('Content-Type', 'text/html');
    }

    /**
     * Générer un PDF professionnel (Bon de commande) via DomPDF
     */
    public function receiptPdf(CakeOrder $cakeOrder)
    {
        $cakeOrder->load(['restaurant', 'cashier']);
        $restaurant = $cakeOrder->restaurant;
        
        $total = number_format((float)$cakeOrder->total, 0, ',', ' ');
        $advance = number_format((float)$cakeOrder->advance_paid, 0, ',', ' ');
        $remaining = number_format((float)$cakeOrder->remaining_amount, 0, ',', ' ');
        $date = \Carbon\Carbon::parse($cakeOrder->delivery_date)->locale('fr')->isoFormat('LL');
        $time = $cakeOrder->delivery_time ? substr($cakeOrder->delivery_time, 0, 5) : 'Non spécifiée';

        $html = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta http-equiv='Content-Type' content='text/html; charset=utf-8'/>
            <style>
                @page { margin: 0; }
                body { font-family: Helvetica, sans-serif; color: #1e293b; margin: 0; padding: 0; background: #fff; }
                .container { padding: 50px; }
                .header { border-bottom: 2px solid #f1f5f9; padding-bottom: 20px; margin-bottom: 30px; }
                .restaurant-name { font-size: 24px; font-weight: bold; color: #0f172a; text-transform: uppercase; }
                .restaurant-info { font-size: 10px; color: #64748b; margin-top: 5px; }
                
                .doc-type { float: right; background: #db2777; color: white; padding: 10px 20px; border-radius: 5px; font-weight: bold; font-size: 14px; margin-top: -60px; }
                
                .title-section { margin-top: 20px; }
                .doc-title { font-size: 18px; font-weight: bold; color: #db2777; }
                .doc-ref { font-size: 12px; color: #64748b; margin-top: 5px; }
                
                .info-grid { width: 100%; margin-top: 40px; border-collapse: collapse; }
                .info-box { width: 48%; background: #f8fafc; padding: 20px; border-radius: 10px; vertical-align: top; }
                .info-label { font-size: 9px; font-weight: bold; color: #94a3b8; text-transform: uppercase; margin-bottom: 8px; }
                .info-value { font-size: 13px; font-weight: bold; color: #1e293b; }
                .info-sub { font-size: 11px; color: #64748b; margin-top: 4px; }

                .items-table { width: 100%; margin-top: 40px; border-collapse: collapse; }
                .items-table th { background: #0f172a; color: white; padding: 12px; text-align: left; font-size: 10px; text-transform: uppercase; }
                .items-table td { padding: 15px 12px; border-bottom: 1px solid #f1f5f9; font-size: 12px; }
                
                .notes-section { margin-top: 30px; background: #f8fafc; padding: 15px; border-left: 4px solid #db2777; border-radius: 0 5px 5px 0; }
                .notes-title { font-size: 10px; font-weight: bold; color: #94a3b8; text-transform: uppercase; margin-bottom: 5px; }
                .notes-text { font-size: 11px; font-style: italic; color: #475569; }

                .totals-section { width: 100%; margin-top: 40px; }
                .total-row { padding: 8px 0; font-size: 12px; }
                .total-label { text-align: right; padding-right: 20px; color: #64748b; }
                .total-value { text-align: right; width: 150px; font-weight: bold; }
                .final-balance { background: #0f172a; color: white; }
                .final-balance td { padding: 15px 20px; font-size: 16px; }

                .footer { position: fixed; bottom: 40px; width: 100%; text-align: center; font-size: 9px; color: #94a3b8; border-top: 1px solid #f1f5f9; padding-top: 20px; }
                .signature-table { width: 100%; margin-top: 60px; }
                .signature-box { border-top: 1px dashed #cbd5e1; padding-top: 10px; text-align: center; font-size: 10px; color: #64748b; width: 40%; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <div class='restaurant-name'>{$restaurant->name}</div>
                    <div class='restaurant-info'>
                        {$restaurant->address}<br>
                        Tél: {$restaurant->phone} | Email: {$restaurant->email}
                    </div>
                </div>
                
                <div class='doc-type'>BON DE COMMANDE</div>

                <div class='title-section'>
                    <div class='doc-title'>CONFIRMATION DE COMMANDE GÂTEAU</div>
                    <div class='doc-ref'>Réf: #{$cakeOrder->order_number} | Emis le " . date('d/m/Y à H:i') . "</div>
                </div>

                <table class='info-grid'>
                    <tr>
                        <td class='info-box'>
                            <div class='info-label'>Client</div>
                            <div class='info-value'>{$cakeOrder->customer_name}</div>
                            <div class='info-sub'>Tél: {$cakeOrder->customer_phone}</div>
                        </td>
                        <td width='4%'></td>
                        <td class='info-box'>
                            <div class='info-label'>Livraison Prévue</div>
                            <div class='info-value'>{$date}</div>
                            <div class='info-sub' style='color:#db2777; font-weight:bold;'>à {$time}</div>
                        </td>
                    </tr>
                </table>

                <table class='items-table'>
                    <thead>
                        <tr>
                            <th>Description du Gâteau</th>
                            <th style='text-align: center;'>Quantité</th>
                            <th style='text-align: right;'>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td style='font-weight: bold;'>Gâteau Personnalisé</td>
                            <td style='text-align: center;'>1</td>
                            <td style='text-align: right; font-weight: bold;'>{$total} FCFA</td>
                        </tr>
                    </tbody>
                </table>

                <div class='notes-section'>
                    <div class='notes-title'>Détails & Personnalisation</div>
                    <div class='notes-text'>" . nl2br($cakeOrder->notes ?: 'Gâteau personnalisé selon spécifications.') . "</div>
                </div>

                <table class='totals-section' align='right'>
                    <tr class='total-row'>
                        <td class='total-label'>Sous-total</td>
                        <td class='total-value'>{$total} FCFA</td>
                    </tr>
                    <tr class='total-row' style='color: #059669;'>
                        <td class='total-label'>Acompte Reçu</td>
                        <td class='total-value'>- {$advance} FCFA</td>
                    </tr>
                    <tr class='final-balance'>
                        <td class='total-label' style='color: white;'>SOLDE À PAYER</td>
                        <td class='total-value'>{$remaining} FCFA</td>
                    </tr>
                </table>

                <table class='signature-table'>
                    <tr>
                        <td class='signature-box'>Signature Client</td>
                        <td width='20%'></td>
                        <td class='signature-box'>Cachet & Signature Établissement</td>
                    </tr>
                </table>

                <div class='footer'>
                    Ce document fait office de bon de commande officiel.<br>
                    #{$cakeOrder->order_number} • Établi par " . ($cakeOrder->cashier->first_name ?? 'Système') . " • Omega POS
                </div>
            </div>
        </body>
        </html>
        ";

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html);
        return $pdf->download("Confirmation_Gateau_{$cakeOrder->order_number}.pdf");
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
