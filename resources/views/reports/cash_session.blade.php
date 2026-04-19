<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Rapport de Caisse</title>
</head>
<body style="font-family: Arial, sans-serif; background-color: #f9fafb; padding: 20px; color: #111827;">

    <div style="max-width: 700px; margin: 0 auto; background: #ffffff; padding: 30px; border-radius: 8px; border: 1px solid #e5e7eb;">
        <!-- Entête -->
        <div style="text-align: center; margin-bottom: 30px; border-bottom: 2px solid #f3f4f6; padding-bottom: 20px;">
            <h1 style="margin: 0; color: #f97316; font-size: 26px; text-transform: uppercase;">{{ $restaurant->name }}</h1>
            <p style="margin: 5px 0 0; color: #6b7280; font-size: 14px;">Rapport Financier Journalier</p>
            <div style="margin-top: 15px;">
                <span style="background: #f1f5f9; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: bold; color: #64748b;">SESSION #{{ $session->id }}</span>
            </div>
            <p style="margin: 10px 0 0; font-weight: bold; font-size: 18px;">{{ $session->opened_at ? $session->opened_at->format('d/m/Y') : '' }}</p>
            <p style="margin: 5px 0 0; color: #4b5563; font-size: 14px;">Caissier : <strong>{{ strtoupper($session->user->first_name) }}</strong></p>
        </div>

        <!-- Chiffres Clés -->
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 30px;">
            <tr>
                <td style="width: 49%; background: #1e293b; color: white; padding: 15px; text-align: center; border-radius: 8px;">
                    <div style="font-size: 10px; text-transform: uppercase; margin-bottom: 5px; opacity: 0.8;">Recette Totale</div>
                    <div style="font-size: 20px; font-weight: bold;">{{ number_format((float)$totalRevenue, 0, ',', ' ') }} FCFA</div>
                </td>
                <td style="width: 2%;"></td>
                <td style="width: 49%; background: #f1f5f9; padding: 15px; text-align: center; border-radius: 8px;">
                    <div style="font-size: 10px; text-transform: uppercase; margin-bottom: 5px; color: #64748b;">Dépenses</div>
                    <div style="font-size: 20px; font-weight: bold; color: #ef4444;">- {{ number_format((float)$totalExpenses, 0, ',', ' ') }} FCFA</div>
                </td>
            </tr>
        </table>

        <!-- Synthèse de Trésorerie -->
        <h3 style="font-size: 14px; color: #475569; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px; margin-bottom: 15px;">SYNTHÈSE DES FLUX DE TRÉSORERIE</h3>
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 40px; font-size: 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">
            <tr>
                <td style="padding: 12px 15px; border-bottom: 1px solid #e2e8f0;">Argent d'ouverture</td>
                <td style="padding: 12px 15px; border-bottom: 1px solid #e2e8f0; text-align: right; font-weight: bold;">{{ number_format((float)$session->opening_amount, 0, ',', ' ') }} FCFA</td>
            </tr>
            <tr>
                <td style="padding: 12px 15px; border-bottom: 1px solid #e2e8f0;">Ventes en espèces</td>
                <td style="padding: 12px 15px; border-bottom: 1px solid #e2e8f0; text-align: right;">+ {{ number_format((float)($session->expected_amount - $session->opening_amount + $totalExpenses), 0, ',', ' ') }} FCFA</td>
            </tr>
            <tr style="color: #ef4444;">
                <td style="padding: 12px 15px; border-bottom: 1px solid #e2e8f0;">Dépenses déduites</td>
                <td style="padding: 12px 15px; border-bottom: 1px solid #e2e8f0; text-align: right;">- {{ number_format((float)$totalExpenses, 0, ',', ' ') }} FCFA</td>
            </tr>
            <tr style="background: #ffffff;">
                <td style="padding: 15px; font-weight: bold; border-bottom: 2px dashed #cbd5e1;">MONTANT TOTAL ATTENDU</td>
                <td style="padding: 15px; text-align: right; font-weight: bold; color: #0f172a; border-bottom: 2px dashed #cbd5e1;">{{ number_format((float)$session->expected_amount, 0, ',', ' ') }} FCFA</td>
            </tr>
            <tr>
                <td style="padding: 12px 15px; border-bottom: 1px solid #e2e8f0; color: #f97316; font-weight: bold;">▶︎ MONTANT REMIS AU BANQUIER</td>
                <td style="padding: 12px 15px; border-bottom: 1px solid #e2e8f0; text-align: right; color: #f97316; font-weight: bold;">{{ $session->amount_to_bank ? number_format((float)$session->amount_to_bank, 0, ',', ' ') . ' FCFA' : '—' }}</td>
            </tr>
            <tr>
                <td style="padding: 12px 15px;">▶︎ FOND DE CAISSE RESTANT</td>
                <td style="padding: 12px 15px; text-align: right;">{{ $session->remaining_amount ? number_format((float)$session->remaining_amount, 0, ',', ' ') . ' FCFA' : '—' }}</td>
            </tr>
            <tr style="font-size: 18px;">
                <td style="padding: 20px 15px; font-weight: bold; border-top: 2px solid #0f172a;">ÉCART DE CAISSE</td>
                <td style="padding: 20px 15px; font-weight: bold; text-align: right; border-top: 2px solid #0f172a; color: {{ ($session->difference ?? 0) >= 0 ? '#10b981' : '#ef4444' }};">{{ number_format((float)($session->difference ?? 0), 0, ',', ' ') }} FCFA</td>
            </tr>
        </table>

        <!-- Modes de paiements -->
        <h3 style="font-size: 14px; color: #475569; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px; margin-bottom: 15px;">DÉTAIL DES ENCAISSEMENTS</h3>
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 40px; font-size: 13px;">
            <thead>
                <tr style="background: #f8fafc; color: #64748b; text-transform: uppercase; font-size: 11px;">
                    <th style="padding: 12px 15px; text-align: left; border-bottom: 2px solid #e2e8f0;">Mode de Paiement</th>
                    <th style="padding: 12px 15px; text-align: center; border-bottom: 2px solid #e2e8f0;">Nb. Transac</th>
                    <th style="padding: 12px 15px; text-align: right; border-bottom: 2px solid #e2e8f0;">Montant Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($payments as $p)
                <tr>
                    <td style="padding: 10px 15px; border-bottom: 1px solid #f1f5f9;">
                        @if($p->method === 'cash') ESPÈCES
                        @elseif($p->method === 'card') CARTE BANCAIRE
                        @elseif($p->method === 'wave') WAVE
                        @elseif($p->method === 'orange_money') ORANGE MONEY
                        @elseif($p->method === 'momo') MTN MOMO
                        @else {{ strtoupper($p->method) }} @endif
                    </td>
                    <td style="padding: 10px 15px; border-bottom: 1px solid #f1f5f9; text-align: center;">{{ $p->count }}</td>
                    <td style="padding: 10px 15px; border-bottom: 1px solid #f1f5f9; text-align: right; font-weight: bold;">{{ number_format((float)$p->total, 0, ',', ' ') }} FCFA</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <!-- Ventes par Catégorie -->
        @if(count($categoryStats) > 0)
        <h3 style="font-size: 14px; color: #475569; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px; margin-bottom: 15px;">VENTES PAR CATÉGORIE (Cuisine, Bar, Pizza...)</h3>
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 40px; font-size: 13px;">
            <thead>
                <tr style="background: #f8fafc; color: #64748b; text-transform: uppercase; font-size: 11px;">
                    <th style="padding: 12px 15px; text-align: left; border-bottom: 2px solid #e2e8f0;">Catégorie</th>
                    <th style="padding: 12px 15px; text-align: center; border-bottom: 2px solid #e2e8f0;">Articles</th>
                    <th style="padding: 12px 15px; text-align: right; border-bottom: 2px solid #e2e8f0;">Recette</th>
                </tr>
            </thead>
            <tbody>
                @foreach($categoryStats as $stat)
                <tr>
                    <td style="padding: 10px 15px; border-bottom: 1px solid #f1f5f9; font-weight: bold;">{{ strtoupper($stat->category_name) }}</td>
                    <td style="padding: 10px 15px; border-bottom: 1px solid #f1f5f9; text-align: center;">{{ $stat->total_qty }}</td>
                    <td style="padding: 10px 15px; border-bottom: 1px solid #f1f5f9; text-align: right;">{{ number_format((float)$stat->total_amount, 0, ',', ' ') }} FCFA</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif

        <!-- Ventes par Catégorie et Crédits -->
        <h3 style="font-size: 14px; color: #475569; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px; margin-bottom: 15px;">PERFORMANCES DES SECTEURS ET CRÉDITS</h3>
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 40px; font-size: 13px;">
            <thead>
                <tr style="background: #f8fafc; color: #64748b; text-transform: uppercase; font-size: 11px;">
                    <th style="padding: 12px 15px; text-align: left; border-bottom: 2px solid #e2e8f0;">Secteur / Catégorie</th>
                    <th style="padding: 12px 15px; text-align: right; border-bottom: 2px solid #e2e8f0;">Recette Générée</th>
                </tr>
            </thead>
            <tbody>
                @foreach($categoryStats as $stat)
                <tr>
                    <td style="padding: 10px 15px; border-bottom: 1px solid #f1f5f9; font-weight: bold;">VENTES - {{ strtoupper($stat->category_name) }}</td>
                    <td style="padding: 10px 15px; border-bottom: 1px solid #f1f5f9; text-align: right; font-weight: bold; color: #10b981;">{{ number_format((float)$stat->total_amount, 0, ',', ' ') }} FCFA</td>
                </tr>
                @endforeach
                <!-- Total Ardoises du jour -->
                <tr style="background: #fff7ed;">
                    <td style="padding: 10px 15px; border-bottom: 1px solid #ffedd5; font-weight: bold; color: #ea580c;">TOTAL DES ARDOISES (CRÉDITS)</td>
                    <td style="padding: 10px 15px; border-bottom: 1px solid #ffedd5; text-align: right; font-weight: bold; color: #ea580c;">{{ number_format((float)$totalCredits, 0, ',', ' ') }} FCFA</td>
                </tr>
            </tbody>
        </table>

        <!-- Logs -->
        @if(count($logs) > 0)
        <h3 style="font-size: 14px; color: #475569; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px; margin-bottom: 15px;">JOURNAL DES ÉVÉNEMENTS (LOGS)</h3>
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 40px; font-size: 11px;">
            <thead>
                <tr style="background: #f8fafc; color: #64748b; text-transform: uppercase; font-size: 10px;">
                    <th style="padding: 8px 10px; text-align: left; border-bottom: 2px solid #e2e8f0; width: 60px;">Heure</th>
                    <th style="padding: 8px 10px; text-align: left; border-bottom: 2px solid #e2e8f0; width: 120px;">Auteur</th>
                    <th style="padding: 8px 10px; text-align: left; border-bottom: 2px solid #e2e8f0;">Action</th>
                </tr>
            </thead>
            <tbody>
                @foreach($logs as $log)
                <tr>
                    <td style="padding: 6px 10px; border-bottom: 1px solid #f1f5f9; color: #64748b;">{{ $log->created_at->format('H:i') }}</td>
                    <td style="padding: 6px 10px; border-bottom: 1px solid #f1f5f9; font-weight: bold;">{{ $log->user ? strtoupper($log->user->first_name) : 'SYSTEM' }}</td>
                    <td style="padding: 6px 10px; border-bottom: 1px solid #f1f5f9; color: #475569;">{{ $log->description }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif

        <!-- Bouton PDF URL -->
        <div style="text-align: center; margin-top: 40px; padding-top: 30px; border-top: 2px solid #f1f5f9;">
            <a href="{{ $pdfUrl }}" style="display: inline-block; background-color: #0f172a; color: #ffffff; padding: 14px 28px; text-decoration: none; border-radius: 8px; font-weight: bold; font-size: 14px;">📥 Télécharger le Rapport PDF Complet</a>
            <p style="font-size: 11px; color: #94a3b8; margin-top: 20px; text-transform: uppercase; letter-spacing: 1px;">Ce rapport a été généré automatiquement par SmartFlow POS.</p>
        </div>
    </div>

</body>
</html>
