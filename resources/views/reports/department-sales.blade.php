<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <style>
        @page { margin: 40px; }
        body { font-family: 'Helvetica', sans-serif; color: #334155; font-size: 11px; line-height: 1.5; }
        .header { border-bottom: 2px solid #f1f5f9; padding-bottom: 20px; margin-bottom: 30px; }
        .restaurant-name { font-size: 20px; font-weight: bold; color: #1e293b; text-transform: uppercase; }
        .report-title { font-size: 14px; color: #f97316; font-weight: bold; margin-top: 5px; }
        .meta { color: #64748b; font-size: 10px; margin-top: 10px; }
        
        .summary-grid { width: 100%; margin-bottom: 30px; border-collapse: collapse; }
        .summary-box { background: #f8fafc; padding: 15px; border-radius: 8px; text-align: center; border: 1px solid #f1f5f9; }
        .summary-label { font-size: 8px; color: #94a3b8; text-transform: uppercase; font-weight: bold; margin-bottom: 5px; }
        .summary-value { font-size: 16px; color: #1e293b; font-weight: bold; }

        .table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        .table th { background: #f8fafc; padding: 10px; text-align: left; font-size: 9px; text-transform: uppercase; color: #64748b; border-bottom: 2px solid #f1f5f9; }
        .table td { padding: 10px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        .product-name { font-weight: bold; color: #1e293b; font-size: 11px; }
        .table-id { color: #94a3b8; font-size: 9px; }
        .amount { font-weight: bold; text-align: right; }
        .qty { text-align: center; font-weight: bold; }
        
        .footer { position: fixed; bottom: 0; width: 100%; text-align: center; color: #94a3b8; font-size: 9px; padding-top: 10px; border-top: 1px solid #f1f5f9; }
        .bg-accent { color: #f97316; }
    </style>
</head>
<body>
    <div class="header">
        <table width="100%">
            <tr>
                <td width="70%">
                    <div class="restaurant-name">{{ $restaurant->name }}</div>
                    <div class="report-title">Journal de Production — <span class="bg-accent">{{ strtoupper($destination) }}</span></div>
                </td>
                <td width="30%" align="right">
                    @if($restaurant->logo)
                        <img src="{{ public_path('storage/' . $restaurant->logo) }}" style="max-height: 50px; margin-bottom: 5px;">
                    @endif
                    <div class="meta">
                        Date: {{ \Carbon\Carbon::parse($data->date)->format('d/m/Y') }}<br>
                        Généré le: {{ now()->format('d/m/Y H:i') }}
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <table class="summary-grid">
        <tr>
            <td width="33%">
                <div class="summary-box">
                    <div class="summary-label">Chiffre d'Affaires</div>
                    <div class="summary-value">{{ number_format($data->summary->total_revenue, 0, ',', ' ') }} FCFA</div>
                </div>
            </td>
            <td width="4%"></td>
            <td width="29%">
                <div class="summary-box">
                    <div class="summary-label">Articles Préparés</div>
                    <div class="summary-value">{{ $data->summary->items_count }}</div>
                </div>
            </td>
            <td width="4%"></td>
            <td width="30%">
                <div class="summary-box">
                    <div class="summary-label">Nombre de Tickets</div>
                    <div class="summary-value">{{ $data->summary->orders_count }}</div>
                </div>
            </td>
        </tr>
    </table>

    <table class="table">
        <thead>
            <tr>
                <th width="15%">Heure</th>
                <th width="45%">Désignation</th>
                <th width="15%">Source</th>
                <th width="10%" style="text-align: center">Qté</th>
                <th width="15%" style="text-align: right">Valeur</th>
            </tr>
        </thead>
        <tbody>
            @foreach($data->items as $item)
            <tr>
                <td>{{ \Carbon\Carbon::parse($item->created_at)->format('H:i') }}</td>
                <td>
                    <div class="product-name">{{ $item->product->name }}</div>
                    <div class="table-id">ID: #{{ $item->id }}</div>
                </td>
                <td>
                    <span style="background: #f1f5f9; padding: 2px 5px; border-radius: 4px; font-size: 8px;">
                        Table {{ $item->order->table_id ?? 'Ext' }}
                    </span>
                </td>
                <td class="qty">{{ $item->quantity }}</td>
                <td class="amount">{{ number_format($item->subtotal, 0, ',', ' ') }} FCFA</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        © {{ date('Y') }} {{ $restaurant->name }} — Document généré par Omega POS
    </div>
</body>
</html>
