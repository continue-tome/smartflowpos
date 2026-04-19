<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\CashSession;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $query = Expense::where('restaurant_id', $request->user()->restaurant_id)
            ->with('user:id,first_name,last_name', 'cashSession:id,opened_at')
            ->when($request->cash_session_id, fn($q) => $q->where('cash_session_id', $request->cash_session_id))
            ->when($request->date && !$request->cash_session_id, fn($q) => $q->whereDate('created_at', $request->date))
            ->when($request->category, fn($q) => $q->where('category', $request->category))
            ->latest();

        $expenses = $request->cash_session_id ? $query->get() : $query->paginate(30);
        $total = $request->cash_session_id ? $expenses->sum('amount') : Expense::where('restaurant_id', $request->user()->restaurant_id)->sum('amount');

        return response()->json([
            'data'  => $expenses,
            'total' => $total,
        ]);
    }

    /** Créer une dépense */
    public function store(Request $request)
    {
        $request->validate([
            'category'       => 'required|string',
            'description'    => 'nullable|string|max:255',
            'amount'         => 'required|numeric|min:0.01',
            'payment_method' => 'nullable|string',
            'receipt_ref'    => 'nullable|string|max:100',
            'notes'          => 'nullable|string',
            'beneficiary'    => 'nullable|string|max:255',
            'agent_name'     => 'nullable|string|max:255',
        ]);

        // Rattacher à la session de caisse courante
        $session = CashSession::where('restaurant_id', $request->user()->restaurant_id)
            ->whereNull('closed_at')
            ->latest()
            ->first();

        abort_unless(!!$session, 422, "Aucune session de caisse active. Veuillez d'abord ouvrir la caisse.");

        // Créer la dépense
        $expense = Expense::create([
            'restaurant_id'   => $request->user()->restaurant_id,
            'cash_session_id' => $session?->id,
            'user_id'         => $request->user()->id,
            'category'        => $request->category,
            'description'     => $request->description ?: "Dépense {$request->category}",
            'amount'          => $request->amount,
            'payment_method'  => $request->payment_method ?: 'cash',
            'receipt_ref'     => $request->receipt_ref,
            'notes'           => $request->notes,
            'beneficiary'     => $request->beneficiary,
            'agent_name'      => $request->agent_name,
        ]);

        // Mettre à jour le total dépenses de la session
        if ($session) {
            $session->update([
                'total_expenses' => $session->expenses()->sum('amount'),
            ]);
        }

        $expense->logActivity('expense_created', "Dépense: {$expense->description} — {$expense->amount} FCFA ({$expense->category})");

        return response()->json($expense->load('user:id,first_name,last_name'), 201);
    }

    /** Supprimer une dépense (Admin uniquement) */
    public function destroy(Request $request, Expense $expense)
    {
        abort_if($expense->restaurant_id !== $request->user()->restaurant_id, 403);
        abort_unless($request->user()->isManager(), 403, 'Manager requis.');

        $request->validate(['reason' => 'required|string|min:5']);

        $expense->logActivity('expense_deleted', "Dépense supprimée: {$expense->description} — Raison: {$request->reason}");
        $expense->delete();

        // Recalculer le total de la session si rattachée
        if ($expense->cash_session_id) {
            $session = CashSession::find($expense->cash_session_id);
            $session?->update([
                'total_expenses' => $session->expenses()->sum('amount'),
            ]);
        }

        return response()->json(['message' => 'Dépense supprimée.']);
    }

    /** Résumé des dépenses par catégorie */
    public function summary(Request $request)
    {
        $summary = Expense::where('restaurant_id', $request->user()->restaurant_id)
            ->when($request->date, fn($q) => $q->whereDate('created_at', $request->date))
            ->when($request->cash_session_id, fn($q) => $q->where('cash_session_id', $request->cash_session_id))
            ->selectRaw('category, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('category')
            ->get();

        return response()->json($summary);
    }

    /**
     * Reçu de dépense format PDF VÉRITABLE (DomPDF) avec FILIGRANE
     */
    public function receipt(Expense $expense)
    {
        $expense->load(['user', 'restaurant', 'cashSession']);
        $restaurantName = $expense->restaurant->name ?? 'SMARTFLOW POS';

        $html = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta http-equiv='Content-Type' content='text/html; charset=utf-8'/>
            <style>
                @page { margin: 0; }
                body { font-family: Helvetica, sans-serif; background: #fff; color: #1e293b; margin: 0; padding: 0; }
                .container { padding: 40px; position: relative; }
                
                /* FILIGRANE PDF */
                #watermark {
                    position: fixed; top: 35%; left: -30px; transform: rotate(-35deg);
                    font-size: 80px; font-weight: bold; color: #f1f5f9;
                    z-index: -1000; text-transform: uppercase; width: 100%; text-align: center;
                }

                .header { border-bottom: 2px solid #f1f5f9; padding-bottom: 20px; margin-bottom: 30px; }
                .title { font-size: 26px; font-weight: bold; color: #f97316; }
                .ref { font-size: 10px; color: #64748b; margin-top: 5px; text-transform: uppercase; }

                .table-info { width: 100%; border-collapse: collapse; margin-top: 20px; }
                .table-info td { padding: 15px 0; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
                .label { font-size: 9px; font-weight: bold; color: #94a3b8; text-transform: uppercase; margin-bottom: 5px; }
                .value { font-size: 14px; color: #1e293b; font-weight: bold; }

                .amount-container { margin-top: 40px; padding: 30px; background: #0f172a; border-radius: 12px; text-align: center; }
                .amount-label { color: #94a3b8; font-size: 10px; text-transform: uppercase; margin-bottom: 10px; }
                .amount-value { color: #f97316; font-size: 38px; font-weight: bold; }

                .signature-section { margin-top: 60px; width: 100%; }
                .sig-box { width: 45%; border-top: 1px dashed #cbd5e1; padding-top: 10px; text-align: center; }
                .sig-name { font-size: 9px; color: #64748b; font-weight: bold; text-transform: uppercase; }

                .footer { position: fixed; bottom: 40px; width: 100%; text-align: center; font-size: 9px; color: #94a3b8; }
            </style>
        </head>
        <body>
            <div id='watermark'>{$restaurantName}</div>
            
            <div class='container'>
                <div class='header'>
                    <div class='title'>Justificatif de Sortie de Caisse</div>
                    <div class='ref'>Référence : EXP-" . str_pad($expense->id, 6, '0', STR_PAD_LEFT) . " • Émis le " . $expense->created_at->format('d/m/Y à H:i') . "</div>
                </div>

                <table class='table-info'>
                    <tr>
                        <td width='50%'>
                            <div class='label'>Agent ayant perçu les fonds</div>
                            <div class='value'>" . ($expense->agent_name ?: 'Non spécifié') . "</div>
                        </td>
                        <td width='50%'>
                            <div class='label'>Bénéficiaire / Fournisseur</div>
                            <div class='value'>" . ($expense->beneficiary ?: 'Non spécifié') . "</div>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <div class='label'>Motif de la dépense</div>
                            <div class='value'>" . ($expense->description ?: 'Aucun motif') . "</div>
                        </td>
                        <td>
                            <div class='label'>Catégorie comptable</div>
                            <div class='value' style='text-transform: uppercase;'>" . $expense->category . "</div>
                        </td>
                    </tr>
                </table>

                <div class='amount-container'>
                    <div class='amount-label'>Montant Total Décaissé</div>
                    <div class='amount-value'>" . number_format((float) $expense->amount, 0, ',', ' ') . " FCFA</div>
                </div>

                <table class='signature-section' style='margin-top: 80px;'>
                    <tr>
                        <td class='sig-box'>
                            <div class='sig-name'>Signature de l'Agent</div>
                        </td>
                        <td width='10%'></td>
                        <td class='sig-box'>
                            <div class='sig-name'>Validation BOSS / Manager</div>
                        </td>
                    </tr>
                </table>

                <div class='footer'>
                    Ce document fait office de pièce comptable pour " . $restaurantName . ".<br>
                    ID Caisse : #" . ($expense->cash_session_id ?? 'N/A') . " • Établi par : " . ($expense->user->first_name ?? 'Inconnu') . "
                </div>
            </div>
        </body>
        </html>";

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html);
        return $pdf->download("Recu_Depense_EXP_" . $expense->id . ".pdf");
    }
}
