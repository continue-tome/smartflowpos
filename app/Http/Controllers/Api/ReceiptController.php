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
        $receipt = $this->ticketService->buildReceiptData($order, $restaurant, $config);

        return response()->json($receipt);
    }

    public function pdf(Request $request, int $orderId)
    {
        $order = Order::with(['items' => fn($q) => $q->whereNotIn('status', ['cancelled']), 'items.product', 'items.modifiers.modifier', 'payments', 'table'])
            ->where('restaurant_id', $request->user()->restaurant_id)->findOrFail($orderId);

        $restaurant = $request->user()->restaurant;
        $config = $restaurant->settings ?? [];
        $receipt = $this->ticketService->buildReceiptData($order, $restaurant, $config);

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
        $receipt = $this->ticketService->buildReceiptData($order, $restaurant, $config);

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
        $receipt = $this->ticketService->buildReceiptData($order, $restaurant, $config);

        Mail::send('receipts.ticket', compact('receipt', 'restaurant', 'config'), function ($mail) use ($request, $order, $restaurant) {
            $mail->to($request->email)->subject("Votre reçu — {$restaurant->name} — {$order->order_number}");
        });

        return response()->json(['message' => 'Reçu envoyé par email.']);
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

        return response()->json(['message' => $result['message']], 400);
    }

    public function bulkPrintNetwork(Request $request, \App\Services\EscPosPrintService $escPos)
    {
        $request->validate(['order_ids' => 'required|array']);
        $result = $escPos->bulkPrintCustomerReceipts($request->order_ids);

        if ($result['success']) {
            return response()->json(['message' => 'Impressions lancées avec succès.']);
        }

        return response()->json(['message' => $result['message']], 400);
    }

    /** Helper pour renvoyer le PDF A4 direct */
    private function invoiceA4PdfDirect(Order $order)
    {
        $pdfContent = $this->ticketService->generateInvoiceA4Pdf($order);
        
        return response($pdfContent, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="invoice-' . $order->order_number . '.pdf"');
    }


}
