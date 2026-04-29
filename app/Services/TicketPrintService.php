<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemNote;
use App\Models\Restaurant;
use App\Models\Table;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;

class TicketPrintService
{
    public function __construct(protected OrderRoutingService $routing) {}

    /**
     * Construit les données formatées pour un reçu (Noir & Blanc)
     */
    public function buildReceiptData(Order $order, $restaurant, array $config): array
    {
        $currencySymbol = $config['currency_symbol'] ?? 'FCFA';
        $currencyPosition = $config['currency_position'] ?? 'after';
        $formatAmount = fn($amount) => $currencyPosition === 'before'
            ? "{$currencySymbol} " . number_format($amount, 0, '.', ' ')
            : number_format($amount, 0, '.', ' ') . " {$currencySymbol}";

        $lines = $order->items->map(function ($item) use ($formatAmount) {
            $modifiers = $item->modifiers->map(fn($m) => [
                'name' => $m->modifier->name, 
                'extra_price' => $m->extra_price, 
                'extra_fmt' => $m->extra_price > 0 ? '+' . number_format($m->extra_price, 0) : ''
            ])->toArray();
            $lineTotal = ($item->unit_price * $item->quantity) + collect($modifiers)->sum('extra_price') * $item->quantity;
            return [
                'name' => $item->product->name, 
                'quantity' => $item->quantity, 
                'unit_price' => $item->unit_price, 
                'unit_fmt' => $formatAmount($item->unit_price), 
                'total' => $lineTotal, 
                'total_fmt' => $formatAmount($lineTotal), 
                'notes' => $item->notes, 
                'modifiers' => $modifiers
            ];
        })->toArray();

        $paymentLines = $order->payments->map(fn($p) => [
            'method' => $this->methodLabel($p->method), 
            'amount' => $p->amount, 
            'amount_fmt' => $formatAmount($p->amount), 
            'reference' => $p->reference, 
            'amount_given' => $p->amount_given, 
            'change_given' => $p->change_given, 
            'change_fmt' => $p->change_given ? $formatAmount($p->change_given) : null
        ])->toArray();

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
            'order' => [
                'id' => $order->id, 
                'number' => $order->order_number, 
                'date' => $order->created_at->format('d/m/Y'), 
                'time' => $order->created_at->format('H:i'), 
                'paid_at' => $order->paid_at?->format('d/m/Y H:i'), 
                'table_number' => $order->table?->number, 
                'covers' => $order->covers, 
                'type' => $order->type, 
                'type_label' => $this->typeLabel($order->type), 
                'waiter' => $order->waiter?->full_name, 
                'cashier' => $order->cashier?->full_name, 
                'notes' => $order->notes
            ],
            'lines' => $lines,
            'totals' => [
                'subtotal' => $order->subtotal, 
                'subtotal_fmt' => $formatAmount($order->subtotal), 
                'discount' => $order->discount_amount, 
                'discount_fmt' => $order->discount_amount > 0 ? '-' . $formatAmount($order->discount_amount) : null, 
                'discount_reason' => $order->discount_reason, 
                'vat_rate' => $config['default_vat_rate'] ?? 18, 
                'vat_amount' => $order->vat_amount, 
                'vat_fmt' => $formatAmount($order->vat_amount), 
                'total' => $order->total, 
                'total_fmt' => $formatAmount($order->total), 
                'amount_paid' => $order->amountPaid(), 
                'amount_paid_fmt' => $formatAmount($order->amountPaid()), 
                'change' => max(0, $order->amountPaid() - $order->total), 
                'change_fmt' => $formatAmount(max(0, $order->amountPaid() - $order->total))
            ],
            'payments' => $paymentLines,
            'footer' => [
                'message' => $config['receipt_footer'] ?? 'Merci de votre visite !', 
                'website' => $config['receipt_website'] ?? null, 
                'show_logo' => $config['receipt_logo'] ?? true, 
                'width' => $config['receipt_width'] ?? '80mm'
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Ticket de cuisine filtré par destination - NOIR & BLANC
     */
    public function kitchenTicketHtml(Order $order, string $destination = 'kitchen', ?array $itemIds = null): string
    {
        $order->loadMissing(['items.product.category', 'table', 'waiter']);
        $filteredItems = $order->items->whereNotIn('status', ['cancelled']);
        if ($itemIds) { $filteredItems = $filteredItems->whereIn('id', $itemIds); }

        $allGroups = $this->routing->groupByDestination($filteredItems);
        $items = $allGroups[$destination] ?? collect();
        if ($items->isEmpty()) return '';

        $restaurant = $order->restaurant;
        $tableLabel = $order->table ? "T" . $order->table->number : strtoupper($order->type);
        $date = now()->format('d/m H:i');

        return "
        <div style='font-family: monospace; width: 100%; font-size: 13px; color: #000; line-height: 1.25;'>
            <div style='text-align: center; font-weight: bold; border-bottom: 1px solid #000; padding-bottom: 2px; margin-bottom: 2px;'>
                " . strtoupper($restaurant->name) . " - " . strtoupper($this->routing->destinationLabel($destination)) . "
            </div>
            <div style='text-align: center; font-size: 11px;'>#{$order->order_number} | {$date}</div>

            <div style='border: 2px solid #000; text-align: center; font-size: 24px; font-weight: bold; margin: 3px 0;'>
                {$tableLabel}
            </div>

            <table style='width: 100%; border-collapse: collapse;'>
                " . $items->map(fn($item) => "
                <tr style='border-bottom: 1px dashed #000;'>
                    <td style='vertical-align: top; padding: 4px 0; font-size: 18px; font-weight: bold;'>x{$item->quantity}</td>
                    <td style='padding: 4px 0; font-size: 16px; font-weight: bold;'>
                        " . strtoupper($item->product->name) . "
                        " . ($item->notes ? "<div style='font-size: 12px; font-weight: bold; font-style: italic;'>! {$item->notes}</div>" : "") . "
                    </td>
                </tr>")->implode('') . "
            </table>
            <div style='margin-top: 5px; text-align: center; font-size: 10px; font-weight: bold;'>*** FIN ***</div>
        </div>";
    }

    /**
     * Reçu de dépense - NOIR & BLANC
     */
    public function expenseReceiptHtml($expense): string
    {
        $restaurant = $expense->restaurant;
        $date = $expense->created_at->format('d/m/y H:i');
        $id = str_pad($expense->id, 4, '0', STR_PAD_LEFT);

        return "
        <div style='font-family: monospace; width: 100%; font-size: 12px; color: #000; line-height: 1.25;'>
            <div style='text-align: center; font-weight: bold; border-bottom: 1px dashed #000;'>" . strtoupper($restaurant->name) . "</div>
            <div style='text-align: center; font-weight: bold; margin: 4px 0;'>RECUS DEPENSE #{$id}</div>
            
            <div style='font-size: 11px; margin-bottom: 5px;'>
                <b>DATE:</b> {$date}<br>
                <b>AGENT:</b> " . strtoupper($expense->user->first_name) . "
            </div>

            <div style='margin-bottom: 5px;'>
                <b>MOTIF:</b> " . strtoupper($expense->description) . "<br>
                <b>POUR:</b> " . strtoupper($expense->beneficiary ?: 'N/A') . "
            </div>

            <div style='text-align: center; border: 2px solid #000; padding: 5px; font-size: 18px; font-weight: bold;'>
                " . number_format($expense->amount, 0, ',', ' ') . " F
            </div>

            <div style='margin-top: 20px; border-top: 1px solid #000; padding-top: 3px; text-align: center; font-size: 10px; font-weight: bold;'>
                SIGNATURE BENEFICIAIRE<br><br><br>
                ...........................
            </div>
        </div>";
    }

    /**
     * Rapport de clôture - NOIR & BLANC
     */
    public function sessionReportHtml($session): string
    {
        $restaurant = $session->restaurant;
        $open = $session->opened_at->format('d/m H:i');
        $close = $session->closed_at ? $session->closed_at->format('d/m H:i') : 'ACTIF';

        $html = "
        <div style='font-family: monospace; width: 100%; font-size: 11px; color: #000; line-height: 1.25;'>
            <div style='text-align: center; font-weight: bold; font-size: 13px;'>" . strtoupper($restaurant->name) . "</div>
            <div style='text-align: center; border-bottom: 1px solid #000; padding-bottom: 2px; margin-bottom: 4px; font-weight: bold;'>RAPPORT CLOTURE #{$session->id}</div>
            
            <div style='margin-bottom: 5px;'>
                <b>CAISSIER:</b> " . strtoupper($session->user->first_name) . "<br>
                <b>PERIODE:</b> {$open} au {$close}
            </div>

            <div style='border-bottom: 1px solid #000; font-weight: bold; margin-bottom: 2px;'>FLUX CASH</div>
            <table style='width: 100%; border-collapse: collapse;'>
                <tr><td>OUVERTURE:</td><td style='text-align: right;'>" . number_format($session->opening_amount, 0, ',', ' ') . "</td></tr>
                <tr><td>VENTES (+):</td><td style='text-align: right;'>" . number_format($session->cash_total, 0, ',', ' ') . "</td></tr>
                <tr><td>DEPENSES (-):</td><td style='text-align: right;'>" . number_format($session->total_expenses, 0, ',', ' ') . "</td></tr>
                <tr style='font-weight: bold; border-top: 1px solid #000;'><td>THEORIQUE:</td><td style='text-align: right;'>" . number_format($session->expected_amount, 0, ',', ' ') . "</td></tr>
            </table>

            <div style='border-bottom: 1px solid #000; font-weight: bold; margin-top: 8px; margin-bottom: 2px;'>REEL</div>
            <table style='width: 100%;'>
                <tr><td>BANQUE:</td><td style='text-align: right;'>" . number_format($session->amount_to_bank, 0, ',', ' ') . "</td></tr>
                <tr><td>CAISSE:</td><td style='text-align: right;'>" . number_format($session->remaining_amount, 0, ',', ' ') . "</td></tr>
                <tr style='font-weight: bold; font-size: 13px; border-top: 1px solid #000;'>
                    <td>ECART:</td>
                    <td style='text-align: right;'>" . ($session->difference > 0 ? '+' : '') . number_format($session->difference, 0, ',', ' ') . "</td>
                </tr>
            </table>

            <div style='border-bottom: 1px solid #000; font-weight: bold; margin-top: 8px; margin-bottom: 2px;'>AUTRES MODES</div>
            <table style='width: 100%;'>";
        
        foreach (['card' => 'CARTE', 'wave' => 'WAVE', 'orange_money' => 'ORANGE', 'momo' => 'MOMO'] as $key => $label) {
            $val = $session->{$key . '_total'} ?? 0;
            if ($val > 0) {
                $html .= "<tr><td>{$label}:</td><td style='text-align: right;'>" . number_format($val, 0, ',', ' ') . "</td></tr>";
            }
        }
        
        $html .= "</table>
            <div style='margin-top: 15px; border-top: 1px dashed #000; text-align: center; font-weight: bold;'>VISA RESPONSABLE</div>
        </div>";

        return $html;
    }

    /**
     * Commande de gâteau - NOIR & BLANC
     */
    public function cakeOrderHtml($cakeOrder): string
    {
        $restaurant = $cakeOrder->restaurant;
        $deliveryDate = \Carbon\Carbon::parse($cakeOrder->delivery_date)->format('d/m/y');
        $deliveryTime = $cakeOrder->delivery_time ? " " . substr($cakeOrder->delivery_time, 0, 5) : '';

        $html = "
        <div style='font-family: monospace; width: 100%; font-size: 12px; color: #000; line-height: 1.25;'>
            <div style='text-align: center; font-weight: bold; border-bottom: 1px solid #000;'>COMMANDE GATEAU #{$cakeOrder->order_number}</div>
            
            <div style='margin: 5px 0;'>
                <b>CLIENT:</b> " . strtoupper($cakeOrder->customer_name) . "<br>
                <b>TEL:</b> {$cakeOrder->customer_phone}<br>
                <b>LIVRER LE:</b> <span style='border: 1px solid #000; padding: 0 3px; font-weight: bold;'>{$deliveryDate}{$deliveryTime}</span>
            </div>

            <div style='border-bottom: 1px solid #000; font-weight: bold; margin-bottom: 2px;'>DETAILS</div>";

        foreach ($cakeOrder->items as $item) {
            $html .= "
                <div style='display:flex; justify-content:space-between; font-weight: bold;'>
                    <span>x{$item['qty']} " . strtoupper($item['name']) . "</span>
                    <span>" . number_format($item['qty'] * $item['unit_price'], 0, ',', ' ') . "</span>
                </div>";
            if (!empty($item['notes'])) {
                $html .= "<div style='font-size:11px; font-style:italic; font-weight: bold;'>- " . $item['notes'] . "</div>";
            }
        }

        $html .= "
            <div style='border-top: 1px dashed #000; margin-top: 5px; padding-top: 3px;'>
                <div style='display:flex; justify-content:space-between;'><span>TOTAL:</span><b>" . number_format($cakeOrder->total, 0, ',', ' ') . "</b></div>
                <div style='display:flex; justify-content:space-between;'><span>AVANCE:</span><b>" . number_format($cakeOrder->advance_paid, 0, ',', ' ') . "</b></div>
                <div style='display:flex; justify-content:space-between; font-size:14px; margin-top:3px; border: 1px solid #000; padding: 2px; font-weight: bold;'>
                    <span>RESTE:</span>
                    <b>" . number_format($cakeOrder->remaining_amount, 0, ',', ' ') . " F</b>
                </div>
            </div>
        </div>";

        return $html;
    }

    /**
     * Facture A4 sécurisée (Correction erreur de chargement)
     */
    public function invoiceA4Html(Order $order): string
    {
        $restaurant = $order->restaurant;
        $customer = $order->customer_name ?: 'Client de Passage';
        $date = $order->created_at->format('d/m/Y');
        
        $html = "
        <html><head><style>body { font-family: Arial, sans-serif; color: #000; margin: 0; padding: 20px; }</style></head><body>
        <div style='padding: 20px; border: 1px solid #000;'>
            <table style='width: 100%; border-bottom: 2px solid #000; padding-bottom: 10px;'>
                <tr>
                    <td>
                        <h1 style='margin: 0; font-size: 24px;'>" . strtoupper($restaurant->name) . "</h1>
                        <p style='margin: 2px 0;'>{$restaurant->address}</p>
                        <p style='margin: 2px 0;'>Tél: {$restaurant->phone}</p>
                        <p style='margin: 2px 0;'>IFU: 1001580865</p>
                    </td>
                    <td style='text-align: right; vertical-align: top;'>
                        <h2 style='margin: 0;'>FACTURE</h2>
                        <p style='margin: 2px 0;'>N°: {$order->order_number}</p>
                        <p style='margin: 2px 0;'>Date: {$date}</p>
                    </td>
                </tr>
            </table>

            <div style='margin: 20px 0;'>
                <strong>DOIT À:</strong><br>
                " . strtoupper($customer) . "<br>
                " . ($order->customer_phone ?: '') . "
            </div>

            <table style='width: 100%; border-collapse: collapse; margin-top: 15px;'>
                <thead>
                    <tr style='background: #000; color: #fff;'>
                        <th style='padding: 8px; text-align: left; border: 1px solid #000;'>DESIGNATION</th>
                        <th style='padding: 8px; text-align: center; border: 1px solid #000;'>QTE</th>
                        <th style='padding: 8px; text-align: right; border: 1px solid #000;'>P.U</th>
                        <th style='padding: 8px; text-align: right; border: 1px solid #000;'>TOTAL</th>
                    </tr>
                </thead>
                <tbody>";

        foreach ($order->items as $item) {
            if ($item->status === 'cancelled') continue;
            $html .= "
                <tr>
                    <td style='padding: 8px; border: 1px solid #000;'>" . strtoupper($item->product->name) . "</td>
                    <td style='padding: 8px; text-align: center; border: 1px solid #000;'>{$item->quantity}</td>
                    <td style='padding: 8px; text-align: right; border: 1px solid #000;'>" . number_format($item->unit_price, 0, ',', ' ') . "</td>
                    <td style='padding: 8px; text-align: right; border: 1px solid #000;'>" . number_format($item->unit_price * $item->quantity, 0, ',', ' ') . "</td>
                </tr>";
        }

        $html .= "
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan='3' style='padding: 8px; text-align: right; border: 1px solid #000;'><strong>TOTAL HT</strong></td>
                        <td style='padding: 8px; text-align: right; border: 1px solid #000;'>" . number_format($order->subtotal, 0, ',', ' ') . "</td>
                    </tr>
                    <tr>
                        <td colspan='3' style='padding: 8px; text-align: right; border: 1px solid #000;'><strong>TVA (18%)</strong></td>
                        <td style='padding: 8px; text-align: right; border: 1px solid #000;'>" . number_format($order->vat_amount, 0, ',', ' ') . "</td>
                    </tr>
                    <tr style='background: #eee;'>
                        <td colspan='3' style='padding: 8px; text-align: right; border: 1px solid #000;'><strong>TOTAL TTC</strong></td>
                        <td style='padding: 8px; text-align: right; border: 1px solid #000;'><strong>" . number_format($order->total, 0, ',', ' ') . " FCFA</strong></td>
                    </tr>
                </tfoot>
            </table>

            <div style='margin-top: 40px; text-align: right;'>
                <p>La Direction</p>
                <br><br>
                <p>_________________________</p>
            </div>
        </div></body></html>";

        return $html;
    }

    public function generateInvoiceA4Pdf(Order $order): string
    {
        $html = $this->invoiceA4Html($order);
        return Pdf::loadHTML($html)->setPaper('a4')->output();
    }

    public function generateReceiptPdf(Order $order): string
    {
        $restaurant = $order->restaurant;
        $config = $restaurant->settings ?? [];
        $receipt = $this->buildReceiptData($order, $restaurant, $config);
        
        $html = view('receipts.ticket', compact('receipt', 'restaurant', 'config'))->render();
        return Pdf::loadHTML($html)->setPaper([0, 0, 226.77, 800])->output();
    }

    public function generateBulkInvoiceA4Pdf($orders): string
    {
        $html = "<html><body>";
        foreach ($orders as $order) {
            $html .= "<div style='page-break-after: always;'>" . $this->invoiceA4Html($order) . "</div>";
        }
        $html .= "</body></html>";
        return Pdf::loadHTML($html)->setPaper('a4')->output();
    }

    public function generateKitchenTicketPdf(Order $order, string $destination = 'kitchen', ?array $itemIds = null): string
    {
        $html = "<html><body>" . $this->kitchenTicketHtml($order, $destination, $itemIds) . "</body></html>";
        return Pdf::loadHTML($html)->setPaper([0, 0, 226.77, 600])->output();
    }

    private function methodLabel(string $method): string { return match($method) { 'cash' => 'Espèces', 'card' => 'Carte', 'wave' => 'Wave', 'orange_money' => 'Orange', 'momo' => 'Momo', default => 'Autre' }; }
    private function typeLabel(string $type): string { return match($type) { 'dine_in' => 'Sur place', 'takeaway' => 'À emporter', 'delivery' => 'Livraison', default => $type }; }
}
