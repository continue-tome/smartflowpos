<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemNote;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\CakeOrder;
use App\Models\Expense;
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
                'logo_raw' => $restaurant->logo,
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

        $allGroups = $this->routing->groupByDestination(collect($filteredItems));
        $items = $allGroups[$destination] ?? collect();
        if ($items->isEmpty()) return '';

        $restaurant = $order->restaurant;
        $tableLabel = ($order->table instanceof \App\Models\Table) ? "T" . $order->table->number : strtoupper((string)$order->type);
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
        <div style='font-family: monospace; width: 100%; font-size: 11px; color: #000; line-height: 1.1;'>
            <div style='text-align: center; font-weight: bold; border-bottom: 1px dashed #000; padding-bottom: 2px;'>" . strtoupper($restaurant->name) . "</div>
            <div style='text-align: center; font-weight: bold; margin: 3px 0;'>DEPENSE #{$id}</div>
            
            <div style='font-size: 10px; margin-bottom: 3px;'>
                <b>DATE:</b> {$date} | <b>AGENT:</b> " . strtoupper($expense->user->first_name) . "
            </div>

            <div style='margin-bottom: 3px;'>
                <b>MOTIF:</b> " . strtoupper($expense->description) . "<br>
                <b>POUR:</b> " . strtoupper($expense->beneficiary ?: 'N/A') . "
            </div>

            <div style='text-align: center; border: 1px solid #000; padding: 3px; font-size: 16px; font-weight: bold;'>
                " . number_format($expense->amount, 0, ',', ' ') . " F
            </div>

            <div style='margin-top: 10px; border-top: 1px dashed #000; padding-top: 2px; text-align: center; font-size: 9px; font-weight: bold;'>
                SIGNATURE BENEFICIAIRE<br><br>
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
        <div style='font-family: monospace; width: 100%; font-size: 10px; color: #000; line-height: 1.1;'>
            <div style='text-align: center; font-weight: bold; font-size: 12px;'>" . strtoupper($restaurant->name) . "</div>
            <div style='text-align: center; border-bottom: 1px solid #000; padding-bottom: 1px; margin-bottom: 3px; font-weight: bold;'>CLOTURE #{$session->id}</div>
            
            <div style='margin-bottom: 3px;'>
                <b>AGENT:</b> " . strtoupper($session->user->first_name) . "<br>
                <b>PERIODE:</b> {$open} au {$close}
            </div>

            <div style='border-bottom: 1px solid #000; font-weight: bold; margin-bottom: 1px;'>FLUX CASH</div>
            <table style='width: 100%; border-collapse: collapse;'>
                <tr><td>OUVERTURE:</td><td style='text-align: right;'>" . number_format($session->opening_amount, 0, ',', ' ') . "</td></tr>
                <tr><td>VENTES (+):</td><td style='text-align: right;'>" . number_format($session->cash_total, 0, ',', ' ') . "</td></tr>
                <tr><td>DEPENSES (-):</td><td style='text-align: right;'>" . number_format($session->total_expenses, 0, ',', ' ') . "</td></tr>
                <tr style='font-weight: bold; border-top: 1px solid #000;'><td>THEORIQUE:</td><td style='text-align: right;'>" . number_format($session->expected_amount, 0, ',', ' ') . "</td></tr>
            </table>

            <div style='border-bottom: 1px solid #000; font-weight: bold; margin-top: 4px; margin-bottom: 1px;'>REEL</div>
            <table style='width: 100%;'>
                <tr><td>BANQUE:</td><td style='text-align: right;'>" . number_format($session->amount_to_bank, 0, ',', ' ') . "</td></tr>
                <tr><td>CAISSE:</td><td style='text-align: right;'>" . number_format($session->remaining_amount, 0, ',', ' ') . "</td></tr>
                <tr style='font-weight: bold; font-size: 11px; border-top: 1px solid #000;'>
                    <td>ECART:</td>
                    <td style='text-align: right;'>" . ($session->difference > 0 ? '+' : '') . number_format($session->difference, 0, ',', ' ') . "</td>
                </tr>
            </table>

            <div style='border-bottom: 1px solid #000; font-weight: bold; margin-top: 4px; margin-bottom: 1px;'>AUTRES MODES</div>
            <table style='width: 100%; border-collapse: collapse;'>";
        
        foreach (['card' => 'CARTE', 'wave' => 'WAVE', 'orange_money' => 'ORANGE', 'momo' => 'MOMO'] as $key => $label) {
            $val = $session->{$key . '_total'} ?? 0;
            if ($val > 0) {
                $html .= "<tr><td>{$label}:</td><td style='text-align: right;'>" . number_format($val, 0, ',', ' ') . "</td></tr>";
            }
        }
        
        $qrPath = public_path('img/website_qr.png');
        $qrBase64 = null;
        if (file_exists($qrPath)) {
            $qrData = base64_encode(file_get_contents($qrPath));
            $qrBase64 = 'data:image/png;base64,' . $qrData;
        }

        $html .= "</table>";
        
        if ($qrBase64) {
            $html .= "<div style='text-align: center; margin-top: 5px;'>
                <img src='{$qrBase64}' style='width: 40px; height: 40px; filter: grayscale(100%); display: inline-block;'>
            </div>";
        }

        $html .= "<div style='margin-top: 5px; border-top: 1px dashed #000; text-align: center; font-weight: bold; font-size: 8px;'>
                VISA RESPONSABLE • " . now()->format('d/m/y H:i') . "
            </div>
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

        // QR Code Base64
        $qrPath = public_path('img/website_qr.png');
        $qrBase64 = null;
        if (file_exists($qrPath)) {
            $qrData = base64_encode(file_get_contents($qrPath));
            $qrBase64 = 'data:image/png;base64,' . $qrData;
        }

        $html = "
        <div style='font-family: monospace; width: 100%; font-size: 10px; color: #000; line-height: 1.1; background: #fff;'>
            <div style='text-align: center;'>
                <div style='font-size: 13px; font-weight: bold;'>" . strtoupper($restaurant->name) . "</div>
                <div style='font-weight: bold; border-top: 1px dashed #000; border-bottom: 1px dashed #000; margin: 3px 0; padding: 2px 0;'>
                    GATEAU #{$cakeOrder->order_number}
                </div>
            </div>
            
            <div style='margin: 4px 0; font-weight: bold;'>
                CLIENT: " . strtoupper($cakeOrder->customer_name) . "<br>
                TEL: {$cakeOrder->customer_phone} | LE: {$deliveryDate}{$deliveryTime}
            </div>

            <div style='border-bottom: 1px solid #000; font-weight: bold; margin-bottom: 3px;'>ARTICLES</div>";

        foreach ($cakeOrder->items as $item) {
            $html .= "
                <div style='font-weight: bold; margin-bottom: 2px;'>
                    <div style='display:flex; justify-content:space-between;'>
                        <span>x{$item['qty']} " . strtoupper($item['name']) . "</span>
                        <span>" . number_format($item['qty'] * $item['unit_price'], 0, ',', ' ') . "</span>
                    </div>";
            if (!empty($item['notes'])) {
                $html .= "<div style='font-size:10px; font-style:italic; padding-left: 10px;'>- " . $item['notes'] . "</div>";
            }
            $html .= "</div>";
        }

        $html .= "
            <div style='border-top: 1px dashed #000; margin-top: 5px; padding-top: 5px;'>
                <table style='width: 100%; font-weight: bold;'>
                    <tr><td>TOTAL:</td><td style='text-align: right;'>" . number_format($cakeOrder->total, 0, ',', ' ') . "</td></tr>
                    <tr><td>AVANCE:</td><td style='text-align: right;'>" . number_format($cakeOrder->advance_paid, 0, ',', ' ') . "</td></tr>
                    <tr style='font-size: 13px;'>
                        <td style='border: 1px solid #000; padding: 2px;'>RESTE:</td>
                        <td style='text-align: right; border: 1px solid #000; padding: 2px;'>" . number_format($cakeOrder->remaining_amount, 0, ',', ' ') . " FCFA</td>
                    </tr>
                </table>
            </div>

            <div style='text-align: center; margin-top: 15px;'>
                <div style='font-weight: bold; margin-bottom: 5px;'>Merci de votre confiance !</div>";
        
        if ($qrBase64) {
            $html .= "<div style='text-align: center; margin-top: 8px;'><img src='{$qrBase64}' style='width: 60px; height: 60px; display: inline-block; filter: grayscale(100%);'></div>";
        }

        $html .= "
                <div style='font-size: 9px; margin-top: 5px; font-weight: bold;'>" . now()->format('d/m/Y H:i') . "</div>
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
        <div style='padding: 10px; border: 1px solid #000; font-family: Arial, sans-serif; background: #fff;'>
            <table style='width: 100%; border-bottom: 2px solid #000; padding-bottom: 5px;'>
                <tr>
                    <td>
                        <h1 style='margin: 0; font-size: 20px;'>" . strtoupper($restaurant->name) . "</h1>
                        <p style='margin: 2px 0; font-size: 11px;'>{$restaurant->address}</p>
                        <p style='margin: 2px 0; font-size: 11px;'>Tél: {$restaurant->phone}</p>
                        <p style='margin: 2px 0; font-size: 11px;'>IFU: 1001580865</p>
                    </td>
                    <td style='text-align: right; vertical-align: top;'>
                        <h2 style='margin: 0; font-size: 18px;'>FACTURE</h2>
                        <p style='margin: 2px 0; font-size: 11px;'>N°: {$order->order_number}</p>
                        <p style='margin: 2px 0; font-size: 11px;'>Date: {$date}</p>
                    </td>
                </tr>
            </table>

            <div style='margin: 8px 0; font-size: 11px;'>
                <strong>DOIT À:</strong><br>
                " . strtoupper($customer) . " " . ($order->customer_phone ?: '') . "
            </div>

            <table style='width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 11px;'>
                <thead>
                    <tr style='border-top: 2px solid #000; border-bottom: 2px solid #000;'>
                        <th style='padding: 5px; text-align: left; border: 1px solid #000;'>DESIGNATION</th>
                        <th style='padding: 5px; text-align: center; border: 1px solid #000;'>QTE</th>
                        <th style='padding: 5px; text-align: right; border: 1px solid #000;'>P.U</th>
                        <th style='padding: 5px; text-align: right; border: 1px solid #000;'>TOTAL</th>
                    </tr>
                </thead>
                <tbody>";

        foreach ($order->items as $item) {
            if ($item->status === 'cancelled') continue;
            $html .= "
                <tr>
                    <td style='padding: 5px; border: 1px solid #000;'>" . strtoupper($item->product->name) . "</td>
                    <td style='padding: 5px; text-align: center; border: 1px solid #000;'>{$item->quantity}</td>
                    <td style='padding: 5px; text-align: right; border: 1px solid #000;'>" . number_format($item->unit_price, 0, ',', ' ') . "</td>
                    <td style='padding: 5px; text-align: right; border: 1px solid #000;'>" . number_format($item->unit_price * $item->quantity, 0, ',', ' ') . "</td>
                </tr>";
        }

        $html .= "
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan='3' style='padding: 5px; text-align: right; border: 1px solid #000;'><strong>TOTAL HT</strong></td>
                        <td style='padding: 5px; text-align: right; border: 1px solid #000;'>" . number_format((float)($order->subtotal ?? 0), 0, ',', ' ') . "</td>
                    </tr>
                    <tr>
                        <td colspan='3' style='padding: 5px; text-align: right; border: 1px solid #000;'><strong>TVA (18%)</strong></td>
                        <td style='padding: 5px; text-align: right; border: 1px solid #000;'>" . number_format((float)($order->vat_amount ?? 0), 0, ',', ' ') . "</td>
                    </tr>
                    <tr style='border-top: 2px solid #000; border-bottom: 2px solid #000;'>
                        <td colspan='3' style='padding: 5px; text-align: right; border: 1px solid #000;'><strong>TOTAL TTC</strong></td>
                        <td style='padding: 5px; text-align: right; border: 1px solid #000;'><strong>" . number_format((float)($order->total ?? 0), 0, ',', ' ') . " FCFA</strong></td>
                    </tr>
                </tfoot>
            </table>

            <div style='margin-top: 15px; text-align: right; font-size: 11px;'>
                <p style='margin: 0;'>La Direction</p>
                <br>
                <p style='margin: 0;'>_________________________</p>
            </div>
        </div>";

        return $html;
    }

    public function generateInvoiceA4Pdf(Order $order): string
    {
        $content = $this->invoiceA4Html($order);
        $html = "<html><head><style>body { font-family: Arial, sans-serif; color: #000; margin: 0; padding: 20px; } .wrapper { width: 180mm; margin: 0 auto; }</style></head><body><div class='wrapper'>{$content}</div></body></html>";
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

    /**
     * Génère le HTML A4 professionnel pour une commande de gâteau
     */
    public function cakeOrderA4Html(\App\Models\CakeOrder $cakeOrder): string
    {
        $restaurant = $cakeOrder->restaurant;
        $total = number_format((float)$cakeOrder->total, 0, ',', ' ');
        $advance = number_format((float)$cakeOrder->advance_paid, 0, ',', ' ');
        $remaining = number_format((float)$cakeOrder->remaining_amount, 0, ',', ' ');
        $date = \Carbon\Carbon::parse($cakeOrder->delivery_date)->locale('fr')->isoFormat('LL');
        $time = $cakeOrder->delivery_time ? substr($cakeOrder->delivery_time, 0, 5) : 'N/A';

        $qrPath = public_path('img/website_qr.png');
        $qrBase64 = null;
        if (file_exists($qrPath)) {
            $qrData = base64_encode(file_get_contents($qrPath));
            $qrBase64 = 'data:image/png;base64,' . $qrData;
        }

        return "
        <div style='font-family: Helvetica, sans-serif; color: #000; padding: 15px; border: 1px solid #000; position: relative;'>
            <table style='width: 100%; border-bottom: 2px solid #000; padding-bottom: 8px; margin-bottom: 10px;'>
                <tr>
                    <td style='vertical-align: top;'>
                        <div style='font-size: 18px; font-weight: bold; text-transform: uppercase;'>{$restaurant->name}</div>
                        <div style='font-size: 9px; font-weight: bold; margin-top: 2px;'>
                            {$restaurant->address}<br>
                            Tél: {$restaurant->phone}
                        </div>
                    </td>
                    <td style='text-align: right; vertical-align: top;'>
                        <div style='border: 2px solid #000; padding: 5px 10px; font-weight: bold; font-size: 11px; display: inline-block;'>BON DE COMMANDE</div>
                    </td>
                </tr>
            </table>

            <div style='font-size: 14px; font-weight: bold; margin-bottom: 2px;'>CONFIRMATION DE COMMANDE GÂTEAU</div>
            <div style='font-size: 10px; font-weight: bold; margin-bottom: 15px;'>Réf: #{$cakeOrder->order_number} | Emis le " . date('d/m/Y à H:i') . "</div>

            <table style='width: 100%; border-collapse: collapse; margin-bottom: 15px;'>
                <tr>
                    <td style='width: 48%; border: 1px solid #000; padding: 8px; vertical-align: top;'>
                        <div style='font-size: 8px; font-weight: bold; text-transform: uppercase; margin-bottom: 4px;'>Client</div>
                        <div style='font-size: 11px; font-weight: bold;'>{$cakeOrder->customer_name}</div>
                        <div style='font-size: 10px; font-weight: bold;'>Tél: {$cakeOrder->customer_phone}</div>
                    </td>
                    <td style='width: 4%;'></td>
                    <td style='width: 48%; border: 1px solid #000; padding: 8px; vertical-align: top;'>
                        <div style='font-size: 8px; font-weight: bold; text-transform: uppercase; margin-bottom: 4px;'>Livraison Prévue</div>
                        <div style='font-size: 11px; font-weight: bold;'>{$date}</div>
                        <div style='font-size: 10px; font-weight: bold;'>à {$time}</div>
                    </td>
                </tr>
            </table>

            <table style='width: 100%; border-collapse: collapse; margin-bottom: 10px;'>
                <thead>
                    <tr>
                        <th style='background: #000; color: #fff; padding: 6px; text-align: left; font-size: 9px; text-transform: uppercase;'>Description</th>
                        <th style='background: #000; color: #fff; padding: 6px; text-align: center; font-size: 9px; text-transform: uppercase; width: 60px;'>Qté</th>
                        <th style='background: #000; color: #fff; padding: 6px; text-align: right; font-size: 9px; text-transform: uppercase; width: 100px;'>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td style='padding: 8px 6px; border-bottom: 1px solid #000; font-size: 11px; font-weight: bold;'>Gâteau Personnalisé</td>
                        <td style='padding: 8px 6px; border-bottom: 1px solid #000; font-size: 11px; text-align: center;'>1</td>
                        <td style='padding: 8px 6px; border-bottom: 1px solid #000; font-size: 11px; text-align: right; font-weight: bold;'>{$total} FCFA</td>
                    </tr>
                </tbody>
            </table>

            <div style='border: 1px solid #000; padding: 8px; margin-bottom: 15px;'>
                <div style='font-size: 8px; font-weight: bold; text-transform: uppercase; margin-bottom: 2px;'>Détails & Personnalisation</div>
                <div style='font-size: 10px; font-weight: bold;'>" . nl2br($cakeOrder->notes ?: 'Gâteau personnalisé.') . "</div>
            </div>

            <table style='width: 250px; margin-left: auto; border-collapse: collapse;'>
                <tr>
                    <td style='padding: 4px 0; font-size: 10px; font-weight: bold; text-align: right; padding-right: 15px;'>Sous-total</td>
                    <td style='padding: 4px 0; font-size: 10px; font-weight: bold; text-align: right;'>{$total} FCFA</td>
                </tr>
                <tr>
                    <td style='padding: 4px 0; font-size: 10px; font-weight: bold; text-align: right; padding-right: 15px;'>Acompte</td>
                    <td style='padding: 4px 0; font-size: 10px; font-weight: bold; text-align: right;'>- {$advance} FCFA</td>
                </tr>
                <tr style='background: #000; color: #fff;'>
                    <td style='padding: 8px 15px; font-size: 12px; font-weight: bold; text-align: right;'>RESTE À PAYER</td>
                    <td style='padding: 8px 10px; font-size: 12px; font-weight: bold; text-align: right;'>{$remaining} FCFA</td>
                </tr>
            </table>

            <div style='margin-top: 20px; width: 100%;'>
                <table style='width: 100%;'>
                    <tr>
                        <td style='width: 40%; border-top: 1px dashed #000; padding-top: 5px; text-align: center; font-size: 8px; font-weight: bold;'>Signature Client</td>
                        <td style='width: 20%; text-align: center;'>
                            " . ($qrBase64 ? "<img src='{$qrBase64}' style='width: 50px; height: 50px; filter: grayscale(100%);'>" : "") . "
                        </td>
                        <td style='width: 40%; border-top: 1px dashed #000; padding-top: 5px; text-align: center; font-size: 8px; font-weight: bold;'>Cachet Établissement</td>
                    </tr>
                </table>
            </div>

            <div style='margin-top: 10px; text-align: center; font-size: 7px; font-weight: bold; border-top: 1px solid #000; padding-top: 5px;'>
                #{$cakeOrder->order_number} • Document certifié par Omega POS • " . now()->format('d/m/y H:i') . "
            </div>
        </div>";
    }

    /**
     * Génère un PDF A4 avec 2 copies pour une commande de gâteau
     */
    public function generateCakeOrderA4Pdf(\App\Models\CakeOrder $cakeOrder): string
    {
        $content = $this->cakeOrderA4Html($cakeOrder);
        $html = "<html><head><style>
            @page { margin: 10mm; }
            body { margin: 0; padding: 0; background: #fff; }
            .copy-wrapper { width: 100%; margin-bottom: 10mm; }
            .divider { border-bottom: 1px dashed #000; margin: 10mm 0; width: 100%; }
        </style></head><body>
            <div class='copy-wrapper'>{$content}</div>
            <div class='divider'></div>
            <div class='copy-wrapper'>{$content}</div>
        </body></html>";
        
        return Pdf::loadHTML($html)->setPaper('a4')->output();
    }

    /**
     * Génère le HTML A4 professionnel pour un reçu de dépense
     */
    public function expenseA4Html(Expense $expense): string
    {
        $restaurant = $expense->restaurant;
        $id = str_pad($expense->id, 6, '0', STR_PAD_LEFT);
        $date = $expense->created_at->format('d/m/Y à H:i');
        $amount = number_format((float)$expense->amount, 0, ',', ' ');

        return "
        <div style='font-family: Helvetica, sans-serif; color: #000; padding: 15px; border: 1px solid #000; position: relative;'>
            <table style='width: 100%; border-bottom: 2px solid #000; padding-bottom: 8px; margin-bottom: 10px;'>
                <tr>
                    <td style='vertical-align: top;'>
                        <div style='font-size: 18px; font-weight: bold; text-transform: uppercase;'>{$restaurant->name}</div>
                        <div style='font-size: 9px; font-weight: bold; margin-top: 2px;'>
                            {$restaurant->address}<br>
                            Tél: {$restaurant->phone}
                        </div>
                    </td>
                    <td style='text-align: right; vertical-align: top;'>
                        <div style='border: 2px solid #000; padding: 5px 10px; font-weight: bold; font-size: 11px; display: inline-block;'>PIÈCE COMPTABLE</div>
                    </td>
                </tr>
            </table>

            <div style='font-size: 14px; font-weight: bold; margin-bottom: 2px;'>JUSTIFICATIF DE SORTIE DE CAISSE</div>
            <div style='font-size: 10px; font-weight: bold; margin-bottom: 15px;'>Réf: EXP-{$id} | Émis le {$date}</div>

            <table style='width: 100%; border-collapse: collapse; margin-bottom: 15px;'>
                <tr>
                    <td style='width: 48%; border: 1px solid #000; padding: 8px; vertical-align: top;'>
                        <div style='font-size: 8px; font-weight: bold; text-transform: uppercase; margin-bottom: 4px;'>Bénéficiaire</div>
                        <div style='font-size: 11px; font-weight: bold;'>" . ($expense->beneficiary ?: 'Non spécifié') . "</div>
                    </td>
                    <td style='width: 4%;'></td>
                    <td style='width: 48%; border: 1px solid #000; padding: 8px; vertical-align: top;'>
                        <div style='font-size: 8px; font-weight: bold; text-transform: uppercase; margin-bottom: 4px;'>Agent / Coursier</div>
                        <div style='font-size: 11px; font-weight: bold;'>" . ($expense->agent_name ?: 'Non spécifié') . "</div>
                    </td>
                </tr>
            </table>

            <div style='border: 1px solid #000; padding: 10px; margin-bottom: 15px;'>
                <div style='font-size: 8px; font-weight: bold; text-transform: uppercase; margin-bottom: 4px;'>Motif de la dépense</div>
                <div style='font-size: 12px; font-weight: bold;'>" . strtoupper($expense->description) . "</div>
                <div style='font-size: 9px; color: #333; margin-top: 4px;'>Catégorie: " . strtoupper($expense->category) . "</div>
            </div>

            <div style='background: #000; color: #fff; padding: 15px; text-align: center; margin-bottom: 20px;'>
                <div style='font-size: 10px; text-transform: uppercase; margin-bottom: 5px;'>Montant Total Décaissé</div>
                <div style='font-size: 24px; font-weight: bold;'>{$amount} FCFA</div>
            </div>

            <table style='width: 100%; margin-top: 20px;'>
                <tr>
                    <td style='width: 45%; border-top: 1px dashed #000; padding-top: 8px; text-align: center;'>
                        <div style='font-size: 9px; font-weight: bold; text-transform: uppercase;'>Signature de l'Agent</div>
                        <br><br>
                    </td>
                    <td style='width: 10%;'></td>
                    <td style='width: 45%; border-top: 1px dashed #000; padding-top: 8px; text-align: center;'>
                        <div style='font-size: 9px; font-weight: bold; text-transform: uppercase;'>Validation Direction</div>
                        <br><br>
                    </td>
                </tr>
            </table>

            <div style='margin-top: 15px; text-align: center; font-size: 7px; font-weight: bold; border-top: 1px solid #000; padding-top: 5px;'>
                ID Caisse: #" . ($expense->cash_session_id ?: 'N/A') . " • Établi par: " . ($expense->user->first_name ?? 'Inconnu') . " • " . now()->format('d/m/y H:i') . "
            </div>
        </div>";
    }

    /**
     * Génère un PDF A4 avec 2 copies pour un reçu de dépense
     */
    public function generateExpenseA4Pdf(Expense $expense): string
    {
        $content = $this->expenseA4Html($expense);
        $html = "<html><head><style>
            @page { margin: 10mm; }
            body { margin: 0; padding: 0; background: #fff; }
            .copy-wrapper { width: 100%; }
        </style></head><body>
            <div class='copy-wrapper'>{$content}</div>
        </body></html>";
        
        return Pdf::loadHTML($html)->setPaper('a4')->output();
    }
    public function generateBulkInvoiceA4Pdf($orders): string
    {
        $html = "<html><head><style>
            body { margin: 0; padding: 10mm; font-family: Arial, sans-serif; background: #fff; }
            .invoice-outer { width: 100%; margin-bottom: 20px; }
            .invoice-inner { width: 180mm; margin: 0 auto; }
            .divider { border-bottom: 1px dashed #000; margin: 15px 0; width: 180mm; margin-left: auto; margin-right: auto; }
        </style></head><body>";
        
        $count = 0;
        foreach ($orders as $order) {
            $html .= "<div class='invoice-outer'><div class='invoice-inner'>";
            $html .= $this->invoiceA4Html($order);
            $html .= "</div>";
            if ($count < count($orders) - 1) {
                $html .= "<div class='divider'></div>";
            }
            $html .= "</div>";
            $count++;
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
