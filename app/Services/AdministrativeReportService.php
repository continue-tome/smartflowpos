<?php

namespace App\Services;

use FPDF;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Expense;
use App\Models\CakeOrder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AdministrativeReportService extends FPDF
{
    private $data;
    private $restaurant;

    public function generateDailyReport($date, $restaurant)
    {
        $this->restaurant = $restaurant;
        $this->data = $this->collectData($date, $restaurant->id);

        $this->AddPage();
        $this->SetAutoPageBreak(true, 20);

        // Header
        $this->renderHeader();

        // 1. Resume Global
        $this->renderFinancialSummary();

        // 2. Pillars Breakdown (Cuisine, Pizza, Bar, Gateaux)
        $this->renderPillarsBreakdown();

        // 3. Payments Breakdown
        $this->renderPaymentsBreakdown();

        // 4. Expenses
        $this->renderExpenses();

        // Footer Signatures
        $this->renderSignatures();

        return $this->Output('S');
    }

    /** Helper pour gerer les accents en FPDF */
    private function s($str)
    {
        return iconv('UTF-8', 'windows-1252//TRANSLIT', $str ?? '');
    }

    private function collectData($date, $restaurantId)
    {
        $today = $date;

        // 1. CHIFFRE D'AFFAIRES (Ventes RÉELLES du jour : Commandes et Gâteaux créés aujourd'hui)
        $orderStats = Order::where('restaurant_id', $restaurantId)
            ->whereDate('created_at', $today)
            ->where('status', '!=', 'cancelled')
            ->selectRaw('COUNT(*) as count, SUM(total) as revenue, SUM(covers) as covers, SUM(vat_amount) as vat')->first();
            
        $restaurant_ca = (float)($orderStats->revenue ?? 0);

        $cake_value_today = \App\Models\CakeOrder::where('restaurant_id', $restaurantId)
            ->whereDate('created_at', $today)
            ->where('status', '!=', 'cancelled')
            ->sum('total');

        $total_ca = $restaurant_ca + (float)$cake_value_today;

        // 2. ARGENT COLLECTÉ (Liquidité entrante aujourd'hui)
        $payments = Payment::where(function($q) use ($restaurantId) {
                $q->whereHas('order', fn($q) => $q->where('restaurant_id', $restaurantId))
                  ->orWhereHas('cakeOrder', fn($q) => $q->where('restaurant_id', $restaurantId));
            })
            ->whereDate('created_at', $today)
            ->selectRaw('method, SUM(amount) as total, COUNT(*) as count')->groupBy('method')->get();

        $total_collected = (float)$payments->sum('total');

        // 3. LE PONT MATHÉMATIQUE (Différences)
        // A. Paiements reçus aujourd'hui pour les ventes d'aujourd'hui
        $paymentsOnTodayOrders = Payment::where(function($q) use ($restaurantId, $today) {
                $q->whereHas('order', fn($q) => $q->where('restaurant_id', $restaurantId)->whereDate('created_at', $today))
                  ->orWhereHas('cakeOrder', fn($q) => $q->where('restaurant_id', $restaurantId)->whereDate('created_at', $today));
            })
            ->whereDate('created_at', $today)
            ->sum('amount');

        // Impayés du jour (Ardoises, crédits, restes à payer sur les ventes d'aujourd'hui)
        $unpaid_from_today = max(0, $total_ca - $paymentsOnTodayOrders);

        // B. Paiements reçus aujourd'hui pour des commandes antérieures (Acquittement de dettes)
        $past_debts_collected_today = Payment::where(function($q) use ($restaurantId, $today) {
                $q->whereHas('order', fn($q) => $q->where('restaurant_id', $restaurantId)->whereDate('created_at', '<', $today))
                  ->orWhereHas('cakeOrder', fn($q) => $q->where('restaurant_id', $restaurantId)->whereDate('created_at', '<', $today));
            })
            ->whereDate('created_at', $today)
            ->sum('amount');

        // DEPENSES
        $expenses = Expense::where('restaurant_id', $restaurantId)
            ->whereDate('created_at', $today)
            ->get();

        // VENTILATION PAR SECTION (Sur les commandes créées aujourd'hui)
        $byDestination = OrderItem::join('products', 'order_items.product_id', '=', 'products.id')
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->whereHas('order', fn($q) => $q->where('restaurant_id', $restaurantId)->whereDate('created_at', $today)->where('status', '!=', 'cancelled'))
            ->whereNull('order_items.deleted_at')
            ->selectRaw('categories.destination, SUM(order_items.subtotal) as revenue')
            ->groupBy('categories.destination')
            ->get()
            ->pluck('revenue', 'destination');

        return [
            'date'           => $today,
            'total_collected' => $total_collected,
            'restaurant_ca'  => $restaurant_ca,
            'payments'       => $payments,
            'by_destination' => $byDestination,
            'cake_revenue'   => (float)$cake_value_today,
            'expenses'       => $expenses,
            'order_stats'    => $orderStats,
            'unpaid_ardoises_today' => (float)$unpaid_from_today,
            'past_debts_collected_today' => (float)$past_debts_collected_today,
        ];
    }

    private function renderHeader()
    {
        // Logo
        if ($this->restaurant->logo && file_exists(storage_path('app/public/' . $this->restaurant->logo))) {
            try {
                $this->Image(storage_path('app/public/' . $this->restaurant->logo), 90, 10, 30);
                $this->Ln(25);
            } catch (\Exception $e) {}
        }

        // Direction
        $this->SetFont('Arial', 'B', 16);
        $this->Cell(0, 10, $this->s(strtoupper($this->restaurant->name)), 0, 1, 'C');

        $subtitle = data_get($this->restaurant->settings, 'receipt_subtitle');
        if ($subtitle) {
            $this->SetFont('Arial', 'B', 11);
            $this->SetTextColor(100, 100, 100);
            $this->Cell(0, 7, $this->s(strtoupper($subtitle)), 0, 1, 'C');
            $this->SetTextColor(0);
        }
        
        $this->SetFont('Arial', '', 10);
        if ($this->restaurant->address) $this->Cell(0, 5, $this->s($this->restaurant->address), 0, 1, 'C');
        if ($this->restaurant->phone)   $this->Cell(0, 5, "Telephone: " . $this->s($this->restaurant->phone), 0, 1, 'C');
        
        $this->Ln(10);
        
        // Titre
        $this->SetFillColor(240, 240, 240);
        $this->SetFont('Arial', 'B', 14);
        $this->Cell(0, 12, $this->s("RAPPORT D'ANALYSE ADMINISTRATIVE - " . Carbon::parse($this->data['date'])->format('d/m/Y')), 1, 1, 'C', true);
        
        $this->Ln(10);
    }

    private function renderFinancialSummary()
    {
        $this->SetFont('Arial', 'B', 12);
        $this->SetTextColor(0);
        $this->Cell(0, 10, $this->s("1. RÉSUMÉ FINANCIER GLOBAL"), 0, 1, 'L');
        $this->Line($this->GetX(), $this->GetY(), $this->GetX() + 190, $this->GetY());
        $this->Ln(5);

        $this->SetFont('Arial', 'B', 11);
        $this->Cell(120, 8, $this->s("A. CHIFFRE D'AFFAIRES (Valeur des Ventes):"), 0, 0);
        $caTotal = $this->data['restaurant_ca'] + $this->data['cake_revenue'];
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(70, 8, number_format($caTotal, 0, ',', ' ') . " FCFA", 0, 1, 'R');
        
        $this->SetFont('Arial', '', 11);
        $this->Cell(120, 8, $this->s("   - Ventes Restaurant / Cuisine:"), 0, 0);
        $this->Cell(70, 8, number_format($this->data['restaurant_ca'], 0, ',', ' ') . " FCFA", 0, 1, 'R');

        $this->Cell(120, 8, $this->s("   - Ventes Pâtisserie / Gâteaux:"), 0, 0);
        $this->Cell(70, 8, number_format($this->data['cake_revenue'], 0, ',', ' ') . " FCFA", 0, 1, 'R');

        $totalVat = ($this->data['order_stats']->vat ?? 0);
        if ($totalVat > 0) {
            $this->SetFont('Arial', 'I', 10);
            $this->SetTextColor(100, 100, 100);
            $this->Cell(120, 6, $this->s("     (Dont TVA Incluse à reverser : " . number_format($totalVat, 0, ',', ' ') . " FCFA)"), 0, 1, 'L');
            $this->SetTextColor(0);
            $this->SetFont('Arial', '', 11);
        }
        
        $this->Ln(4);
        
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(120, 8, $this->s("B. ARGENT COLLECTÉ (Total Encaissé en caisse):"), 0, 0);
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(70, 8, number_format($this->data['total_collected'], 0, ',', ' ') . " FCFA", 0, 1, 'R');
        $this->SetFont('Arial', 'I', 9);
        $this->Cell(190, 5, $this->s("   *L'argent collecté diffère du CA notamment à cause des ardoises et des acomptes :*"), 0, 1, 'L');
        
        $this->SetFont('Arial', 'I', 11);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(120, 7, $this->s("   - Ardoises / Crédits accordés ce jour (Restant à payer):"), 0, 0);
        $this->Cell(70, 7, number_format($this->data['unpaid_ardoises_today'], 0, ',', ' ') . " FCFA", 0, 1, 'R');

        $this->Cell(120, 7, $this->s("   - Recouvrements (Paiements perçus pour paiements antérieurs):"), 0, 0);
        $this->Cell(70, 7, number_format($this->data['past_debts_collected_today'], 0, ',', ' ') . " FCFA", 0, 1, 'R');
        $this->SetTextColor(0);

        $this->Ln(4);
        $this->Line($this->GetX() + 80, $this->GetY(), $this->GetX() + 190, $this->GetY());
        $this->Ln(4);

        $this->SetFont('Arial', '', 11);
        $this->Cell(95, 8, $this->s("Nombre de Commandes (Restaurant):"), 0, 0);
        $this->Cell(95, 8, $this->data['order_stats']->count ?? 0, 0, 1, 'R');
        
        $this->Cell(95, 8, $this->s("Total Couverts:"), 0, 0);
        $this->Cell(95, 8, $this->data['order_stats']->covers ?? 0, 0, 1, 'R');

        $this->Ln(8);
    }

    private function renderPillarsBreakdown()
    {
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 10, $this->s("2. ANALYSE DES VENTES PAR SECTION"), 0, 1, 'L');
        $this->Line($this->GetX(), $this->GetY(), $this->GetX() + 190, $this->GetY());
        $this->Ln(5);

        $this->SetFont('Arial', 'B', 10);
        $this->SetFillColor(230, 230, 230);
        $this->Cell(120, 10, "Section", 1, 0, 'L', true);
        $this->Cell(70, 10, $this->s("Valeur des Ventes"), 1, 1, 'R', true);

        $this->SetFont('Arial', '', 11);
        
        $pillars = [
            'cuisine' => 'Cuisine / Restaurant',
            'bar'     => 'Bar / Boissons',
            'pizza'   => 'Pizzeria',
            'gateaux' => 'Pâtisserie / Gâteaux'
        ];

        $sumOfSections = 0;
        foreach ($pillars as $key => $label) {
            $amount = 0;
            if ($key === 'gateaux') {
                $amount = $this->data['cake_revenue'];
            } else {
                $amount = $this->data['by_destination'][$key] ?? 0;
                $sumOfSections += $amount;
            }

            $this->Cell(120, 10, $this->s($label), 1, 0, 'L');
            $this->Cell(70, 10, number_format($amount, 0, ',', ' ') . " F", 1, 1, 'R');
        }

        // Différence due aux remises ou aux frais de livraison
        $annexes = $this->data['restaurant_ca'] - $sumOfSections;
        if (round($annexes, 2) != 0) {
            $this->SetFont('Arial', 'I', 10);
            $this->Cell(120, 10, $this->s("Remises et Ajustements"), 1, 0, 'L');
            $this->Cell(70, 10, number_format($annexes, 0, ',', ' ') . " F", 1, 1, 'R');
            $this->SetFont('Arial', '', 11);
        }

        // Ligne de totalisation pour prouver que Section 2 = Section 1
        $this->SetFont('Arial', 'B', 11);
        $totalCA = $this->data['restaurant_ca'] + $this->data['cake_revenue'];
        $this->Cell(120, 10, $this->s("TOTAL GLOBAL DES VENTES"), 1, 0, 'R', true);
        $this->Cell(70, 10, number_format($totalCA, 0, ',', ' ') . " F", 1, 1, 'R', true);

        $this->Ln(10);
    }

    private function renderPaymentsBreakdown()
    {
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 10, $this->s("3. DÉTAIL DES ENCAISSEMENTS PAR MODE"), 0, 1, 'L');
        $this->Line($this->GetX(), $this->GetY(), $this->GetX() + 190, $this->GetY());
        $this->Ln(5);

        $this->SetFont('Arial', 'B', 10);
        $this->Cell(80, 10, "Mode de Paiement", 1, 0, 'L', true);
        $this->Cell(50, 10, "Nb. Transactions", 1, 0, 'C', true);
        $this->Cell(60, 10, $this->s("Total Encaissé"), 1, 1, 'R', true);

        $this->SetFont('Arial', '', 11);
        foreach ($this->data['payments'] as $p) {
            $methodMapping = [
                'cash' => 'Espèces', 
                'card' => 'Carte Bancaire', 
                'wave' => 'Wave', 
                'orange_money' => 'Orange Money', 
                'momo' => 'MTN MoMo',
                'mixx' => 'Mixx Luck',
                'moov' => 'Moov Money'
            ];
            $method = $methodMapping[$p->method] ?? ucfirst(str_replace('_', ' ', $p->method));
            
            $this->Cell(80, 10, $this->s($method), 1, 0, 'L');
            $this->Cell(50, 10, $p->count, 1, 0, 'C');
            $this->Cell(60, 10, number_format($p->total, 0, ',', ' ') . " F", 1, 1, 'R');
        }

        $this->Ln(10);
    }

    private function renderExpenses()
    {
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 10, $this->s("4. JOURNAL DES DÉPENSES"), 0, 1, 'L');
        $this->Line($this->GetX(), $this->GetY(), $this->GetX() + 190, $this->GetY());
        $this->Ln(5);

        if ($this->data['expenses']->isEmpty()) {
            $this->SetFont('Arial', 'I', 11);
            $this->Cell(0, 10, $this->s("Aucune dépense enregistrée pour cette date."), 0, 1, 'L');
        } else {
            $this->SetFont('Arial', 'B', 10);
            $this->Cell(130, 10, $this->s("Motif / Bénéficiaire"), 1, 0, 'L', true);
            $this->Cell(60, 10, "Montant", 1, 1, 'R', true);

            $this->SetFont('Arial', '', 10);
            foreach ($this->data['expenses'] as $e) {
                $label = $e->category . " - " . ($e->description ?? $e->beneficiary);
                $this->Cell(130, 10, $this->s($label), 1, 0, 'L');
                $this->Cell(60, 10, "- " . number_format($e->amount, 0, ',', ' ') . " F", 1, 1, 'R');
            }
            
            $this->SetFont('Arial', 'B', 11);
            $this->Cell(130, 10, $this->s("TOTAL DÉPENSES"), 1, 0, 'R', true);
            $this->Cell(60, 10, "- " . number_format((float)$this->data['expenses']->sum('amount'), 0, ',', ' ') . " F", 1, 1, 'R', true);
        }

        $this->Ln(20);
    }

    private function renderSignatures()
    {
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(63, 10, "La Caisse", 0, 0, 'C');
        $this->Cell(63, 10, "Comptabilite", 0, 0, 'C');
        $this->Cell(63, 10, "Direction", 0, 1, 'C');
        
        $this->Ln(15);
        $this->Cell(63, 10, "..........................", 0, 0, 'C');
        $this->Cell(63, 10, "..........................", 0, 0, 'C');
        $this->Cell(63, 10, "..........................", 0, 1, 'C');
    }
}
