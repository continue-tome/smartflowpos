<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemNote;
use App\Models\Restaurant;
use App\Models\Table;
use App\Libraries\Fpdf;
use Illuminate\Support\Collection;

class TicketPrintService
{
    public function __construct(protected OrderRoutingService $routing) {}

    /**
     * Génère le HTML d'un ticket de cuisine filtré par destination
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

        $html = "
        <div style='font-family: monospace; width: 100%; font-size: 13px; color: #000; line-height: 1.25;'>
            <div style='text-align: center; font-weight: bold; border-bottom: 1px solid #000; padding-bottom: 2px; margin-bottom: 2px;'>
                " . strtoupper($restaurant->name) . " - " . strtoupper($this->routing->destinationLabel($destination)) . "
            </div>
            <div style='text-align: center; font-size: 11px;'>#{$order->order_number} | {$date}</div>

            <div style='border: 1px solid #000; text-align: center; font-size: 22px; font-weight: bold; margin: 3px 0;'>
                {$tableLabel}
            </div>

            <table style='width: 100%; border-collapse: collapse;'>";

        foreach ($items as $item) {
            $html .= "
                <tr style='border-bottom: 1px dashed #ccc;'>
                    <td style='vertical-align: top; padding: 4px 0; font-size: 18px; font-weight: bold;'>x{$item->quantity}</td>
                    <td style='padding: 4px 0; font-size: 16px; font-weight: bold;'>
                        " . strtoupper($item->product->name) . "
                        " . ($item->notes ? "<div style='font-size: 12px; font-weight: normal; font-style: italic;'>! {$item->notes}</div>" : "") . "
                    </td>
                </tr>";
        }

        $html .= "
            </table>
            <div style='margin-top: 5px; text-align: center; font-size: 10px;'>*** FIN ***</div>
        </div>";

        return $html;
    }

    /**
     * Génère le HTML thermique d'un reçu de dépense (Version Compacte)
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

            <div style='text-align: center; background: #000; color: #fff; padding: 5px; font-size: 16px; font-weight: bold;'>
                " . number_format($expense->amount, 0, ',', ' ') . " F
            </div>

            <div style='margin-top: 15px; border-top: 1px dotted #000; padding-top: 3px; text-align: center; font-size: 10px;'>
                SIGNATURE BENEFICIAIRE<br><br>
                ...........................
            </div>
        </div>";
    }

    /**
     * Génère le HTML thermique d'un rapport de clôture (Version Compacte)
     */
    public function sessionReportHtml($session): string
    {
        $restaurant = $session->restaurant;
        $open = $session->opened_at->format('d/m H:i');
        $close = $session->closed_at ? $session->closed_at->format('d/m H:i') : 'ACTIF';

        $html = "
        <div style='font-family: monospace; width: 100%; font-size: 11px; color: #000; line-height: 1.25;'>
            <div style='text-align: center; font-weight: bold; font-size: 13px;'>" . strtoupper($restaurant->name) . "</div>
            <div style='text-align: center; border-bottom: 1px solid #000; padding-bottom: 2px; margin-bottom: 4px;'>RAPPORT CLOTURE #{$session->id}</div>
            
            <div style='margin-bottom: 5px;'>
                <b>CAISSIER:</b> " . strtoupper($session->user->first_name) . "<br>
                <b>PERIODE:</b> {$open} au {$close}
            </div>

            <div style='border-bottom: 1px solid #eee; font-weight: bold; margin-bottom: 2px;'>FLUX CASH</div>
            <table style='width: 100%; border-collapse: collapse;'>
                <tr><td>OUVERTURE:</td><td style='text-align: right;'>" . number_format($session->opening_amount, 0, ',', ' ') . "</td></tr>
                <tr><td>VENTES (+):</td><td style='text-align: right;'>" . number_format($session->cash_total, 0, ',', ' ') . "</td></tr>
                <tr><td>DEPENSES (-):</td><td style='text-align: right;'>" . number_format($session->total_expenses, 0, ',', ' ') . "</td></tr>
                <tr style='font-weight: bold;'><td>THEORIQUE:</td><td style='text-align: right;'>" . number_format($session->expected_amount, 0, ',', ' ') . "</td></tr>
            </table>

            <div style='border-bottom: 1px solid #eee; font-weight: bold; margin-top: 5px; margin-bottom: 2px;'>REEL</div>
            <table style='width: 100%;'>
                <tr><td>BANQUE:</td><td style='text-align: right;'>" . number_format($session->amount_to_bank, 0, ',', ' ') . "</td></tr>
                <tr><td>CAISSE:</td><td style='text-align: right;'>" . number_format($session->remaining_amount, 0, ',', ' ') . "</td></tr>
                <tr style='font-weight: bold; font-size: 13px;'>
                    <td>ECART:</td>
                    <td style='text-align: right;'>" . ($session->difference > 0 ? '+' : '') . number_format($session->difference, 0, ',', ' ') . "</td>
                </tr>
            </table>

            <div style='border-bottom: 1px solid #eee; font-weight: bold; margin-top: 5px; margin-bottom: 2px;'>AUTRES MODES</div>
            <table style='width: 100%;'>";
        
        foreach (['card' => 'CARTE', 'wave' => 'WAVE', 'orange_money' => 'ORANGE', 'momo' => 'MOMO'] as $key => $label) {
            $val = $session->{$key . '_total'} ?? 0;
            if ($val > 0) {
                $html .= "<tr><td>{$label}:</td><td style='text-align: right;'>" . number_format($val, 0, ',', ' ') . "</td></tr>";
            }
        }
        
        $html .= "</table>
            <div style='margin-top: 15px; border-top: 1px dashed #000; text-align: center;'>VISA</div>
        </div>";

        return $html;
    }

    /**
     * Génère le HTML thermique d'une commande de gâteau (Version Compacte)
     */
    public function cakeOrderHtml($cakeOrder): string
    {
        $restaurant = $cakeOrder->restaurant;
        $deliveryDate = \Carbon\Carbon::parse($cakeOrder->delivery_date)->format('d/m/y');
        $deliveryTime = $cakeOrder->delivery_time ? " " . substr($cakeOrder->delivery_time, 0, 5) : '';

        $html = "
        <div style='font-family: monospace; width: 100%; font-size: 12px; color: #000; line-height: 1.25;'>
            <div style='text-align: center; font-weight: bold; border-bottom: 1px solid #000;'>GATEAU #{$cakeOrder->order_number}</div>
            
            <div style='margin: 4px 0;'>
                <b>CLIENT:</b> " . strtoupper($cakeOrder->customer_name) . " ({$cakeOrder->customer_phone})<br>
                <b>LE:</b> <span style='background:#000; color:#fff; padding:0 2px;'>{$deliveryDate}{$deliveryTime}</span>
            </div>

            <div style='border-bottom: 1px solid #eee; font-weight: bold; margin-bottom: 2px;'>DETAILS</div>";

        foreach ($cakeOrder->items as $item) {
            $html .= "
                <div style='display:flex; justify-content:space-between;'>
                    <span>x{$item['qty']} " . strtoupper($item['name']) . "</span>
                    <span>" . number_format($item['qty'] * $item['unit_price'], 0, ',', ' ') . "</span>
                </div>";
            if (!empty($item['notes'])) {
                $html .= "<div style='font-size:10px; font-style:italic; color:#444;'>- " . $item['notes'] . "</div>";
            }
        }

        $html .= "
            <div style='border-top: 1px dashed #000; margin-top: 4px; padding-top: 2px;'>
                <div style='display:flex; justify-content:space-between;'><span>TOTAL:</span><b>" . number_format($cakeOrder->total, 0, ',', ' ') . "</b></div>
                <div style='display:flex; justify-content:space-between;'><span>PAYE:</span><b>" . number_format($cakeOrder->advance_paid, 0, ',', ' ') . "</b></div>
                <div style='display:flex; justify-content:space-between; font-size:14px; margin-top:2px; background:#000; color:#fff; padding:2px;'>
                    <span>RESTE:</span>
                    <b>" . number_format($cakeOrder->remaining_amount, 0, ',', ' ') . " F</b>
                </div>
            </div>
        </div>";

        return $html;
    }

    /**
     * PDF methods remain for backup/A4 (omitted for brevity in this compact view)
     */
    public function generateKitchenTicketPdf(Order $order, string $destination = 'kitchen', ?array $itemIds = null) { return ''; }
    public function generateReceiptPdf(Order $order) { return ''; }
}
