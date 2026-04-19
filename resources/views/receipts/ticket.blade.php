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
    font-size: 11px;
    color: #000;
    width: auto;
    margin: 0 15px;
    padding: 15px 0 5px 0;
    background: #fff;
    line-height: 1.4;
    overflow: hidden;
    position: relative;
  }
  .receipt-wrap .paid-stamp {
    position: absolute;
    top: 45%; left: 50%;
    transform: translate(-50%, -50%) rotate(-30deg);
    font-size: 36px; font-weight: 900;
    color: rgba(0, 128, 0, 0.25);
    border: 5px solid rgba(0, 128, 0, 0.25);
    padding: 8px 25px;
    text-transform: uppercase;
    letter-spacing: 5px;
    pointer-events: none;
    z-index: 10;
    border-radius: 10px;
  }
  .receipt-wrap .given-change-box {
    background: #f8f8f8;
    border: 1.5px solid #000;
    padding: 5px;
    margin-top: 6px;
  }
  .receipt-wrap .given-change-box td {
    font-weight: bold;
    font-size: 12px;
    padding: 2px 0;
  }
  .receipt-wrap .center  { text-align: center; }
  .receipt-wrap .right   { text-align: right; }
  .receipt-wrap .bold    { font-weight: bold; }
  .receipt-wrap .large   { font-size: 14px; }
  .receipt-wrap .xlarge  { font-size: 16px; }
  .receipt-wrap .muted   { color: #555; }
  .receipt-wrap .divider { border-top: 1px dashed #000; margin: 8px 0; }
  .receipt-wrap .divider-solid { border-top: 1px solid #000; margin: 8px 0; }
  .receipt-wrap table    { width: 100%; border-collapse: collapse; }
  .receipt-wrap td       { padding: 2px 0; vertical-align: top; }
  .receipt-wrap .td-right { text-align: right; white-space: nowrap; padding-left: 6px; }
  .receipt-wrap .logo    { max-width: 100px; max-height: 80px; display: block; margin: 0 auto 6px; }
  .receipt-wrap .total-row td { font-weight: bold; font-size: 14px; border-top: 1.5px solid #000; padding-top: 5px; margin-top: 4px; }
  .receipt-wrap .mod-line { padding-left: 10px; color: #444; font-size: 10px; }
  .receipt-wrap .footer-msg { font-size: 12px; font-weight: bold; margin-top: 10px; }
</style>
@if(!($is_preview ?? false))
</head>
<body>
@endif

<div class="receipt-wrap">
  @if($receipt['order']['paid_at'])
  <div class="paid-stamp">PAYÉ</div>
  @endif
  {{-- EN-TÊTE RESTAURANT --}}
  <div class="center">
    @if(($receipt['footer']['show_logo'] ?? true) && $receipt['restaurant']['logo'])
    <img src="{{ $receipt['restaurant']['logo'] }}" class="logo" alt="" onerror="this.style.display='none'; this.style.opacity='0';">
  @endif
  <div class="bold xlarge">{{ $receipt['restaurant']['name'] }}</div>
  @if($receipt['restaurant']['receipt_subtitle'] ?? null)
    <div class="bold" style="font-size:12px; color:#555; margin-bottom: 2px;">{{ $receipt['restaurant']['receipt_subtitle'] }}</div>
  @endif
  @if($receipt['restaurant']['address'])
    <div class="muted">{{ $receipt['restaurant']['address'] }}</div>
  @endif
  @if($receipt['restaurant']['phone'])
    <div class="muted">Tél : {{ $receipt['restaurant']['phone'] }}</div>
  @endif
  @if($receipt['restaurant']['vat_number'])
    <div class="muted">TVA : {{ $receipt['restaurant']['vat_number'] }}</div>
  @endif
  <div class="bold" style="margin-top:2px;">IFU : 1001580865</div>
</div>

<div class="divider"></div>

{{-- INFOS COMMANDE --}}
<table>
  <tr><td class="bold">TICKET DE CAISSE</td><td class="td-right bold">{{ $receipt['order']['number'] }}</td></tr>
  <tr><td class="muted">Date</td><td class="td-right">{{ $receipt['order']['date'] }} {{ $receipt['order']['time'] }}</td></tr>
  @if($receipt['order']['table_number'])
  <tr><td class="muted">Table</td><td class="td-right">{{ $receipt['order']['table_number'] }}</td></tr>
  @endif
  @if($receipt['order']['covers'])
  <tr><td class="muted">Couverts</td><td class="td-right">{{ $receipt['order']['covers'] }}</td></tr>
  @endif
  <tr><td class="muted">Type</td><td class="td-right">{{ $receipt['order']['type_label'] }}</td></tr>
  @if($receipt['order']['waiter'])
  <tr><td class="muted">Serveur</td><td class="td-right">{{ $receipt['order']['waiter'] }}</td></tr>
  @endif
</table>

<div class="divider"></div>

{{-- LIGNES ARTICLES --}}
<table>
  <thead><tr><td class="bold">Article</td><td class="td-right bold">Qté</td><td class="td-right bold">PU</td><td class="td-right bold">Total</td></tr></thead>
  <tbody>
    @foreach($receipt['lines'] as $line)
    <tr><td class="bold">{{ $line['name'] }}</td><td class="td-right">{{ $line['quantity'] }}</td><td class="td-right">{{ $line['unit_fmt'] }}</td><td class="td-right">{{ $line['total_fmt'] }}</td></tr>
    @foreach($line['modifiers'] as $mod)
    <tr class="mod-line"><td colspan="3">  + {{ $mod['name'] }}</td><td class="td-right muted">{{ $mod['extra_fmt'] }}</td></tr>
    @endforeach
    @if($line['notes'])
    <tr><td colspan="4" class="muted" style="padding-left:8px;font-style:italic">Note: {{ $line['notes'] }}</td></tr>
    @endif
    @endforeach
  </tbody>
</table>

<div class="divider"></div>

{{-- TOTAUX --}}
<table>
  <tr><td class="muted">Sous-total</td><td class="td-right">{{ $receipt['totals']['subtotal_fmt'] }}</td></tr>
  @if($receipt['totals']['discount'] > 0)
  <tr><td class="muted">Remise {{ $receipt['totals']['discount_reason'] ? '('.$receipt['totals']['discount_reason'].')' : '' }}</td><td class="td-right">{{ $receipt['totals']['discount_fmt'] }}</td></tr>
  @endif
  <tr><td class="muted">TVA ({{ $receipt['totals']['vat_rate'] }}%)</td><td class="td-right">{{ $receipt['totals']['vat_fmt'] }}</td></tr>
  <tr class="total-row"><td class="large">TOTAL</td><td class="td-right large">{{ $receipt['totals']['total_fmt'] }}</td></tr>
</table>

<div class="divider"></div>

{{-- PAIEMENTS simplified --}}

@if($receipt['totals']['change'] > 0)
<div class="divider-solid"></div>
<table><tr><td class="bold">Monnaie rendue</td><td class="td-right bold">{{ $receipt['totals']['change_fmt'] }}</td></tr></table>
@endif

@php
  $totalGiven = collect($receipt['payments'])->sum('amount_given');
  $totalChange = collect($receipt['payments'])->sum('change_given');
@endphp
@if($totalGiven > 0)
<div class="given-change-box">
  <table>
    <tr><td>DONNÉ PAR LE CLIENT</td><td class="td-right">{{ number_format($totalGiven, 0, '.', ' ') }} {{ $config['currency_symbol'] ?? 'FCFA' }}</td></tr>
    <tr><td>MONNAIE RENDUE</td><td class="td-right">{{ number_format($totalChange, 0, '.', ' ') }} {{ $config['currency_symbol'] ?? 'FCFA' }}</td></tr>
  </table>
</div>
@endif

<div class="divider"></div>

{{-- PIED DE PAGE --}}
<div class="center" style="margin-top:6px">
  <div class="footer-msg">{{ $receipt['footer']['message'] }}</div>
  @if($receipt['footer']['website'])
    <div class="muted" style="margin-top:3px">{{ $receipt['footer']['website'] }}</div>
  @endif
  <div class="muted" style="margin-top:6px;font-size:10px">Généré le {{ now()->format('d/m/Y à H:i') }}</div>
</div> {{-- .receipt-wrap --}}
@if(!($is_preview ?? false))
</body>
</html>
@endif
