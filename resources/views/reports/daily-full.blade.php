<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Rapport d'Analyse Journalière - {{ $data['date'] }}</title>
    <style>
        body { font-family: 'Helvetica', sans-serif; color: #333; font-size: 12px; margin: 0; padding: 0; }
        .header { text-align: center; border-bottom: 2px solid #e11d48; padding-bottom: 10px; margin-bottom: 20px; }
        .header h1 { margin: 0; color: #e11d48; text-transform: uppercase; font-size: 24px; }
        .header p { margin: 5px 0 0; color: #666; font-weight: bold; }
        
        .section { margin-bottom: 25px; }
        .section-title { background: #f4f4f5; padding: 8px 12px; border-left: 4px solid #e11d48; font-weight: bold; text-transform: uppercase; margin-bottom: 10px; font-size: 14px; }
        
        table { w-full; border-collapse: collapse; width: 100%; margin-bottom: 10px; }
        th { background: #fafafa; text-align: left; padding: 8px; border-bottom: 1px solid #eee; text-transform: uppercase; font-size: 10px; color: #71717a; }
        td { padding: 10px 8px; border-bottom: 1px solid #f4f4f5; }
        .text-right { text-align: right; }
        .font-bold { font-weight: bold; }
        
        .grid { width: 100%; margin-bottom: 20px; }
        .card { background: #fff; border: 1px solid #e4e4e7; padding: 15px; border-radius: 8px; }
        .card h3 { margin: 0 0 5px; font-size: 10px; color: #71717a; text-transform: uppercase; }
        .card .value { font-size: 20px; font-weight: 900; color: #18181b; }

        .badge { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 9px; font-weight: bold; text-transform: uppercase; }
        .badge-success { background: #dcfce7; color: #166534; }
        .badge-info { background: #dbeafe; color: #1e40af; }
        
        .footer { position: fixed; bottom: 0; width: 100%; text-align: center; font-size: 10px; color: #999; padding: 10px 0; border-top: 1px solid #eee; }
    </style>
</head>
<body>
    <div class="header">
        <h1>RAPPORT D'ANALYSE</h1>
        <p>{{ $restaurant->name }} - {{ \Carbon\Carbon::parse($data['date'])->format('d/m/Y') }}</p>
    </div>

    <div class="section">
        <div class="section-title">Résumé Financier Global</div>
        <table style="width: 100%; border: none;">
            <tr>
                <td style="width: 25%; border: none;">
                    <div class="card">
                        <h3>Revenu Total</h3>
                        <div class="value">{{ number_format($data['total_revenue'], 0, ',', ' ') }} FCFA</div>
                    </div>
                </td>
                <td style="width: 25%; border: none;">
                    <div class="card">
                        <h3>Commandes</h3>
                        <div class="value">{{ $data['orders_stats']->count }}</div>
                    </div>
                </td>
                <td style="width: 25%; border: none;">
                    <div class="card">
                        <h3>Couverts</h3>
                        <div class="value">{{ $data['orders_stats']->covers ?? 0 }}</div>
                    </div>
                </td>
                <td style="width: 25%; border: none;">
                    <div class="card">
                        <h3>Ticket Moyen</h3>
                        <div class="value">{{ number_format($data['orders_stats']->count > 0 ? $data['orders_stats']->revenue / $data['orders_stats']->count : 0, 0, ',', ' ') }} F</div>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div style="width: 100%; margin-bottom: 20px;">
        <div style="width: 48%; float: left;">
            <div class="section-title">Modes de Paiement</div>
            <table>
                <thead>
                    <tr>
                        <th>Méthode</th>
                        <th class="text-right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($data['payments'] as $p)
                    <tr>
                        <td>{{ ucfirst(str_replace('_', ' ', $p->method)) }} ({{ $p->count }} tx)</td>
                        <td class="text-right font-bold">{{ number_format($p->total, 0, ',', ' ') }} F</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div style="width: 48%; float: right;">
            <div class="section-title">Répartition Sections</div>
            <table>
                <thead>
                    <tr>
                        <th>Section</th>
                        <th class="text-right">CA</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($data['by_destination'] as $d)
                    <tr>
                        <td>{{ ucfirst($d->destination) }}</td>
                        <td class="text-right font-bold">{{ number_format($d->revenue, 0, ',', ' ') }} F</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div style="clear: both;"></div>
    </div>

    @if(count($data['cake_payments']) > 0)
    <div class="section">
        <div class="section-title">Détail des Gâteaux (Encaissements)</div>
        <table>
            <thead>
                <tr>
                    <th>Commande</th>
                    <th>Client</th>
                    <th>Méthode</th>
                    <th class="text-right">Montant Payé</th>
                </tr>
            </thead>
            <tbody>
                @foreach($data['cake_payments'] as $cp)
                <tr>
                    <td>#{{ $cp->cakeOrder->order_number }}</td>
                    <td>{{ $cp->cakeOrder->customer_name }}</td>
                    <td><span class="badge badge-info">{{ $cp->method }}</span></td>
                    <td class="text-right font-bold">{{ number_format($cp->amount, 0, ',', ' ') }} F</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3" class="text-right font-bold" style="background: #fdf2f2;">TOTAL GÂTEAUX</td>
                    <td class="text-right font-bold" style="background: #fdf2f2;">{{ number_format($data['cake_payments']->sum('amount'), 0, ',', ' ') }} F</td>
                </tr>
            </tfoot>
        </table>
    </div>
    @endif

    @if(count($data['expenses']) > 0)
    <div class="section">
        <div class="section-title">Dépenses de Caisse</div>
        <table>
            <thead>
                <tr>
                    <th>Catégorie / Motif</th>
                    <th>Bénéficiaire</th>
                    <th class="text-right">Montant</th>
                </tr>
            </thead>
            <tbody>
                @foreach($data['expenses'] as $ex)
                <tr>
                    <td>
                        <span class="font-bold">{{ $ex->category }}</span><br>
                        <small style="color: #666;">{{ $ex->description }}</small>
                    </td>
                    <td>{{ $ex->beneficiary ?? '-' }}</td>
                    <td class="text-right font-bold" style="color: #dc2626;">-{{ number_format($ex->amount, 0, ',', ' ') }} F</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="2" class="text-right font-bold" style="background: #fdf2f2;">TOTAL DÉPENSES</td>
                    <td class="text-right font-bold" style="background: #fdf2f2; color: #dc2626;">-{{ number_format($data['expenses']->sum('amount'), 0, ',', ' ') }} F</td>
                </tr>
            </tfoot>
        </table>
    </div>
    @endif

    <div class="section">
        <div class="section-title">Top 5 Produits vendus</div>
        <table>
            <thead>
                <tr>
                    <th>Produit</th>
                    <th class="text-right">Quantité</th>
                    <th class="text-right">Chiffre d'Affaires</th>
                </tr>
            </thead>
            <tbody>
                @foreach($data['top_products'] as $tp)
                <tr>
                    <td>{{ $tp->product->name }}</td>
                    <td class="text-right">{{ $tp->qty }}</td>
                    <td class="text-right font-bold">{{ number_format($tp->revenue, 0, ',', ' ') }} F</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="footer">
        Rapport généré automatiquement le {{ now()->format('d/m/Y à H:i') }} par SMARTFLOW POS.<br>
        Restaurant: {{ $restaurant->name }} - {{ $restaurant->address }}
    </div>
</body>
</html>
