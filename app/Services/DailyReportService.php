<?php

namespace App\Services;

use App\Models\CashSession;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\CustomerTab;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DailyReportService
 * Génère le rapport journalier HTML ultra-professionnel (format A4) et l'envoie par email au boss.
 */
class DailyReportService
{
    /**
     * Génère un vrai PDF FPDF pour le rapport de caisse
     */
    public function generateSessionPdf(CashSession $session): string
    {
        $data = $this->getReportData($session);
        $pdf = new \App\Libraries\Fpdf('P', 'mm', 'A4');
        $pdf->SetMargins(15, 15, 15);
        $pdf->AddPage();
        
        // 1. En-tête
        $pdf->SetFont('Helvetica', 'B', 18);
        $pdf->SetTextColor(249, 115, 22); // Orange
        $restaurantName = $data['restaurant'] ? $data['restaurant']->name : 'RESTAURANT';
        $pdf->Cell(0, 8, utf8_decode(strtoupper($restaurantName)), 0, 1, 'C');
        
        $pdf->SetFont('Helvetica', '', 11);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell(0, 6, "RAPPORT FINANCIER DE CAISSE", 0, 1, 'C');
        $pdf->Ln(4);
        
        // 2. Info Session
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(90, 6, "SESSION #" . $session->id, 0, 0, 'L');
        $pdf->Cell(90, 6, "Date: " . ($session->opened_at ? $session->opened_at->format('d/m/Y') : date('d/m/Y')), 0, 1, 'R');
        $pdf->Cell(180, 6, "Caissier: " . utf8_decode(strtoupper($session->user ? $session->user->first_name : 'SYSTEM')), 0, 1, 'L');
        $pdf->Ln(4);
        
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Ln(6);

        // 3. Totaux Principaux
        $pdf->SetFont('Helvetica', 'B', 12);
        
        $currentY = $pdf->GetY();
        
        // Rectangles de fond pour Recette, Dépenses
        $pdf->SetFillColor(30, 41, 59); // Bleu foncé
        $pdf->Rect(10, $currentY, 90, 20, 'F');
        $pdf->SetFillColor(241, 245, 249); // Gris clair
        $pdf->Rect(105, $currentY, 95, 20, 'F');
        
        // Textes
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(10, $currentY + 3);
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->Cell(90, 5, "RECETTE TOTALE", 0, 1, 'C');
        $pdf->SetXY(10, $currentY + 8);
        $pdf->SetFont('Helvetica', 'B', 14);
        $pdf->Cell(90, 8, number_format((float)$data['totalRevenue'], 0, ',', ' ') . " FCFA", 0, 1, 'C');
        
        if ($data['totalVat'] > 0) {
            $pdf->SetXY(10, $currentY + 14);
            $pdf->SetFont('Helvetica', 'I', 7);
            $pdf->Cell(90, 5, utf8_decode("(Dont TVA : " . number_format((float)$data['totalVat'], 0, ',', ' ') . " F)"), 0, 1, 'C');
        }

        $pdf->SetTextColor(71, 85, 105);
        $pdf->SetXY(105, $currentY + 3);
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->Cell(95, 5, utf8_decode("DÉPENSES"), 0, 1, 'C');
        $pdf->SetTextColor(239, 68, 68); // Rouge
        $pdf->SetXY(105, $currentY + 8);
        $pdf->SetFont('Helvetica', 'B', 14);
        $pdf->Cell(95, 8, "- " . number_format((float)$data['totalExpenses'], 0, ',', ' ') . " FCFA", 0, 1, 'C');

        $pdf->SetXY(15, $currentY + 25);
        $pdf->SetTextColor(0, 0, 0);

        // 4. Synthèse Trésorerie
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->SetFillColor(26, 26, 46);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell(0, 8, utf8_decode(" SYNTHESE DES FLUX"), 0, 1, 'L', true);
        
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell(90, 6, "Ouverture de caisse", 'B', 0, 'L');
        $pdf->Cell(90, 6, number_format((float)$session->opening_amount, 0, ',', ' ') . " FCFA", 'B', 1, 'R');
        
        $pdf->Cell(90, 6, "Ventes en especes", 'B', 0, 'L');
        $pdf->Cell(90, 6, "+" . number_format((float)($session->expected_amount - $session->opening_amount + $data['totalExpenses']), 0, ',', ' ') . " FCFA", 'B', 1, 'R');
        
        $pdf->SetTextColor(220, 38, 38);
        $pdf->Cell(90, 6, utf8_decode("Depenses deduites"), 'B', 0, 'L');
        $pdf->Cell(90, 6, "-" . number_format((float)$data['totalExpenses'], 0, ',', ' ') . " FCFA", 'B', 1, 'R');
        
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->Cell(90, 8, "MONTANT TOTAL ATTENDU", 'B', 0, 'L');
        $pdf->Cell(90, 8, number_format((float)$session->expected_amount, 0, ',', ' ') . " FCFA", 'B', 1, 'R');
        
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell(90, 6, "Verse a la Banque", 'B', 0, 'L');
        $pdf->Cell(90, 6, ($session->amount_to_bank ? number_format((float)$session->amount_to_bank, 0, ',', ' ') : '0') . " FCFA", 'B', 1, 'R');
        
        $pdf->Cell(90, 6, "Fond de roulement restant", 'B', 0, 'L');
        $pdf->Cell(90, 6, ($session->remaining_amount ? number_format((float)$session->remaining_amount, 0, ',', ' ') : '0') . " FCFA", 'B', 1, 'R');
        
        $diffColor = ($session->difference ?? 0) >= 0 ? [22, 163, 74] : [220, 38, 38];
        $pdf->SetFont('Helvetica', 'B', 13);
        $pdf->Cell(90, 10, utf8_decode("ECART FINAL"), 'B', 0, 'L');
        $pdf->SetTextColor($diffColor[0], $diffColor[1], $diffColor[2]);
        $pdf->Cell(90, 10, number_format((float)($session->difference ?? 0), 0, ',', ' ') . " FCFA", 'B', 1, 'R');
        
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(8);

        // 5. Modes de Paiements
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->SetFillColor(26, 26, 46);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell(0, 8, utf8_decode(" DETAILS DES ENCAISSEMENTS"), 0, 1, 'L', true);
        
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell(70, 6, "Mode", 'B', 0, 'L');
        $pdf->Cell(40, 6, "Nb", 'B', 0, 'C');
        $pdf->Cell(70, 6, "Montant", 'B', 1, 'R');
        
        $pdf->SetFont('Helvetica', '', 10);
        foreach ($data['payments'] as $p) {
            $pdf->Cell(70, 6, strtoupper($p->method), 'B', 0, 'L');
            $pdf->Cell(40, 6, $p->count, 'B', 0, 'C');
            $pdf->Cell(70, 6, number_format((float)$p->total, 0, ',', ' ') . " FCFA", 'B', 1, 'R');
        }

        $pdf->Ln(8);

        // 5.5 Performances des secteurs (Catégories) et Crédits
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->SetFillColor(26, 26, 46);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell(0, 8, utf8_decode(" PERFORMANCES DES SECTEURS ET CRÉDITS"), 0, 1, 'L', true);
        
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell(130, 6, utf8_decode("Secteur / Catégorie"), 'B', 0, 'L');
        $pdf->Cell(50, 6, "Recette", 'B', 1, 'R');
        
        $pdf->SetFont('Helvetica', '', 10);
        foreach ($data['categoryStats'] as $catStat) {
            if ($pdf->GetY() > 270) $pdf->AddPage();
            $pdf->Cell(130, 6, utf8_decode("VENTES - " . strtoupper($catStat->category_name)), 'B', 0, 'L');
            $pdf->SetTextColor(16, 185, 129); // Green text
            $pdf->Cell(50, 6, number_format((float)$catStat->total_amount, 0, ',', ' ') . " FCFA", 'B', 1, 'R');
            $pdf->SetTextColor(0, 0, 0); // Reset
        }
        
        if ($pdf->GetY() > 270) $pdf->AddPage();
        $pdf->SetTextColor(234, 88, 12); // Orange text
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(130, 6, utf8_decode("TOTAL DES ARDOISES (CRÉDITS)"), 'B', 0, 'L');
        $pdf->Cell(50, 6, number_format((float)$data['totalCredits'], 0, ',', ' ') . " FCFA", 'B', 1, 'R');
        $pdf->SetTextColor(0, 0, 0); // Reset
        
        $pdf->Ln(8);

        return $pdf->Output('S');
    }

    /**
     * Envoie le rapport par email au boss (Email de luxe)
     */
    public function getReportData(CashSession $session): array
    {
        $revenueData = \App\Models\Order::whereHas('payments', fn($q) => $q->where('cash_session_id', $session->id))
            ->selectRaw('SUM(total) as revenue, SUM(vat_amount) as vat')
            ->first();
            
        $totalRevenue = (float)($revenueData->revenue ?? 0);
        $totalVat = (float)($revenueData->vat ?? 0);
        $totalExpenses = Expense::where('cash_session_id', $session->id)->sum('amount');
        $restaurant = $session->restaurant;
        
        $newCredits = \App\Models\Order::whereHas('customerTabs')
            ->whereBetween('paid_at', [$session->opened_at, $session->closed_at ?? now()])
            ->sum('total');

        $tabDetails = \App\Models\Order::whereHas('customerTabs')
            ->with(['customerTabs'])
            ->whereBetween('paid_at', [$session->opened_at, $session->closed_at ?? now()])
            ->get();

        $payments = \App\Models\Payment::where('cash_session_id', $session->id)
            ->selectRaw('method, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('method')
            ->get();

        $productStats = \Illuminate\Support\Facades\DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('payments', 'payments.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->where('payments.cash_session_id', $session->id)
            ->select('products.name', \Illuminate\Support\Facades\DB::raw('SUM(order_items.quantity) as total_qty'), \Illuminate\Support\Facades\DB::raw('SUM(order_items.subtotal) as total_amount'))
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('total_amount')
            ->get();

        $categoryStats = \Illuminate\Support\Facades\DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('payments', 'payments.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->where('payments.cash_session_id', $session->id)
            ->select('categories.name as category_name', \Illuminate\Support\Facades\DB::raw('SUM(order_items.quantity) as total_qty'), \Illuminate\Support\Facades\DB::raw('SUM(order_items.subtotal) as total_amount'))
            ->groupBy('categories.id', 'categories.name')
            ->orderByDesc('total_amount')
            ->get();

        $logs = \App\Models\ActivityLog::where('restaurant_id', $session->restaurant_id)
            ->where(function($q) use ($session) {
                $q->where(function($sq) use ($session) {
                    $sq->where('subject_type', CashSession::class)
                       ->where('subject_id', $session->id);
                })->orWhere(function($sq) use ($session) {
                    $sq->where('module', 'cash')
                      ->whereBetween('created_at', [$session->opened_at, $session->closed_at ?? now()]);
                });
            })->with('user')->orderBy('created_at', 'asc')->limit(100)->get();

        $pdfUrl = config('app.url') . "/api/cash-sessions/{$session->id}/report-preview";

        return [
            'session' => $session,
            'totalRevenue' => $totalRevenue,
            'totalVat' => $totalVat,
            'totalExpenses' => $totalExpenses,
            'totalCredits' => $newCredits,
            'tabDetails' => $tabDetails,
            'payments' => $payments,
            'productStats' => $productStats,
            'categoryStats' => $categoryStats, // Ajout
            'logs' => $logs,
            'restaurant' => $restaurant,
            'pdfUrl' => $pdfUrl,
        ];
    }

    /**
     * Envoie le rapport de caisse par email
     *
     * @param CashSession $session
     * @param string $toEmail
     * @return bool
     */
    public function sendReportByEmail(CashSession $session, string $toEmail): bool
    {
        Log::info("Tentative d'envoi du rapport de caisse à : " . $toEmail);

        try {
            $data = $this->getReportData($session);
            $restaurant = $session->restaurant;

            $subject = "Rapport de Caisse — {$restaurant->name} — " . ($session->opened_at?->format('d/m/Y') ?? date('d/m/Y'));

            Mail::send('reports.cash_session', $data, function ($message) use ($toEmail, $subject) {
                $message->to($toEmail)->subject($subject);
            });

            $session->update([
                'report_sent_at' => now(),
                'report_email'   => $toEmail,
            ]);

            Log::info("Rapport envoyé avec succès à : " . $toEmail);
            return true;
        } catch (\Exception $e) {
            Log::error('Erreur SMTP lors de l\'envoi du rapport : ' . $e->getMessage());
            return false;
        }
    }


}
