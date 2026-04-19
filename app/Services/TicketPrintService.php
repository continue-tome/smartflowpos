<?php

namespace App\Services;

use App\Models\Order;
use App\Models\CashSession;
use App\Services\OrderRoutingService;
use App\Libraries\Fpdf;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * TicketPrintService
 * Génère le HTML des tickets d'impression:
 * - Ticket cuisine/bar/pizza (SANS prix) format 58mm
 * - Reçu client (AVEC prix) format 58mm
 * - Facture A4 normalisée
 */
class TicketPrintService
{
    public function __construct(
        protected OrderRoutingService $routing
    ) {}

    /**
     * Ticket destination (cuisine/bar/pizza) — SANS PRIX
     * Format 58mm pour imprimante thermique
     */
    public function kitchenTicketHtml(Order $order, string $destination = 'kitchen'): string
    {
        $order->loadMissing([
            'items.product.category',
            'table',
            'waiter',
        ]);

        // Filtrer uniquement les items de cette destination
        $filteredItems = $order->items->whereNotIn('status', ['cancelled']);
        $allGroups = $this->routing->groupByDestination($filteredItems);
        $items = $allGroups[$destination] ?? collect();

        if ($items->isEmpty()) return '';

        $label = $this->routing->destinationLabel($destination);
        $icon  = $this->routing->destinationIcon($destination);
        
        $locationLabel = '';
        if ($order->table instanceof \App\Models\Table) {
            $locationLabel = "TABLE " . $order->table->number;
        } else {
            $locationLabel = strtoupper($order->type === 'gozem' ? 'GOZEM' : ($order->type === 'takeaway' ? 'À EMPORTER' : 'VENTE DIRECTE'));
        }
        
        $time  = now()->format('H:i');
        $date  = now()->format('d/m/Y');

        $itemsHtml = '';
        foreach ($items as $item) {
            $note = $item->notes ? "<div class='note'>⚠ {$item->notes}</div>" : '';
            $itemsHtml .= "
            <div class='item'>
                <span class='qty'>x{$item->quantity}</span>
                <span class='name'>{$item->product->name}</span>
                {$note}
            </div>";
        }

        $customerInfo = '';
        if ($order->type === 'gozem' && $order->customer_name) {
            $customerInfo = "<div class='customer'>Client: {$order->customer_name} | {$order->customer_phone}</div>";
        }

        $orderNote = $order->notes
            ? "<div class='order-note'>📝 {$order->notes}</div>"
            : '';

        $restaurant = $order->restaurant;
        $logoHtml = ($restaurant->logo && file_exists(storage_path('app/public/' . $restaurant->logo)))
            ? "<img src='{$restaurant->logo_url}' style='max-width:40mm;max-height:15mm;display:block;margin:0 auto 4px'>" 
            : "";

        $restaurant = $order->restaurant;
        $restoPhone = $restaurant->phone ? " | Tél: {$restaurant->phone}" : '';
        
        return "<!DOCTYPE html>
<html>
<head>
<meta charset='UTF-8'>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: 'Courier New', monospace; font-size: 13px; width: 100%; max-width: 80mm; margin: 0 auto; padding: 4px; overflow: hidden; position: relative; }
  .header { text-align: center; border-bottom: 2px dashed #000; padding-bottom: 6px; margin-bottom: 6px; position: relative; }
  .destination { font-size: 18px; font-weight: bold; letter-spacing: 2px; }
  .order-num { font-size: 12px; margin-top: 2px; font-weight: bold; }
  .meta { font-size: 11px; color: #000; font-weight: 800; border: 1px solid #000; display: inline-block; padding: 2px 8px; margin-top: 4px; }
  .divider { border-top: 1px dashed #000; margin: 4px 0; }
  .item { display: flex; gap: 8px; padding: 5px 0; align-items: flex-start; border-bottom: 0.5px solid #eee; }
  .qty { font-weight: 900; font-size: 18px; min-width: 25px; }
  .name { font-size: 14px; font-weight: 900; flex: 1; text-transform: uppercase; }
  .note { font-size: 11px; color: #000; padding-left: 33px; font-weight: bold; font-style: italic; }
  .customer { margin-top: 8px; font-size: 10px; border-top: 1px dashed #000; padding-top: 4px; }
  .order-note { font-size: 12px; font-weight: bold; border: 2px solid #000; padding: 4px; margin-top: 6px; }
  .footer-name { text-align: center; font-size: 10px; font-weight: bold; margin-top: 10px; text-transform: uppercase; border-top: 1px solid #000; padding-top: 4px; }
  @media print { @page { margin: 0; } }
</style>
</head>
<body>
  {$logoHtml}
  <div class='header'>
    <div class='destination'>{$icon} {$label}</div>
    <div class='order-num'>#{$order->order_number}</div>
    <div class='meta'>IFU: 1001580865 | {$locationLabel}{$restoPhone}</div>
  </div>
  <div class='items'>
    {$itemsHtml}
  </div>
  {$orderNote}
  {$customerInfo}
  <div class='footer-name'>
    {$restaurant->name}
    " . (data_get($restaurant->settings, 'receipt_subtitle') ? "<br><span style='font-size:8px;font-weight:normal'>" . data_get($restaurant->settings, 'receipt_subtitle') . "</span>" : "") . "
    <br>Omega POS
  </div>
</body>
</html>";
    }

    /**
     * Reçu client avec prix — format 58mm
     */
    public function receiptHtml(Order $order): string
    {
        $order->loadMissing(['items.product', 'table', 'restaurant', 'payments', 'waiter']);
        $restaurant = $order->restaurant;
        $items = $order->items->whereNotIn('status', ['cancelled']);

        $itemsHtml = '';
        foreach ($items as $item) {
            $subtotal  = number_format((float) ($item->quantity * $item->unit_price), 0, ',', ' ');
            $itemsHtml .= "
            <div class='line'>
                <span>{$item->quantity}x {$item->product->name}</span>
                <span>{$subtotal}</span>
            </div>";
        }

        $paymentsHtml = '';
        foreach ($order->payments as $pmt) {
            $method = strtoupper($pmt->method);
            $amt    = number_format($pmt->amount, 0, ',', ' ');
            $paymentsHtml .= "<div class='line'><span>{$method}</span><span>{$amt} FCFA</span></div>";

        }
        $totalGiven = $order->payments->sum('amount_given');
        $totalChange = $order->payments->sum('change_given');
        $givenChangeHtml = '';
        if ($totalGiven > 0) {
            $fmtGiven = number_format($totalGiven, 0, ',', ' ');
            $fmtChange = number_format($totalChange, 0, ',', ' ');
            $givenChangeHtml = "
            <div class='divider'></div>
            <div style='border:2px solid #000;padding:4px;margin:4px 0'>
              <div class='line total-line'><span>DONNÉ</span><span>{$fmtGiven} FCFA</span></div>
              <div class='line total-line'><span>RENDU</span><span>{$fmtChange} FCFA</span></div>
            </div>";
        }

        $typeLabel = match($order->type) {
            'dine_in'  => 'Sur place',
            'takeaway' => 'À emporter',
            'gozem'    => 'Livraison (Gozem)',
            default    => ucfirst($order->type),
        };
        $tableLabel  = $order->table ? "Table {$order->table->number}" : $typeLabel;
        $subtotal    = number_format((float) ($order->subtotal ?? 0), 0, ',', ' ');
        $discountRow = '';
        if ($order->discount_amount > 0) {
            $disc = number_format((float) $order->discount_amount, 0, ',', ' ');
            $discountRow = "<div class='line'><span>Remise</span><span>-{$disc} FCFA</span></div>";
        }
        $vatRate     = $restaurant->settings['default_vat_rate'] ?? 18;
        $vat         = number_format((float) ($order->vat_amount ?? 0), 0, ',', ' ');
        $total       = number_format((float) ($order->total ?? 0), 0, ',', ' ');
        $date        = now()->format('d/m/Y H:i');
        $serveur     = $order->waiter ? ($order->waiter->first_name . ' ' . $order->waiter->last_name) : null;
        $restoAddress = $restaurant->address ?? '';

        $logoHtml = ($restaurant->logo && file_exists(storage_path('app/public/' . $restaurant->logo)))
            ? "<img src='{$restaurant->logo_url}' style='max-width:40mm;max-height:15mm;display:block;margin:0 auto 4px'>" 
            : "";


        $thanksMsg   = $restaurant->settings['thank_you_message'] ?? 'Merci de votre visite !';

        return "<!DOCTYPE html>
<html>
<head>
<meta charset='UTF-8'>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: 'Courier New', monospace; font-size: 11px; width: 100%; max-width: 80mm; margin: 0 auto; padding: 4px; position: relative; overflow: hidden; }
  .header { text-align: center; margin-bottom: 6px; border-bottom: 1px dashed #000; padding-bottom: 4px; }
  .resto-name { font-size: 15px; font-weight: bold; text-transform: uppercase; }
  .line { display: flex; justify-content: space-between; padding: 2px 0; }
  .divider { border-top: 1px dashed #000; margin: 4px 0; }
  .total-line { font-weight: bold; font-size: 14px; }
  .footer { text-align: center; margin-top: 10px; font-size: 10px; font-weight: bold; border-top: 1px dashed #000; padding-top: 4px; }
  .table-box { border: 2px solid #000; font-weight: 900; text-align: center; padding: 4px; margin: 4px 0; font-size: 14px; }
  .paid-stamp { position: fixed; top: 30%; left: 50%; transform: translate(-50%, -50%) rotate(-30deg); font-size: 42px; font-weight: 900; color: rgba(0,128,0,0.15); padding: 6px 16px; letter-spacing: 4px; pointer-events: none; z-index: -1; }
  @media print { @page { margin: 0; } }
</style>
</head>
<body>
  {$logoHtml}
  <div class='header'>
    <div class='resto-name'>" . strtoupper($restaurant->name) . "</div>
    " . (data_get($restaurant->settings, 'receipt_subtitle') ? "<div style='font-size:10px; font-weight:bold; margin-bottom:2px; color:#555;'>" . data_get($restaurant->settings, 'receipt_subtitle') . "</div>" : "") . "
    " . ($restoAddress ? "<div style='font-size:9px'>{$restoAddress}</div>" : "") . "
    <div style='font-size:10px; font-weight:bold; margin-top:2px;'>IFU : 1001580865</div>
    " . ($restaurant->phone ? "<div style='font-size:9px'>Tél: {$restaurant->phone}</div>" : "") . "
    <div style='font-size:9px'>{$date}</div>
  </div>
  <div class='table-box'>{$tableLabel}</div>
  <div class='line'><span>Ticket</span><span>#{$order->order_number}</span></div>
  " . ($serveur ? "<div class='line'><span>Serveur</span><span>{$serveur}</span></div>" : "") . "
  <div class='divider'></div>
  {$itemsHtml}
  <div class='totals'>
    <div class='line'><span>Sous-total</span><span>{$subtotal} FCFA</span></div>
    {$discountRow}
    <div class='line total-line'><span>TOTAL TTC</span><span>{$total} FCFA</span></div>
    <div class='line'><small>Dont TVA ({$vatRate}%)</small><small>{$vat} FCFA</small></div>
  </div>
  <div class='divider'></div>
  {$givenChangeHtml}
  " . ($order->paid_at ? "<div class='paid-stamp'>PAYÉ</div>" : "") . "
  <div class='footer'>
    {$thanksMsg}<br>{$restaurant->name}
    <div style='margin-top:8px;'>
      <div style='font-size:8px; font-weight:bold; margin-bottom:2px;'>Hello, Scan moi</div>
      <img src='/img/website_qr.png' style='width:22mm; height:22mm; display:block; margin:0 auto;'>
    </div>
  </div>
</body>
</html>";
    }

    /**
     * Facture A4 normalisée
     */
    public function invoiceA4Html(Order $order): string
    {
        $order->loadMissing(['items.product', 'table', 'restaurant', 'payments', 'waiter']);
        $restaurant = $order->restaurant;
        $items      = $order->items->whereNotIn('status', ['cancelled']);

        $itemsHtml = '';
        $i = 1;
        foreach ($items as $item) {
            $subtotal = number_format((float) ($item->quantity * $item->unit_price), 0, ',', ' ');
            $price    = number_format($item->unit_price, 0, ',', ' ');
            $itemsHtml .= "
            <tr>
                <td>{$i}</td>
                <td>{$item->product->name}</td>
                <td style='text-align:center'>{$item->quantity}</td>
                <td style='text-align:right'>{$price}</td>
                <td style='text-align:right'>{$subtotal}</td>
            </tr>";
            $i++;
        }

        $paymentsHtml = '';
        foreach ($order->payments as $pmt) {
            $method = strtoupper($pmt->method);
            $amt    = number_format($pmt->amount, 0, ',', ' ');
            $paymentsHtml .= "<tr><td>{$method}</td><td style='text-align:right'>{$amt} FCFA</td></tr>";
        }
        $totalGiven = $order->payments->sum('amount_given');
        $totalChange = $order->payments->sum('change_given');
        $givenSummaryHtml = '';
        if ($totalGiven > 0) {
            $fmtGiven = number_format($totalGiven, 0, ',', ' ');
            $fmtChange = number_format($totalChange, 0, ',', ' ');
            $givenSummaryHtml = "
            <table style='width:50%;margin-top:8px;border-top:1px dashed #ccc;'>
              <tr><td style='font-weight:bold;padding:6px;border-bottom:1px solid #eee;'>DONNÉ PAR LE CLIENT</td><td style='text-align:right;font-weight:bold;padding:6px;border-bottom:1px solid #eee;'>{$fmtGiven} FCFA</td></tr>
              <tr><td style='font-weight:bold;padding:6px;'>MONNAIE RENDUE</td><td style='text-align:right;font-weight:bold;padding:6px;'>{$fmtChange} FCFA</td></tr>
            </table>";
        }
        $waiterName = $order->waiter ? ($order->waiter->first_name . ' ' . $order->waiter->last_name) : null;

        $locationLabel = $order->table ? "Table {$order->table->number}" : ucfirst(str_replace('_', ' ', $order->type));
        $subtotal    = number_format((float) ($order->subtotal ?? 0), 0, ',', ' ');
        $vatRate     = $restaurant->settings['default_vat_rate'] ?? 18;
        $vat         = number_format((float) ($order->vat_amount ?? 0), 0, ',', ' ');
        $discount    = ($order->discount_amount ?? 0) > 0 ? number_format((float) $order->discount_amount, 0, ',', ' ') : null;
        $total       = number_format((float) ($order->total ?? 0), 0, ',', ' ');
        $date        = now()->format('d/m/Y H:i');
        $invoiceNum  = 'INV-' . $order->order_number;
        $restoAddr   = $restaurant->address ?? '';
        $restoPhone  = $restaurant->phone ?? '';

        $discountRow = $discount
            ? "<tr><td>Remise</td><td colspan='3'>{$order->discount_reason}</td><td style='text-align:right'>- {$discount}</td></tr>"
            : '';

        $customerBlock = '';
        // Customer name hidden on invoices as requested

        $logoHtml = ($restaurant->logo && file_exists(storage_path('app/public/' . $restaurant->logo)))
            ? "<img src='{$restaurant->logo_url}' style='max-height:25mm;'>" 
            : "";

        $thanksMsg   = data_get($restaurant->settings, 'thank_you_message') ?? 'Merci pour votre confiance';
        $subtitle    = data_get($restaurant->settings, 'receipt_subtitle');

        return "<!DOCTYPE html>
<html>
<head>
<meta charset='UTF-8'>
<style>
  body { font-family: Arial, sans-serif; font-size: 11px; color: #222; margin: 10mm 12mm; position: relative; overflow: hidden; }
  .header { display: flex; justify-content: space-between; margin-bottom: 10px; }
  .resto-info h1 { font-size: 20px; color: #1a1a2e; margin-bottom: 2px; text-transform: uppercase; }
  .invoice-info { text-align: right; }
  .invoice-info h2 { font-size: 18px; color: #16213e; margin-bottom: 6px; }
  .divider { border: none; border-top: 2px solid #1a1a2e; margin: 6px 0; }
  .customer-block { padding: 8px 0; margin-bottom: 10px; border-bottom: 1px dashed #ccc; }
  table { width: 100%; border-collapse: collapse; margin-top: 6px; }
  th { background: transparent; color: #111; padding: 6px 8px; text-align: left; font-size: 10px; font-weight: bold; border-bottom: 2px solid #000; text-transform: uppercase; }
  td { padding: 5px 8px; border-bottom: 1px solid #eee; }
  .totals-table td { border: none; padding: 3px 8px; }
  .total-row td { font-weight: bold; font-size: 14px; border-top: 2px solid #000; padding-top: 6px; }
  .footer { margin-top: 15px; text-align: center; font-size: 10px; color: #666; border-top: 1px solid #eee; padding-top: 8px; }
  .table-badge { background: #eee; color: #111; padding: 3px 10px; border-radius: 4px; font-weight: bold; font-size: 12px; border: 1px solid #ccc; }
  .paid-watermark { position: absolute; top: 40%; left: 50%; transform: translate(-50%, -50%) rotate(-35deg); font-size: 96px; font-weight: 900; color: rgba(0,128,0,0.1); padding: 15px 50px; letter-spacing: 12px; pointer-events: none; z-index: -1; }
  .serveur-badge { background: transparent; border: 1px dashed #ccc; padding: 4px 10px; border-radius: 4px; font-size: 11px; display: inline-block; margin-bottom: 8px; }
  @media print { @page { size: A4; margin: 10mm; } }
</style>
</head>
<body>
  {$logoHtml}
  <div class='header'>
    <div class='resto-info'>
      <h1>{$restaurant->name}</h1>
      " . ($subtitle ? "<div style='font-size:12px; font-weight:bold; margin-bottom:5px; color:#555;'>{$subtitle}</div>" : "") . "
      " . ($restoAddr ? "<div>{$restoAddr}</div>" : "") . "
      " . ($restoPhone ? "<div>Tél: {$restoPhone}</div>" : "") . "
      " . ($restaurant->email ? "<div>{$restaurant->email}</div>" : "") . "
      <div style='font-size:14px; font-weight:bold; margin-top:5px; color:#000;'>IFU : 1001580865</div>
    </div>
    <div class='invoice-info'>
      <h2>FACTURE</h2>
      <div style='margin-bottom:8px'><span class='table-badge'>{$locationLabel}</span></div>
      <div>N° {$invoiceNum}</div>
      <div>Date: {$date}</div>
    </div>
  </div>
  <hr class='divider'>
  " . ($order->paid_at ? "<div class='paid-watermark'>PAYÉ</div>" : "") . "
  " . ($waiterName ? "<div class='serveur-badge'>Serveur : <strong>{$waiterName}</strong></div>" : "") . "
  {$customerBlock}
  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Article / Description</th>
        <th style='text-align:center'>Qté</th>
        <th style='text-align:right'>P.U. (FCFA)</th>
        <th style='text-align:right'>Total (FCFA)</th>
      </tr>
    </thead>
    <tbody>
      {$itemsHtml}
    </tbody>
  </table>
  <table class='totals-table' style='width:45%; margin-top:20px;'>
    <tr><td>Sous-total</td><td style='text-align:right'>{$subtotal} FCFA</td></tr>
    {$discountRow}
    <tr class='total-row'><td>TOTAL TTC</td><td style='text-align:right'>{$total} FCFA</td></tr>
    <tr><td><small>Dont TVA ({$vatRate}%)</small></td><td style='text-align:right'><small>{$vat} FCFA</small></td></tr>
  </table>
  <div style='margin-top:15px;'>
    <h3 style='border-bottom:1px solid #eee; padding-bottom:3px; font-size:11px; margin-bottom:6px;'>DÉTAILS PAIEMENT</h3>
    <table style='width:50%;'>
      {$paymentsHtml}
    </table>
    {$givenSummaryHtml}
  </div>
  <div class='footer'>
    {$thanksMsg} — {$restaurant->name} — Document certifié
  </div>
</body>
</html>";
    }

    /**

     * Génère la facture A4 en PDF via FPDF
     */
    public function generateInvoiceA4Pdf(Order $order): string
    {
        $order->loadMissing(['items.product', 'table', 'restaurant', 'payments', 'waiter']);
        $pdf = new Fpdf('P', 'mm', 'A4');
        $pdf->SetMargins(15, 10, 15);
        $pdf->AddPage();
        
        $this->renderInvoiceOnPdf($pdf, $order, 10);
        
        return $pdf->Output('S');
    }

    /**
     * Génération groupée A4 (2 par page) avec FPDF pour précision absolue
     */
    public function generateBulkInvoiceA4Pdf($orders): string
    {
        $pdf = new Fpdf('P', 'mm', 'A4');
        $pdf->SetMargins(15, 10, 15);
        $pdf->SetAutoPageBreak(false);

        foreach ($orders->chunk(2) as $pair) {
            $pdf->AddPage();
            
            // Premier ticket
            $this->renderInvoiceOnPdf($pdf, $pair->values()[0], 10);
            
            // Ligne de découpe au milieu
            $pdf->SetDrawColor(220, 220, 220);
            $pdf->Line(0, 148.5, 210, 148.5);
            
            // Second ticket (si existant)
            if (isset($pair->values()[1])) {
                $this->renderInvoiceOnPdf($pdf, $pair->values()[1], 158.5);
            }
        }
        
        return $pdf->Output('S');
    }

    /**
     * Helper pour dessiner une facture A4 sur une instance FPDF
     * @param Fpdf $pdf
     * @param Order $order
     * @param float $yStart Point de départ vertical
     */
    private function renderInvoiceOnPdf(Fpdf $pdf, Order $order, float $yStart)
    {
        $restaurant = $order->restaurant;
        $items      = $order->items->whereNotIn('status', ['cancelled']);
        
        $pdf->SetY($yStart);

        // Paid Stamp en ARRIÈRE-PLAN (Style filigrane)
        if ($order->paid_at) {
            $stampCX = 105; // Centre horizontal
            $stampCY = $yStart + 60; // Remonté (était à +75)
            $stampAngle = -30;

            $pdf->SetFont('Helvetica', 'B', 46); // Un peu plus grand sans le rectangle
            $pdf->SetTextColor(215, 235, 215);
            $pdf->RotatedText($stampCX - 25, $stampCY + 5, utf8_decode('PAYÉ'), $stampAngle);

            // Restaurer couleurs
            $pdf->SetTextColor(0, 0, 0);
        }

        // Header - Reduced size to fit all
        $pdf->SetFont('Helvetica', 'B', 16);
        $pdf->SetTextColor(26, 26, 46);
        $pdf->Cell(100, 8, utf8_decode(strtoupper($restaurant->name)), 0, 0);
        
        $pdf->SetFont('Helvetica', 'B', 14);
        $pdf->SetTextColor(50, 50, 100);
        $pdf->Cell(80, 8, 'FACTURE', 0, 1, 'R');

        $subtitle = data_get($restaurant->settings, 'receipt_subtitle');
        if ($subtitle) {
            $pdf->SetFont('Helvetica', 'B', 8);
            $pdf->SetTextColor(80, 80, 80);
            $pdf->Cell(100, 4, utf8_decode(strtoupper($subtitle)), 0, 1);
        }
        $pdf->SetTextColor(0, 0, 0);

        // Restaurant Info
        $pdf->SetFont('Helvetica', '', 8);
        $addr = $restaurant->address ? utf8_decode($restaurant->address) : '';
        $pdf->Cell(100, 4, $addr, 0, 0);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell(80, 4, 'Ref : INV-' . $order->order_number, 0, 1, 'R');
        
        $pdf->SetFont('Helvetica', '', 8);
        $phone = $restaurant->phone ? 'Tel : ' . $restaurant->phone : '';
        $pdf->Cell(100, 4, $phone, 0, 0);
        $pdf->Cell(80, 4, 'Date : ' . $order->created_at->format('d/m/Y H:i'), 0, 1, 'R');

        if ($restaurant->vat_number) {
            $pdf->Cell(60, 4, 'TVA : ' . $restaurant->vat_number, 0, 0, 'L');
        }
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->Cell(120, 4, 'IFU : 1001580865', 0, 1, 'R');

        $pdf->Ln(3);

        // Info Commande
        $yBefore = $pdf->GetY();
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell(0, 5, utf8_decode('DÉTAILS COMMANDE'), 'B', 1, 'L');

        $pdf->SetFont('Helvetica', '', 8);
        $typeLabel = match($order->type) {
            'dine_in'  => 'Sur place',
            'takeaway' => 'À emporter',
            'gozem'    => 'Gozem',
            default    => ucfirst($order->type),
        };
        
        $pdf->Cell(50, 4, 'Type: ' . utf8_decode(strtoupper($typeLabel)), 0, 0);
        if ($order->table) {
            $pdf->Cell(45, 4, 'Table: ' . $order->table->number, 0, 0);
        }
        
        $pdf->SetFont('Helvetica', '', 8);
        if ($order->waiter) {
            $pdf->Cell(95, 4, 'Serveur: ' . utf8_decode($order->waiter->first_name), 0, 1);
        } else {
            $pdf->Ln(4);
        }

        // Table Header
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell(10, 6, '#', 'B', 0, 'C');
        $pdf->Cell(100, 6, 'Article / Description', 'B', 0, 'L');
        $pdf->Cell(15, 6, 'Qt', 'B', 0, 'C');
        $pdf->Cell(25, 6, 'P.U.', 'B', 0, 'R');
        $pdf->Cell(30, 6, 'Total', 'B', 1, 'R');

        // Table Body
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Helvetica', '', 8);
        $i = 1;
        // Limit items to ensure everything fits (around 8 items if payments are detailed)
        foreach ($items->take(10) as $item) {
            $subtotal = $item->quantity * $item->unit_price;
            $pdf->Cell(10, 5, $i++, 'B', 0, 'C');
            $pdf->Cell(100, 5, utf8_decode(substr($item->product->name, 0, 50)), 'B', 0, 'L');
            $pdf->Cell(15, 5, $item->quantity, 'B', 0, 'C');
            $pdf->Cell(25, 5, number_format($item->unit_price, 0, '.', ' '), 'B', 0, 'R');
            $pdf->Cell(30, 5, number_format($subtotal, 0, '.', ' '), 'B', 1, 'R');
        }
        if ($items->count() > 10) {
            $pdf->Cell(180, 5, '... (Voir suite sur ticket original)', 0, 1, 'C');
        }

        // Totals & Payments
        $pdf->Ln(2);
        
        // Block 1: Totals (Now on the left, above payments)
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->Cell(45, 5, 'Sous-total', 0, 0, 'L');
        $pdf->Cell(35, 5, number_format((float) $order->subtotal, 0, '.', ' ') . ' FCFA', 0, 1, 'R');
        
        if (($order->discount_amount ?? 0) > 0) {
            $pdf->Cell(45, 5, 'Remise', 0, 0, 'L');
            $pdf->Cell(35, 5, '-' . number_format((float) $order->discount_amount, 0, '.', ' ') . ' FCFA', 0, 1, 'R');
        }

        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(45, 7, utf8_decode('TOTAL TTC'), 'T,B', 0, 'L');
        $pdf->Cell(35, 7, number_format((float) $order->total, 0, '.', ' ') . ' FCFA', 'T,B', 1, 'R');

        $pdf->SetFont('Helvetica', '', 8);
        $pdf->Cell(45, 5, 'Dont TVA (' . ($restaurant->settings['default_vat_rate'] ?? 18) . '%)', 0, 0, 'L');
        $pdf->Cell(35, 5, number_format((float) $order->vat_amount, 0, '.', ' ') . ' FCFA', 0, 1, 'R');
        
        $pdf->Ln(4);

        // Block 2: Payment Details (Below totals)
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->SetTextColor(100);
        $pdf->Cell(95, 4, utf8_decode('DÉTAILS PAIEMENT'), 'B', 1, 'L');
        $pdf->SetTextColor(0);
        $pdf->SetFont('Helvetica', '', 8);
        foreach ($order->payments as $pmt) {
            $pdf->Cell(50, 5, utf8_decode(strtoupper($pmt->method)), 0, 0, 'L');
            $pdf->Cell(40, 5, number_format($pmt->amount, 0, '.', ' ') . ' FCFA', 0, 1, 'R');
        }
        
        $pdf->Ln(2);

        // Big Summary Box
        $totalGiven = $order->payments->sum('amount_given');
        $totalChange = $order->payments->sum('change_given');
        if ($totalGiven > 0) {
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->Cell(60, 6, utf8_decode(' DONNÉ PAR LE CLIENT'), 'B', 0, 'L');
            $pdf->Cell(35, 6, number_format($totalGiven, 0, '.', ' ') . ' FCFA', 'B', 1, 'R');
            $pdf->Cell(60, 6, ' MONNAIE RENDUE', 'B', 0, 'L');
            $pdf->Cell(35, 6, number_format($totalChange, 0, '.', ' ') . ' FCFA', 'B', 1, 'R');
        }

        // Footer
        $thanksMsg = data_get($restaurant->settings, 'thank_you_message') ?? 'Merci pour votre confiance';
        $pdf->SetY($yStart + 138); // Force footer near the middle/end of receipt block
        $pdf->SetFont('Helvetica', 'I', 7);
        $pdf->SetDrawColor(220);
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Cell(0, 4, utf8_decode($thanksMsg) . ' - Powered by Omega POS', 0, 1, 'C');

    }
    /**
     * Ticket Cuisine/Bar/Pizza en format PDF 58/80mm SANS PRIX
     */
    public function generateKitchenTicketPdf(Order $order, string $destination = 'kitchen'): string
    {
        $order->loadMissing([
            'items.product.category',
            'table',
            'waiter',
        ]);

        $filteredItems = $order->items->whereNotIn('status', ['cancelled']);
        $allGroups = $this->routing->groupByDestination($filteredItems);
        $items = $allGroups[$destination] ?? collect();

        if ($items->isEmpty()) return '';

        $pdf = new Fpdf('P', 'mm', array(80, 100 + ($items->count() * 15)));
        $pdf->SetMargins(5, 5, 5);
        $pdf->AddPage();
        
        // Entête Restaurant
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(0, 5, utf8_decode(strtoupper($order->restaurant->name)), 0, 1, 'C');
        $subtitle = data_get($order->restaurant->settings, 'receipt_subtitle');
        if ($subtitle) {
            $pdf->SetFont('Helvetica', 'BI', 7);
            $pdf->Cell(0, 4, utf8_decode($subtitle), 0, 1, 'C');
        }
        
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell(0, 5, 'IFU : 1001580865', 0, 1, 'C');
        $pdf->Ln(2);

        // Entête Destination
        $pdf->SetFont('Helvetica', 'B', 16);
        $pdf->Cell(0, 8, utf8_decode($this->routing->destinationLabel($destination)), 0, 1, 'C');
        $pdf->SetFont('Helvetica', 'B', 12);
        $pdf->Cell(0, 6, "Ticket #" . $order->order_number, 0, 1, 'C');
        
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->Cell(0, 5, "Modifie le: " . now()->format('d/m/Y H:i'), 0, 1, 'C');
        
        $pdf->Cell(0, 4, '------------------------------------------', 0, 1, 'C');
        
        // Table or Type
        $pdf->SetFont('Helvetica', 'B', 14);
        if ($order->table instanceof \App\Models\Table) {
            $pdf->Cell(0, 8, "TABLE " . $order->table->number, 1, 1, 'C');
        } else {
            $typeLabel = match($order->type) { 'dine_in' => 'Sur place', 'takeaway' => 'A emporter', 'gozem' => 'Livraison', default => ucfirst($order->type) };
            $pdf->Cell(0, 8, strtoupper(utf8_decode($typeLabel)), 1, 1, 'C');
        }
        
        $pdf->Ln(4);
        
        // Infos complémentaires
        if ($order->waiter) {
            $pdf->SetFont('Helvetica', '', 10);
            $pdf->Cell(0, 5, "Serveur: " . utf8_decode($order->waiter->first_name), 0, 1, 'L');
        }
        if ($order->customer_name && $order->type === 'gozem') {
            $pdf->SetFont('Helvetica', '', 10);
            $pdf->Cell(0, 5, "Client: " . utf8_decode($order->customer_name), 0, 1, 'L');
            $pdf->Cell(0, 5, "Tel: " . $order->customer_phone, 0, 1, 'L');
        }
        
        if ($order->notes) {
            $pdf->SetFont('Helvetica', 'B', 10);
            $pdf->MultiCell(0, 6, utf8_decode("Note Cmd: " . $order->notes), 1, 'L');
        }
        
        $pdf->Ln(2);
        $pdf->Cell(0, 4, '------------------------------------------', 0, 1, 'C');
        $pdf->Ln(2);

        // Liste des articles
        $pdf->SetFont('Helvetica', 'B', 14);
        foreach ($items as $item) {
            $x = $pdf->GetX();
            $y = $pdf->GetY();
            
            $pdf->Cell(12, 6, "x" . $item->quantity, 0, 0, 'L');
            
            // On gère un passage à la ligne possible avec MultiCell (donc on sauve le X/Y)
            $pdf->MultiCell(58, 6, utf8_decode(strtoupper($item->product->name)), 0, 'L');
            
            if ($item->notes) {
                // S'il y a une note d'article
                $pdf->SetFont('Helvetica', 'I', 11);
                $pdf->SetX($x + 12);
                $pdf->MultiCell(58, 5, utf8_decode("! " . $item->notes), 0, 'L');
                $pdf->SetFont('Helvetica', 'B', 14);
            }
            $pdf->Ln(3);
        }
        
        $pdf->Cell(0, 4, '------------------------------------------', 0, 1, 'C');
        $pdf->Ln(3);
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(0, 4, "*** FIN DU BON ***", 0, 1, 'C');

        return $pdf->Output('S');
    }

    /**
     * Génère le reçu client en PDF (format ticket thermique 58mm-ish)
     */
    public function generateReceiptPdf(Order $order): string
    {
        $order->loadMissing(['items.product', 'table', 'restaurant', 'payments', 'waiter']);
        $restaurant = $order->restaurant;
        $items      = $order->items->whereNotIn('status', ['cancelled']);

        $pdf = new Fpdf('P', 'mm', array(80, 200 + ($items->count() * 15)));
        $pdf->SetMargins(5, 5, 5);
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 12);
        
        if ($restaurant->logo && file_exists(storage_path('app/public/' . $restaurant->logo))) {
            try {
                $pdf->Image(storage_path('app/public/' . $restaurant->logo), 30, 5, 20);
                $pdf->Ln(22);
            } catch (\Exception $e) {}
        } else {
            $pdf->Cell(0, 10, utf8_decode(strtoupper($restaurant->name)), 0, 1, 'C');
        }

        $subtitle = data_get($restaurant->settings, 'receipt_subtitle');
        if ($subtitle) {
            $pdf->SetFont('Arial', 'B', 10);
            $pdf->Cell(0, 5, utf8_decode(strtoupper($subtitle)), 0, 1, 'C');
        }
        
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(0, 5, $order->created_at->format('d/m/Y H:i'), 0, 1, 'C');

        $pdf->SetFont('Arial', '', 9);
        if ($restaurant->address) $pdf->Cell(0, 5, utf8_decode($restaurant->address), 0, 1, 'C');
        if ($restaurant->phone)   $pdf->Cell(0, 5, 'Te : ' . $restaurant->phone, 0, 1, 'C');
        if ($restaurant->vat_number) $pdf->Cell(0, 5, 'TVA : ' . $restaurant->vat_number, 0, 1, 'C');
        
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(0, 5, 'IFU : 1001580865', 0, 1, 'C');
        $pdf->Cell(0, 5, '------------------------------------------', 0, 1, 'C');
        
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(40, 5, 'TICKET DE CAISSE', 0, 0);
        $pdf->Cell(30, 5, $order->order_number, 0, 1, 'R');
        
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell(40, 4, 'Date', 0, 0);
        $pdf->Cell(30, 4, $order->created_at->format('d/m/Y H:i'), 0, 1, 'R');
        
        if ($order->table) {
            $pdf->Cell(40, 4, 'Table', 0, 0);
            $tableNum = ($order->table instanceof \App\Models\Table) ? $order->table->number : 'N/A';
            $pdf->Cell(30, 4, $tableNum, 0, 1, 'R');
        }
        
        $pdf->Cell(40, 4, 'Type', 0, 0);
        $typeLabel = match($order->type) {
            'dine_in'  => 'SUR PLACE',
            'takeaway' => 'A EMPORTER',
            'gozem'    => 'LIVRAISON (GOZEM)',
            'delivery' => 'LIVRAISON',
            default    => strtoupper($order->type)
        };
        $pdf->Cell(30, 4, utf8_decode($typeLabel), 0, 1, 'R');

        if ($order->waiter) {
            $pdf->Cell(40, 4, 'Serveur', 0, 0);
            $pdf->Cell(30, 4, utf8_decode($order->waiter->first_name . ' ' . $order->waiter->last_name), 0, 1, 'R');
        }

        $pdf->Cell(0, 4, '------------------------------------------', 0, 1, 'C');

        // LIGNES ARTICLES
        // Usable width is 80 - 5 - 5 = 70.
        // Let's divide: Article(35) Qt(10) PU(10) Total(15) = 70
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell(35, 5, 'Article', 0, 0);
        $pdf->Cell(10, 5, 'Qt', 0, 0, 'C');
        $pdf->Cell(10, 5, 'PU', 0, 0, 'R');
        $pdf->Cell(15, 5, 'Total', 0, 1, 'R');
        $pdf->SetFont('Arial', '', 8);

        foreach ($items as $item) {
            $lineTotal = $item->quantity * $item->unit_price;
            $pdf->Cell(35, 4, utf8_decode(substr($item->product->name, 0, 20)), 0, 0);
            $pdf->Cell(10, 4, $item->quantity, 0, 0, 'C');
            $pdf->Cell(10, 4, number_format($item->unit_price, 0, '', ''), 0, 0, 'R');
            $pdf->Cell(15, 4, number_format($lineTotal, 0, '', ''), 0, 1, 'R');

            if ($item->notes) {
                $pdf->SetFont('Arial', 'I', 7);
                $pdf->Cell(5, 3);
                $pdf->Cell(45, 3, 'Note: ' . utf8_decode($item->notes), 0, 1);
                $pdf->SetFont('Arial', '', 8);
            }
        }

        $pdf->Cell(0, 4, '------------------------------------------', 0, 1, 'C');

        // TOTAUX
        $pdf->Cell(45, 4, 'Sous-total', 0, 0);
        $pdf->Cell(25, 4, number_format((float) $order->subtotal, 0, '.', ' '), 0, 1, 'R');
        
        if ($order->discount_amount > 0) {
            $pdf->Cell(45, 4, 'Remise', 0, 0);
            $pdf->Cell(25, 4, '-' . number_format((float) $order->discount_amount, 0, '.', ' '), 0, 1, 'R');
        }

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(45, 6, 'TOTAL TTC', 0, 0);
        $pdf->Cell(25, 6, number_format((float) $order->total, 0, '.', ' '), 0, 1, 'R');
        $pdf->SetFont('Arial', '', 7);
        $pdf->Cell(45, 4, 'Dont TVA (' . ($restaurant->settings['default_vat_rate'] ?? 18) . '%)');
        $pdf->Cell(25, 4, number_format((float) $order->vat_amount, 0, '.', ' '), 0, 1, 'R');
        $pdf->SetFont('Arial', '', 8);

        $pdf->Cell(0, 4, '------------------------------------------', 0, 1, 'C');

        // PAIEMENTS - simplified to summary only

        $totalGiven = $order->payments->sum('amount_given');
        $totalChange = $order->payments->sum('change_given');
        if ($totalGiven > 0) {
            $pdf->Cell(0, 4, '------------------------------------------', 0, 1, 'C');
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->Cell(40, 4, utf8_decode('DONNE PAR CLIENT'), 0, 0);
            $pdf->Cell(30, 4, number_format($totalGiven, 0, '.', ' '), 0, 1, 'R');
            $pdf->Cell(40, 4, utf8_decode('MONNAIE RENDUE'), 0, 0);
            $pdf->Cell(30, 4, number_format($totalChange, 0, '.', ' '), 0, 1, 'R');
            $pdf->SetFont('Arial', '', 8);
        }

        $pdf->Cell(0, 4, '------------------------------------------', 0, 1, 'C');

        if ($order->paid_at) {
            $pdf->SetFont('Arial', 'B', 14);
            $pdf->Cell(0, 8, utf8_decode('*** PAYÉ ***'), 0, 1, 'C');
            $pdf->SetFont('Arial', '', 8);
            $pdf->Cell(0, 4, '------------------------------------------', 0, 1, 'C');
        }

        // PIED DE PAGE
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->MultiCell(0, 4, utf8_decode($restaurant->settings['thank_you_message'] ?? 'Merci pour votre confiance'), 0, 'C');
        
        $pdf->SetFont('Arial', '', 7);
        $pdf->Cell(0, 4, utf8_decode('Généré le ' . now()->format('d/m/Y à H:i')), 0, 1, 'C');
        $pdf->Cell(0, 4, utf8_decode('Certifié par smartflow POS'), 0, 1, 'C');

        // QR Code - Site Web
        try {
            $qrPath = public_path('img/website_qr.png');
            if (file_exists($qrPath)) {
                $pdf->Ln(1);
                $pdf->SetFont('Arial', 'B', 7);
                $pdf->Cell(0, 4, 'Hello, Scan moi', 0, 1, 'C');
                $pdf->Image($qrPath, 28, $pdf->GetY(), 24);
                $pdf->Ln(28);
            }
        } catch (\Exception $e) {}

        return $pdf->Output('S');
    }

    /**
     * Génère la facture A4 PDF pour une Ardoise (Customer Tab)
     */
    public function generateTabInvoicePdf(\App\Models\CustomerTab $tab): string
    {
        $tab->load(['orders.items.product', 'orders.table', 'restaurant', 'creator']);
        $restaurant = $tab->restaurant;
        
        $pdf = new Fpdf('P', 'mm', 'A4');
        $pdf->AddPage();
        
        // Logo si existe
        if ($restaurant->logo && file_exists(storage_path('app/public/' . $restaurant->logo))) {
            try {
                $pdf->Image(storage_path('app/public/' . $restaurant->logo), 10, 10, 30);
                $pdf->Ln(25);
            } catch (\Exception $e) {}
        }

        // Header
        $pdf->SetFont('Arial', 'B', 18);
        $pdf->Cell(120, 10, utf8_decode(strtoupper($restaurant->name)), 0, 0);
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 10, utf8_decode('FACTURE ARDOISE'), 0, 1, 'R');

        $subtitle = data_get($restaurant->settings, 'receipt_subtitle');
        if ($subtitle) {
            $pdf->SetFont('Arial', 'B', 10);
            $pdf->SetTextColor(80, 80, 80);
            $pdf->Cell(120, 6, utf8_decode(strtoupper($subtitle)), 0, 1);
        }
        $pdf->SetTextColor(0);
        
        $pdf->SetFont('Arial', '', 9);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell(120, 5, utf8_decode($restaurant->address ?? 'ADRESSE NON DEFINIE'), 0, 0);
        $pdf->Cell(0, 5, 'Date: ' . now()->format('d/m/Y'), 0, 1, 'R');
        
        $pdf->Cell(120, 5, utf8_decode('Tél: ' . $restaurant->phone), 0, 0);
        $pdf->Cell(0, 5, utf8_decode('Réf: ARD-' . $tab->id), 0, 1, 'R');
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(0, 5, 'IFU : 1001580865', 0, 1, 'R');
        
        $pdf->Ln(5);
        
        // Bloc Client
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetTextColor(0);
        $pdf->Cell(0, 8, utf8_decode('INFORMATIONS CLIENT'), 'B', 1, 'L');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(95, 7, utf8_decode('Nom: ' . $tab->full_name), 0, 0);
        $pdf->Cell(0, 7, utf8_decode('Téléphone: ' . $tab->phone), 0, 1);
        $pdf->Cell(0, 7, utf8_decode('Ouverte le: ' . $tab->opened_at->format('d/m/Y à H:i')), 0, 1);
        
        $pdf->Ln(5);
        
        // Tableau des articles
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetTextColor(0);
        
        $pdf->Cell(10, 10, '#', 'B', 0, 'C');
        $pdf->Cell(85, 10, utf8_decode('Désignation'), 'B', 0, 'L');
        $pdf->Cell(15, 10, utf8_decode('Qté'), 'B', 0, 'C');
        $pdf->Cell(35, 10, 'P.U. (FCFA)', 'B', 0, 'R');
        $pdf->Cell(35, 10, 'Total (FCFA)', 'B', 1, 'R');
        
        $pdf->SetFont('Arial', '', 9);
        $pdf->SetTextColor(0);
        
        $i = 1;
        $totalVat = 0;
        foreach ($tab->orders as $order) {
            $totalVat += (float) $order->vat_amount;
            foreach ($order->items->whereNotIn('status', ['cancelled']) as $item) {
                $pdf->Cell(10, 8, $i, 'B', 0, 'C');
                $pdf->Cell(85, 8, utf8_decode($item->product->name), 'B', 0, 'L');
                $pdf->Cell(15, 8, $item->quantity, 'B', 0, 'C');
                $pdf->Cell(35, 8, number_format($item->unit_price, 0, '.', ' '), 'B', 0, 'R');
                $pdf->Cell(35, 8, number_format($item->quantity * $item->unit_price, 0, '.', ' '), 'B', 1, 'R');
                $i++;
                
                if ($pdf->GetY() > 270) {
                    $pdf->AddPage();
                    $pdf->SetFont('Arial', 'B', 10);
                    $pdf->Cell(10, 10, '#', 'B', 0, 'C');
                    $pdf->Cell(85, 10, 'Suite...', 'B', 0, 'L');
                    $pdf->Ln();
                    $pdf->SetFont('Arial', '', 9);
                }
            }
        }
        
        // Assurer qu'il y a assez d'espace pour les totaux et le footer
        if ($pdf->GetY() > 210) {
            $pdf->AddPage();
        }

        $pdf->Ln(5);
        
        // Totaux
        $pdf->SetX(120);
        $pdf->SetFont('Arial', 'B', 10);
        $total = $tab->total_amount;
        $paid = $tab->paid_amount;
        $remaining = $tab->remainingAmount();
        $vatRate = $restaurant->settings['default_vat_rate'] ?? 18;
        $pdf->SetX(120);
        $pdf->SetTextColor(0);
        $pdf->Cell(45, 10, 'TOTAL TTC', 'T,B', 0, 'L');
        $pdf->Cell(35, 10, number_format((float) $total, 0, '.', ' ') . ' FCFA', 'T,B', 1, 'R');
        $pdf->SetFont('Arial', '', 9);
        $pdf->SetX(120);
        $pdf->Cell(45, 8, utf8_decode('Dont TVA (' . $vatRate . '%)'), 0, 0);
        $pdf->Cell(35, 8, number_format((float) $totalVat, 0, '.', ' '), 0, 1, 'R');
        
        $pdf->SetX(120);
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetTextColor(255, 0, 0);
        $pdf->Cell(45, 10, 'RESTE A PAYER', 0, 0);
        $pdf->Cell(35, 10, number_format($remaining, 0, '.', ' ') . ' FCFA', 0, 1, 'R');
        
        $pdf->Ln(5);
        $pdf->SetTextColor(0);
        
        // Bloc Historique Paiements
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(0, 8, utf8_decode('HISTORIQUE DES VERSEMENTS'), 'B', 1, 'L');
        $pdf->SetFont('Arial', '', 9);
        
        $tab->load('orders.payments');
        $allPayments = $tab->orders->flatMap->payments->sortByDesc('created_at');
        
        if ($allPayments->isEmpty()) {
            $pdf->Cell(0, 7, utf8_decode('Aucun versement enregistré.'), 0, 1, 'C');
        } else {
            foreach ($allPayments as $p) {
                $dateP = $p->created_at->format('d/m/Y H:i');
                $methodLabel = match($p->method) { 
                    'cash' => 'ESPECES', 
                    'momo' => 'MOBILE MONEY',
                    'orange_money' => 'ORANGE MONEY',
                    'wave' => 'WAVE',
                    default => strtoupper($p->method) 
                };
                $pdf->Cell(60, 6, $dateP . ' - ' . $methodLabel, 0, 0);
                $pdf->Cell(0, 6, number_format($p->amount, 0, '.', ' ') . ' FCFA', 0, 1, 'R');
            }
        }
        
        // Footer aligné dynamiquement après les totaux
        $pdf->Ln(10);
        $pdf->SetDrawColor(200, 200, 200);
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Ln(3);
        $pdf->SetFont('Arial', 'I', 8);
        $pdf->SetTextColor(150);
        $pdf->Cell(0, 5, utf8_decode('Certifié par Omega POS — Document original'), 0, 1, 'C');
        $pdf->Cell(0, 5, utf8_decode('Merci pour votre confiance chez ' . $restaurant->name), 0, 1, 'C');
        
        return $pdf->Output('S');
    }


}
