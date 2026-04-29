@if(!($is_preview ?? false))
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
@endif
@if(!($is_preview ?? false))
<style>
  @page { margin: 0px; }
  body { 
    margin: 0; 
    padding: 0; 
    background: #fff; 
    width: 100%;
  }
</style>
@endif
<style>
  * { box-sizing: border-box; -webkit-box-sizing: border-box; margin: 0; padding: 0; }
  .receipt-wrap {
    font-family: 'Courier New', Courier, monospace;
    font-size: 10px;
    color: #000;
    width: auto;
    margin: 0 10px;
    padding: 10px 0 2px 0;
    background: #fff;
    line-height: 1.25;
    overflow: hidden;
    position: relative;
  }
  .receipt-wrap .paid-stamp {
    position: absolute;
    top: 45%; left: 50%;
    transform: translate(-50%, -50%) rotate(-30deg);
    font-size: 32px; font-weight: 900;
    color: #000;
    border: 4px solid #000;
    padding: 10px 25px;
    text-transform: uppercase;
    z-index: 10;
    opacity: 0.25;
    border-radius: 4px;
    -webkit-print-color-adjust: exact;
  }
  .receipt-wrap .given-change-box {
    background: #fff; /* Fond Blanc */
    border: 1px solid #000;
    padding: 3px;
    margin-top: 4px;
  }
  .receipt-wrap .given-change-box td {
    font-weight: bold;
    font-size: 11px;
    padding: 1px 0;
  }
  .receipt-wrap .center  { text-align: center; }
  .receipt-wrap .right   { text-align: right; }
  .receipt-wrap .bold    { font-weight: bold; }
  .receipt-wrap .large   { font-size: 12px; }
  .receipt-wrap .xlarge  { font-size: 14px; }
  .receipt-wrap .muted   { color: #000; }
  .receipt-wrap .divider { border-top: 1px dashed #000; margin: 5px 0; }
  .receipt-wrap .divider-solid { border-top: 1px solid #000; margin: 5px 0; }
  .receipt-wrap table    { width: 100%; border-collapse: collapse; }
  .receipt-wrap td       { padding: 1px 0; vertical-align: top; color: #000; }
  .receipt-wrap .td-right { text-align: right; white-space: nowrap; padding-left: 4px; }
  .receipt-wrap .logo    { max-width: 80px; max-height: 60px; display: block; margin: 0 auto 4px; filter: grayscale(100%); }
  .receipt-wrap .total-row td { font-weight: bold; font-size: 13px; border-top: 1px solid #000; padding-top: 3px; margin-top: 2px; }
  .receipt-wrap .mod-line { padding-left: 8px; font-size: 9px; font-weight: bold; }
  .receipt-wrap .footer-msg { font-size: 11px; font-weight: bold; margin-top: 5px; }
</style>
@if(!($is_preview ?? false))
</head>
<body>
@endif

<div class="receipt-wrap">
  @if($receipt['order']['paid_at'])
  <div class="paid-stamp">PAYÉ</div>
  @endif
  
  <div class="center">
    @if(($receipt['footer']['show_logo'] ?? true) && $receipt['restaurant']['logo'])
      <img src="{{ $receipt['restaurant']['logo'] }}" class="logo" alt="" onerror="this.style.display='none';" style="filter: grayscale(100%); -webkit-filter: grayscale(100%);">
    @endif
    <div class="bold xlarge">{{ $receipt['restaurant']['name'] }}</div>
    @if($receipt['restaurant']['receipt_subtitle'] ?? null)
      <div class="bold" style="font-size:10px; margin-bottom: 1px;">{{ $receipt['restaurant']['receipt_subtitle'] }}</div>
    @endif
    <div style="font-size: 9px; font-weight: bold;">
      @if($receipt['restaurant']['address']){{ $receipt['restaurant']['address'] }}<br>@endif
      @if($receipt['restaurant']['phone'])Tél : {{ $receipt['restaurant']['phone'] }}<br>@endif
      @if($receipt['restaurant']['vat_number'])TVA : {{ $receipt['restaurant']['vat_number'] }}<br>@endif
      <span class="bold">IFU : 1001580865</span>
    </div>
  </div>

  <div class="divider"></div>

  <table style="font-weight: bold;">
    <tr><td>RECETTE #{{ $receipt['order']['number'] }}</td><td class="td-right">{{ $receipt['order']['date'] }} {{ $receipt['order']['time'] }}</td></tr>
    @if($receipt['order']['table_number'])
      <tr><td>Table: {{ $receipt['order']['table_number'] }}</td><td class="td-right">Couverts: {{ $receipt['order']['covers'] ?: 1 }}</td></tr>
    @endif
    <tr><td>Type: {{ $receipt['order']['type_label'] }}</td><td class="td-right">Serv: {{ $receipt['order']['waiter'] ?: 'N/A' }}</td></tr>
  </table>

  <div class="divider"></div>

  <table>
    <thead><tr style="border-bottom:1px solid #000;"><td class="bold">Art</td><td class="td-right bold">Qt</td><td class="td-right bold">PU</td><td class="td-right bold">Tot</td></tr></thead>
    <tbody>
      @foreach($receipt['lines'] as $line)
      <tr>
        <td class="bold">{{ $line['name'] }}</td>
        <td class="td-right bold">{{ $line['quantity'] }}</td>
        <td class="td-right">{{ number_format($line['unit_price'], 0, '.', ' ') }}</td>
        <td class="td-right bold">{{ number_format($line['total'], 0, '.', ' ') }}</td>
      </tr>
      @foreach($line['modifiers'] as $mod)
        <tr class="mod-line"><td colspan="3">+ {{ $mod['name'] }}</td><td class="td-right">{{ $mod['extra_fmt'] }}</td></tr>
      @endforeach
      @if($line['notes'])
        <tr><td colspan="4" style="padding-left:5px;font-style:italic;font-size:9px;font-weight:bold;">Note: {{ $line['notes'] }}</td></tr>
      @endif
      @endforeach
    </tbody>
  </table>

  <div class="divider"></div>

  <table style="font-size:10px; line-height: 1.2; font-weight: bold;">
    <tr><td>Sous-total</td><td class="td-right">{{ $receipt['totals']['subtotal_fmt'] }}</td></tr>
    @if($receipt['totals']['discount'] > 0)
      <tr><td>Remise ({{ $receipt['totals']['discount_reason'] ?: 'Promo' }})</td><td class="td-right">{{ $receipt['totals']['discount_fmt'] }}</td></tr>
    @endif
    <tr class="total-row"><td class="large">TOTAL NET</td><td class="td-right large">{{ $receipt['totals']['total_fmt'] }}</td></tr>
  </table>

  @if($receipt['totals']['change'] > 0 || collect($receipt['payments'])->sum('amount_given') > 0)
    <div class="given-change-box">
      <table>
        @php $totalGiven = collect($receipt['payments'])->sum('amount_given'); @endphp
        @if($totalGiven > 0)
          <tr><td>DONNÉ:</td><td class="td-right">{{ number_format($totalGiven, 0, '.', ' ') }}</td></tr>
        @endif
        <tr><td>RENDU:</td><td class="td-right">{{ $receipt['totals']['change_fmt'] }}</td></tr>
      </table>
    </div>
  @endif

  <div class="divider"></div>

  <div class="center">
    <div class="footer-msg">{{ $receipt['footer']['message'] }}</div>
    @if($receipt['footer']['website'])<div style="font-size:9px; font-weight: bold;">{{ $receipt['footer']['website'] }}</div>@endif
    <div style="font-size:8px; margin-top:2px; font-weight: bold;">{{ now()->format('d/m/Y H:i') }}</div>
  </div>
</div>

@if(!($is_preview ?? false))
</body>
</html>
@endif
