<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashSession;
use App\Models\Payment;
use App\Models\Expense;
use App\Services\DailyReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashSessionController extends Controller
{
    public function __construct(protected DailyReportService $reportService) {}

    /** Session courante avec tous les totaux */
    public function current(Request $request)
    {
        $session = CashSession::where('restaurant_id', $request->user()->restaurant_id)
            ->whereNull('closed_at')
            ->with('user:id,first_name,last_name')
            ->latest()
            ->first();

        if (!$session) return response()->json(null);

        $totals = $this->computeTotals($session);
        $expensesTotal = Expense::where('cash_session_id', $session->id)->sum('amount');

        // On injecte les valeurs dynamiques dont le frontend a besoin
        $session->total_sales = $totals['grand_total'];
        $session->expected_amount = (float)$session->opening_amount + (float)$totals['cash'] - (float)$expensesTotal;
        $session->total_expenses = (float)$expensesTotal;

        return response()->json([
            'session'            => $session,
            'payments_by_method' => $totals,
            'totals'             => $totals,
            'orders_count'       => Payment::where('cash_session_id', $session->id)->distinct('order_id')->count(),
            'expenses_total'     => $expensesTotal,
        ]);
    }

    /** Ouvrir une session de caisse */
    public function open(Request $request)
    {
        $existing = CashSession::where('restaurant_id', $request->user()->restaurant_id)
            ->whereNull('closed_at')->exists();
        abort_if($existing, 422, 'Une session de caisse est déjà ouverte.');

        $request->validate(['opening_amount' => 'required|numeric|min:0']);

        $session = CashSession::create([
            'restaurant_id'  => $request->user()->restaurant_id,
            'user_id'        => $request->user()->id,
            'opening_amount' => $request->opening_amount,
            'opened_at'      => now(),
        ]);

        $session->logActivity('cash_session_opened', "Session caisse ouverte avec {$request->opening_amount} FCFA");

        return response()->json($session, 201);
    }

    /** Fermer la session + totaux par méthode + email optionnel */
    public function close(Request $request, CashSession $session)
    {
        abort_if($session->restaurant_id !== $request->user()->restaurant_id, 403);
        abort_if((bool) $session->closed_at, 422, 'Session déjà fermée.');
        abort_unless($request->user()->hasRole(['admin', 'manager', 'cashier']), 403, 'Permission requise pour fermer la caisse.');

        $request->validate([
            'amount_to_bank'   => 'required|numeric|min:0',
            'remaining_amount' => 'required|numeric|min:0',
            'notes'            => 'nullable|string',
            'send_report_to'   => 'nullable|email',
        ]);

        $closingAmount = (float)$request->amount_to_bank + (float)$request->remaining_amount;

        // Calcul des totaux par méthode de paiement
        $totals   = $this->computeTotals($session);
        $cashIn   = $totals['cash'] ?? 0;
        $expected = $session->opening_amount + $cashIn;
        $diff     = $closingAmount - $expected;
        $expenses = Expense::where('cash_session_id', $session->id)->sum('amount');

        DB::transaction(function () use ($session, $request, $totals, $expected, $diff, $expenses, $closingAmount) {
            $session->update([
                'closing_amount'      => $closingAmount,
                'amount_to_bank'      => $request->amount_to_bank,
                'remaining_amount'    => $request->remaining_amount,
                'expected_amount'     => $expected,
                'difference'          => $diff,
                'closing_notes'       => $request->notes,
                'closed_at'           => now(),
                'cash_total'          => $totals['cash'] ?? 0,
                'card_total'          => $totals['card'] ?? 0,
                'wave_total'          => $totals['wave'] ?? 0,
                'orange_money_total'  => $totals['orange_money'] ?? 0,
                'momo_total'          => $totals['momo'] ?? 0,
                'other_total'         => $totals['other'] ?? 0,
                'total_expenses'      => $expenses,
            ]);
        });

        $session->logActivity('cash_session_closed',
            "Session fermée. Attendu: {$expected} | Compté: {$request->closing_amount} | Écart: {$diff}"
        );

        // Envoi email rapport (Uniquement si les montants sont renseignés)
        $emailSent = false;
        $emailTo   = $request->send_report_to;
        if ($emailTo && !is_null($session->amount_to_bank) && !is_null($session->remaining_amount)) {
            $emailSent = $this->reportService->sendReportByEmail($session, $emailTo);
        }

        return response()->json([
            'session'    => $session->fresh(),
            'totals'     => $totals,
            'email_sent' => $emailSent,
            'email_to'   => $emailTo,
        ]);
    }

    /** Envoyer le rapport d'une session fermée par email */
    public function sendReport(Request $request, CashSession $session)
    {
        abort_if($session->restaurant_id !== $request->user()->restaurant_id, 403);
        abort_unless($request->user()->hasRole(['admin', 'manager', 'cashier']), 403, 'Permission requise.');

        $request->validate([
            'email' => 'required|email',
            'amount_to_bank' => 'nullable|numeric',
            'remaining_amount' => 'nullable|numeric'
        ]);

        // Mise à jour de la session avec les montants saisis avant envoi du rapport
        if ($request->has('amount_to_bank') || $request->has('remaining_amount')) {
            $session->update([
                'amount_to_bank' => $request->amount_to_bank ?? $session->amount_to_bank,
                'remaining_amount' => $request->remaining_amount ?? $session->remaining_amount,
            ]);
            
            // Recalculer la différence (écart) s'il y a lieu
            if ($session->expected_amount > 0) {
                $totalCounted = (float)$session->amount_to_bank + (float)$session->remaining_amount;
                $session->update(['difference' => $totalCounted - $session->expected_amount]);
            }
        }

        $sent = $this->reportService->sendReportByEmail($session, $request->email);

        return response()->json([
            'message'  => $sent ? 'Rapport envoyé avec succès.' : 'Échec de l\'envoi (vérifiez votre configuration SMTP).',
            'sent'     => $sent,
            'session'  => $session->fresh()
        ]);
    }

    public function reportPreview(Request $request, CashSession $session)
    {
        $pdfContent = $this->reportService->generateSessionPdf($session);
        
        return response($pdfContent)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="Rapport-Session-' . $session->id . '.pdf"');
    }

    /** Historique des sessions */
    public function index(Request $request)
    {
        $sessions = CashSession::with('user:id,first_name,last_name')
            ->where('restaurant_id', $request->user()->restaurant_id)
            ->when($request->date, fn($q) => $q->whereDate('opened_at', $request->date))
            ->latest('opened_at')
            ->paginate(20);

        return response()->json($sessions);
    }

    /** Calculer les totaux par méthode pour une session */
    private function computeTotals(CashSession $session): array
    {
        $rows = Payment::where('cash_session_id', $session->id)
            ->selectRaw('method, SUM(amount) as total')
            ->groupBy('method')
            ->pluck('total', 'method')
            ->toArray();

        // Normaliser toutes les méthodes
        $methods = ['cash', 'card', 'wave', 'orange_money', 'momo', 'other'];
        $totals  = [];
        foreach ($methods as $m) {
            $totals[$m] = round($rows[$m] ?? 0, 2);
        }
        $totals['grand_total'] = array_sum($totals);
        return $totals;
    }
}
