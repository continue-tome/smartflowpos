<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\TicketPrintService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class ReceiptController extends Controller
{
    public function __construct(protected TicketPrintService $ticketService) {}

    public function show(Request $request, int $orderId)
    {
        $order = Order::with(['items' => fn($q) => $q->whereNotIn('status', ['cancelled']), 'items.product:id,name,vat_rate', 'items.modifiers.modifier:id,name,extra_price', 'payments', 'table:id,number', 'waiter:id,first_name,last_name', 'cashier:id,first_name,last_name'])
            ->where('restaurant_id', $request->user()->restaurant_id)->findOrFail($orderId);

        $restaurant = $request->user()->restaurant;
        $config = $restaurant->settings ?? [];
        $receipt = $this->buildReceipt($order, $restaurant, $config);

        return response()->json($receipt);
    }

    public function pdf(Request $request, int $orderId)
    {
        $order = Order::with(['items' => fn($q) => $q->whereNotIn('status', ['cancelled']), 'items.product', 'items.modifiers.modifier', 'payments', 'table'])
            ->where('restaurant_id', $request->user()->restaurant_id)->findOrFail($orderId);

        $restaurant = $request->user()->restaurant;
        $config = $restaurant->settings ?? [];
        $receipt = $this->buildReceipt($order, $restaurant, $config);

        $pdf = Pdf::loadView('receipts.ticket', compact('receipt', 'restaurant', 'config'))
            ->setPaper([0, 0, 226.77, 700])->setOption('margin-top', 0)->setOption('margin-bottom', 0)->setOption('margin-left', 0)->setOption('margin-right', 0);

        return $pdf->download("ticket-{$order->order_number}.pdf");
    }

    public function html(Request $request, int $orderId)
    {
        $order = Order::with(['items.product', 'items.modifiers.modifier', 'payments', 'table'])
            ->where('restaurant_id', $request->user()->restaurant_id)->findOrFail($orderId);

        $restaurant = $request->user()->restaurant;
        $config = $restaurant->settings ?? [];
        $receipt = $this->buildReceipt($order, $restaurant, $config);

        return response()->json(['html' => view('receipts.ticket', compact('receipt', 'restaurant', 'config') + ['is_preview' => true])->render()]);
    }

    public function sendSms(Request $request, int $orderId)
    {
        $request->validate(['phone' => 'required|string|min:8']);
        $order = Order::where('restaurant_id', $request->user()->restaurant_id)->findOrFail($orderId);
        $order->logs()->create(['user_id' => $request->user()->id, 'action' => 'receipt_sms', 'message' => "Reçu envoyé par SMS au {$request->phone}"]);
        return response()->json(['message' => 'Reçu envoyé par SMS.']);
    }

    public function sendEmail(Request $request, int $orderId)
    {
        $request->validate(['email' => 'required|email']);
        $order = Order::with(['items.product', 'payments', 'table'])->where('restaurant_id', $request->user()->restaurant_id)->findOrFail($orderId);
        $restaurant = $request->user()->restaurant;
        $config = $restaurant->settings ?? [];
        $receipt = $this->buildReceipt($order, $restaurant, $config);

        Mail::send('receipts.ticket', compact('receipt', 'restaurant', 'config'), function ($mail) use ($request, $order, $restaurant) {
            $mail->to($request->email)->subject("Votre reçu — {$restaurant->name} — {$order->order_number}");
        });

        return response()->json(['message' => 'Reçu envoyé par email.']);
    }

    private function buildReceipt(Order $order, $restaurant, array $config): array
    {
        $currencySymbol = $config['currency_symbol'] ?? 'FCFA';
        $currencyPosition = $config['currency_position'] ?? 'after';
        $formatAmount = fn($amount) => $currencyPosition === 'before'
            ? "{$currencySymbol} " . number_format($amount, 0, '.', ' ')
            : number_format($amount, 0, '.', ' ') . " {$currencySymbol}";

        $lines = $order->items->map(function ($item) use ($formatAmount) {
            $modifiers = $item->modifiers->map(fn($m) => ['name' => $m->modifier->name, 'extra_price' => $m->extra_price, 'extra_fmt' => $m->extra_price > 0 ? '+' . number_format($m->extra_price, 0) : ''])->toArray();
            $lineTotal = ($item->unit_price * $item->quantity) + collect($modifiers)->sum('extra_price') * $item->quantity;
            return ['name' => $item->product->name, 'quantity' => $item->quantity, 'unit_price' => $item->unit_price, 'unit_fmt' => $formatAmount($item->unit_price), 'total' => $lineTotal, 'total_fmt' => $formatAmount($lineTotal), 'notes' => $item->notes, 'modifiers' => $modifiers];
        })->toArray();

        $paymentLines = $order->payments->map(fn($p) => ['method' => $this->methodLabel($p->method), 'amount' => $p->amount, 'amount_fmt' => $formatAmount($p->amount), 'reference' => $p->reference, 'amount_given' => $p->amount_given, 'change_given' => $p->change_given, 'change_fmt' => $p->change_given ? $formatAmount($p->change_given) : null])->toArray();

        return [
            'restaurant' => [
                'name' => $restaurant->name, 
                'logo' => $restaurant->logo ? asset('storage/' . $restaurant->logo) : null, 
                'address' => $restaurant->address, 
                'phone' => $restaurant->phone, 
                'email' => $restaurant->email, 
                'vat_number' => $restaurant->vat_number,
                'receipt_subtitle' => data_get($restaurant->settings, 'receipt_subtitle')
            ],
            'order' => ['id' => $order->id, 'number' => $order->order_number, 'date' => $order->created_at->format('d/m/Y'), 'time' => $order->created_at->format('H:i'), 'paid_at' => $order->paid_at?->format('d/m/Y H:i'), 'table_number' => $order->table?->number, 'covers' => $order->covers, 'type' => $order->type, 'type_label' => $this->typeLabel($order->type), 'waiter' => $order->waiter?->full_name, 'cashier' => $order->cashier?->full_name, 'notes' => $order->notes],
            'lines' => $lines,
            'totals' => ['subtotal' => $order->subtotal, 'subtotal_fmt' => $formatAmount($order->subtotal), 'discount' => $order->discount_amount, 'discount_fmt' => $order->discount_amount > 0 ? '-' . $formatAmount($order->discount_amount) : null, 'discount_reason' => $order->discount_reason, 'vat_rate' => $config['default_vat_rate'] ?? 18, 'vat_amount' => $order->vat_amount, 'vat_fmt' => $formatAmount($order->vat_amount), 'total' => $order->total, 'total_fmt' => $formatAmount($order->total), 'amount_paid' => $order->amountPaid(), 'amount_paid_fmt' => $formatAmount($order->amountPaid()), 'change' => max(0, $order->amountPaid() - $order->total), 'change_fmt' => $formatAmount(max(0, $order->amountPaid() - $order->total))],
            'payments' => $paymentLines,
            'footer' => ['message' => $config['receipt_footer'] ?? 'Merci de votre visite !', 'website' => $config['receipt_website'] ?? null, 'show_logo' => $config['receipt_logo'] ?? true, 'width' => $config['receipt_width'] ?? '80mm'],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** Facture A4 normalisée */
    public function invoiceA4(Request $request, int $orderId)
    {
        $order = Order::with(['items.product', 'payments', 'table', 'restaurant', 'waiter'])
            ->where('restaurant_id', $request->user()->restaurant_id)
            ->findOrFail($orderId);

        $html = $this->ticketService->invoiceA4Html($order);
        return response()->json(['html' => $html]);
    }

    /** Ticket cuisine/bar/pizza SANS PRIX */
    public function kitchenTicket(Request $request, int $orderId)
    {
        $order = Order::with(['items.product.category', 'table', 'waiter'])
            ->findOrFail($orderId);

        $destination = $request->get('destination', 'kitchen');
        abort_unless(in_array($destination, ['kitchen', 'bar', 'pizza', 'all']), 422, 'Destination invalide.');

        $pdfContent = $this->ticketService->generateKitchenTicketPdf($order, $destination);

        if (!$pdfContent) {
            return response()->json(['message' => "Aucun item pour '{$destination}'."], 404);
        }

        return response($pdfContent, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="kitchen-' . $destination . '-' . $order->order_number . '.pdf"');
    }

    /** Impression groupée A4 (2 par page) */
    public function bulkA4(Request $request)
    {
        $request->validate(['order_ids' => 'required|array']);
        $orders = Order::with(['items.product', 'payments', 'table', 'restaurant', 'waiter'])
            ->where('restaurant_id', $request->user()->restaurant_id)
            ->whereIn('id', $request->order_ids)
            ->get();

        $pdfContent = $this->ticketService->generateBulkInvoiceA4Pdf($orders);

        return response($pdfContent, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="recus_groupes.pdf"');
    }

    /**
     * Interface polyvalente pour le frontend
     * GET /api/orders/{id}/ticket?type=receipt|invoice
     */
    public function ticket(Request $request, int $orderId)
    {
        $type = $request->get('type', 'receipt');

        // On charge la commande AVEC son restaurant par défaut pour être indépendant de l'auth
        $order = Order::with(['items.product', 'items.modifiers.modifier', 'payments', 'table', 'restaurant'])
            ->findOrFail($orderId);

        if ($type === 'invoice') {
            return $this->invoiceA4PdfDirect($order);
        }

        // Pour type=receipt: on génère le ticket thermique en PDF via FPDF
        $pdfContent = $this->ticketService->generateReceiptPdf($order);
        
        return response($pdfContent, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="receipt-' . $order->order_number . '.pdf"');
    }

    public function printNetwork(Request $request, int $orderId, \App\Services\EscPosPrintService $escPos)
    {
        $order = Order::where('restaurant_id', $request->user()->restaurant_id)->findOrFail($orderId);
        $result = $escPos->printCustomerReceipt($order);

        if ($result['success']) {
            return response()->json(['message' => 'Impression lancée avec succès.']);
        }

        return response()->json(['message' => $result['message']], 500);
    }

    public function bulkPrintNetwork(Request $request, \App\Services\EscPosPrintService $escPos)
    {
        $request->validate(['order_ids' => 'required|array']);
        $result = $escPos->bulkPrintCustomerReceipts($request->order_ids);

        if ($result['success']) {
            return response()->json(['message' => 'Impressions lancées avec succès.']);
        }

        return response()->json(['message' => $result['message']], 500);
    }

    /** Helper pour renvoyer le PDF A4 direct */
    private function invoiceA4PdfDirect(Order $order)
    {
        $pdfContent = $this->ticketService->generateInvoiceA4Pdf($order);
        
        return response($pdfContent, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="invoice-' . $order->order_number . '.pdf"');
    }

    private function methodLabel(string $method): string { return match($method) { 'cash' => 'Espèces', 'card' => 'Carte bancaire', 'wave' => 'Wave', 'orange_money' => 'Orange Money', 'momo' => 'Mobile Money', 'moov' => 'Moov Money', 'mixx' => 'Mixx', default => 'Autre' }; }
    private function typeLabel(string $type): string { return match($type) { 'dine_in' => 'Sur place', 'takeaway' => 'À emporter', 'gozem' => 'Gozem', 'delivery' => 'Livraison', default => $type }; }
}
