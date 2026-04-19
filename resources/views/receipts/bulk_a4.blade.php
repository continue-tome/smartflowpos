<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        @page { margin: 0; size: A4 portrait; }
        body { font-family: 'Helvetica', Arial, sans-serif; font-size: 10px; color: #333; margin: 0; padding: 0; }
        .page { width: 210mm; height: 297mm; position: relative; page-break-after: always; overflow: hidden; }
        .receipt-container { height: 148.5mm; width: 100%; padding: 10mm 15mm; box-sizing: border-box; position: relative; border-bottom: 1px dashed #ccc; overflow: hidden; }
        .receipt-container:last-child { border-bottom: none; }
        
        .header { display: table; width: 100%; margin-bottom: 5px; }
        .resto-info { display: table-cell; width: 60%; vertical-align: top; }
        .resto-info h1 { font-size: 18px; margin: 0 0 2px 0; color: #1a1a2e; text-transform: uppercase; }
        .invoice-info { display: table-cell; width: 40%; text-align: right; vertical-align: top; }
        .invoice-info h2 { font-size: 16px; margin: 0 0 4px 0; color: #1a1a2e; }
        
        .badge { background: #1a1a2e; color: white; padding: 2px 8px; border-radius: 10px; font-weight: bold; font-size: 10px; display: inline-block; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 5px; }
        th { background: #f0f0f5; color: #1a1a2e; padding: 5px; text-align: left; font-size: 9px; border-bottom: 2px solid #1a1a2e; }
        td { padding: 4px 5px; border-bottom: 1px solid #eee; }
        
        .totals-wrapper { width: 100%; margin-top: 5px; }
        .totals-table { width: 40%; float: right; }
        .totals-table td { border: none; padding: 2px 5px; }
        .total-row { font-weight: bold; font-size: 12px; border-top: 2px solid #1a1a2e !important; }
        
        .footer { position: absolute; bottom: 5mm; left: 15mm; right: 15mm; text-align: center; font-size: 8px; color: #999; border-top: 1px solid #eee; padding-top: 2px; }
        .paid-stamp { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(-25deg); font-size: 40px; font-weight: 900; color: rgba(0,128,0,0.1); border: 5px solid rgba(0,128,0,0.1); padding: 10px 30px; border-radius: 10px; pointer-events: none; z-index: -1; }
        
        .clear { clear: both; }
    </style>
</head>
<body>
    @foreach($orders->chunk(2) as $pair)
        <div class="page">
            @foreach($pair as $order)
                @php
                    $restaurant = $order->restaurant;
                    $config = $restaurant->settings ?? [];
                    $vatRate = $config['default_vat_rate'] ?? 18;
                    $locationLabel = $order->table ? "Table {$order->table->number}" : ucfirst(str_replace('_', ' ', $order->type));
                @endphp
                <div class="receipt-container">
                    @if($order->paid_at)
                        <div class="paid-stamp">PAYÉ</div>
                    @endif
                    
                    <div class="header">
                        <div class="resto-info">
                            <h1>{{ $restaurant->name }}</h1>
                            @if(data_get($config, 'receipt_subtitle'))
                                <div style="font-weight: bold; font-size: 10px;">{{ data_get($config, 'receipt_subtitle') }}</div>
                            @endif
                            <div>{{ $restaurant->address }}</div>
                            <div>Tél: {{ $restaurant->phone }}</div>
                        </div>
                        <div class="invoice-info">
                            <h2>FACTURE</h2>
                            <div style="margin-bottom: 5px;"><span class="badge">{{ $locationLabel }}</span></div>
                            <div>N° INV-{{ $order->order_number }}</div>
                            <div>Date: {{ $order->created_at->format('d/m/Y H:i') }}</div>
                        </div>
                    </div>

                    <table>
                        <thead>
                            <tr>
                                <th style="width: 5%">#</th>
                                <th style="width: 55%">Article / Description</th>
                                <th style="width: 10%; text-align: center;">Qté</th>
                                <th style="width: 15%; text-align: right;">P.U.</th>
                                <th style="width: 15%; text-align: right;">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($order->items->whereNotIn('status', ['cancelled']) as $index => $item)
                                <tr>
                                    <td>{{ $index + 1 }}</td>
                                    <td>{{ $item->product->name }}</td>
                                    <td style="text-align: center;">{{ $item->quantity }}</td>
                                    <td style="text-align: right;">{{ number_format($item->unit_price, 0, ',', ' ') }}</td>
                                    <td style="text-align: right;">{{ number_format($item->quantity * $item->unit_price, 0, ',', ' ') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <div class="totals-wrapper">
                        <table class="totals-table">
                            <tr>
                                <td>Sous-total</td>
                                <td style="text-align: right;">{{ number_format($order->subtotal, 0, ',', ' ') }} FCFA</td>
                            </tr>
                            @if($order->discount_amount > 0)
                                <tr>
                                    <td>Remise ({{ $order->discount_reason }})</td>
                                    <td style="text-align: right;">-{{ number_format($order->discount_amount, 0, ',', ' ') }} FCFA</td>
                                </tr>
                            @endif
                            <tr>
                                <td>TVA ({{ $vatRate }}%)</td>
                                <td style="text-align: right;">{{ number_format($order->vat_amount, 0, ',', ' ') }} FCFA</td>
                            </tr>
                            <tr class="total-row">
                                <td>TOTAL</td>
                                <td style="text-align: right;">{{ number_format($order->total, 0, ',', ' ') }} FCFA</td>
                            </tr>
                        </table>
                        <div class="clear"></div>
                    </div>

                    <div style="margin-top: 5px; font-size: 8px;">
                        <strong>Paiement:</strong>
                        @foreach($order->payments as $p)
                            {{ strtoupper($p->method) }}: {{ number_format($p->amount, 0, ',', ' ') }} FCFA;
                        @endforeach
                    </div>

                    <div class="footer">
                        {{ data_get($config, 'thank_you_message') ?? 'Merci pour votre confiance' }} — {{ $restaurant->name }} — Document certifié par Omega POS
                    </div>
                </div>
            @endforeach
        </div>
    @endforeach
</body>
</html>
