<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<title>Delivery Receipt - {{ $issuance->dr_number }}</title>
<style>
  @page { margin: 0; }
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { padding: 36pt; font-family: Arial, DejaVu Sans, sans-serif; color: #000; font-size: 8.5px; }
  .page { width: {{ $printable_width_pt ?? 769.89 }}pt; }
  .company { text-align: center; border-bottom: 2px solid #000; padding-bottom: 7px; margin-bottom: 9px; }
  .company h1 { font-family: "Times New Roman", DejaVu Serif, serif; font-size: 20px; text-transform: uppercase; }
  .company div { margin-top: 2px; font-size: 8px; }
  .title { display: table; width: 100%; margin-bottom: 8px; }
  .title h2 { display: table-cell; font-family: "Times New Roman", DejaVu Serif, serif; font-size: 15px; text-transform: uppercase; }
  .title .number { display: table-cell; text-align: right; font-size: 10px; }
  .meta { width: 100%; border-collapse: collapse; margin-bottom: 9px; }
  .meta td { padding: 2px 4px; vertical-align: top; }
  .meta .label { width: 70px; font-weight: bold; white-space: nowrap; }
  .items { width: 100%; border-collapse: collapse; }
  .items thead { display: table-header-group; }
  .items th, .items td { border: 1px solid #000; padding: 4px; vertical-align: top; }
  .items th { background: #fff; color: #000; text-align: left; text-transform: uppercase; font-size: 7.5px; }
  .items tr { page-break-inside: avoid; }
  .num { text-align: right; white-space: nowrap; }
  .center { text-align: center; }
  .detail { display: block; margin-top: 1px; font-size: 7.5px; }
  .totals { margin: 9px 0 0 auto; width: 245px; border-collapse: collapse; }
  .totals td { padding: 3px 5px; }
  .grand td { border-top: 1.5px solid #000; font-weight: bold; font-size: 10px; }
  .signatures { width: 100%; border-collapse: collapse; margin-top: 22px; page-break-inside: avoid; }
  .signatures td { width: 50%; padding-right: 26px; }
  .sig-line { border-bottom: 1px solid #000; height: 25px; }
  .sig-caption { font-size: 8px; margin-top: 3px; }
</style>
</head>
<body>
<div class="page">
  <div class="company">
    <h1>{{ $company['name'] ?? 'Airking Air Conditioning' }}</h1>
    @if(!empty($company['address']))<div>{{ $company['address'] }}</div>@endif
    @if(!empty($company['email']) || !empty($company['phone']))
      <div>{{ collect([$company['email'] ?? null, $company['phone'] ?? null])->filter()->implode(' · ') }}</div>
    @endif
  </div>

  <div class="title">
    <h2>Delivery / Issuance Receipt</h2>
    <div class="number">DR No. <strong>{{ $issuance->dr_number }}</strong></div>
  </div>

  <table class="meta">
    <tr>
      <td class="label">CUSTOMER:</td><td><strong>{{ $issuance->customer?->customer_name ?? $issuance->customer?->company_name ?? '—' }}</strong></td>
      <td class="label">DATE:</td><td>{{ $issuance->issuance_date }}</td>
    </tr>
    <tr>
      <td class="label">CONTACT:</td><td>{{ $issuance->customer?->contact_number ?? '—' }}</td>
      <td class="label">WAREHOUSE:</td><td>{{ $issuance->location?->location_name ?? '—' }}</td>
    </tr>
    <tr>
      <td class="label">STATUS:</td><td>{{ $issuance->status?->status_name ?? '—' }}</td>
      <td class="label">ISSUED BY:</td><td>{{ trim(($issuance->issuedByUser?->first_name ?? '').' '.($issuance->issuedByUser?->last_name ?? '')) ?: '—' }}</td>
    </tr>
    <tr>
      <td class="label">SALES REF.:</td>
      <td colspan="3">
        @if($issuance->external_reference)
          <strong>{{ $issuance->external_reference }}</strong>
        @else
          <span style="display:inline-block; width:220px; border-bottom:1px solid #000;">&nbsp;</span>
          <span style="font-size:7px;">(Accounting/POS receipt or invoice no.)</span>
        @endif
      </td>
    </tr>
  </table>

  <table class="items">
    <thead><tr>
      <th style="width:3%" class="center">No.</th>
      <th style="width:22%">Item / Model Code</th>
      <th style="width:15%">Serial Number</th>
      <th style="width:12%">Batch / Zone</th>
      <th style="width:14%">Stock Movement</th>
      <th style="width:6%" class="center">Qty</th>
      <th style="width:10%" class="num">Price / Piece</th>
      <th style="width:10%" class="num">Amount</th>
      <th>Warranty</th>
    </tr></thead>
    <tbody>
    @forelse($items as $item)
      @php
        $product = $item->product;
        $modelCode = $product?->applianceDetail?->model_code ?? $product?->product_code ?? '—';
      @endphp
      <tr>
        <td class="center">{{ $loop->iteration }}</td>
        <td><strong>{{ $product?->product_name ?? '—' }}</strong><span class="detail">{{ $modelCode }}</span></td>
        <td>{{ $item->serial_number ?: '—' }}</td>
        <td>Batch #{{ $item->batch_id ?: '—' }}<span class="detail">Zone: {{ $item->zone_label ?: 'Unassigned' }}</span></td>
        <td>Before: {{ $item->stock_before ?? '—' }}<span class="detail">Deducted: {{ $item->quantity_deducted ?? $item->quantity_in_pieces }}</span><span class="detail">Remaining: {{ $item->stock_after ?? '—' }}</span></td>
        <td class="center">{{ $item->quantity_in_pieces }}</td>
        <td class="num">PHP {{ number_format((float)($item->unit_price_per_piece ?: $item->unit_price), 2) }}</td>
        <td class="num">PHP {{ number_format((float)$item->subtotal, 2) }}</td>
        <td>@if($item->warrantyRecord){{ $item->warrantyRecord->warranty_duration_label }}<span class="detail">{{ $item->warrantyRecord->warranty_start_date?->format('Y-m-d') }} to {{ $item->warrantyRecord->warranty_end_date?->format('Y-m-d') }}</span>@else No Warranty @endif</td>
      </tr>
    @empty
      <tr><td colspan="9" class="center">No issued items.</td></tr>
    @endforelse
    </tbody>
  </table>

  <table class="totals">
    <tr><td>Subtotal</td><td class="num">PHP {{ number_format((float)$issuance->subtotal, 2) }}</td></tr>
    <tr><td>Discount ({{ number_format((float)$issuance->discount_percent, 2) }}%)</td><td class="num">- PHP {{ number_format((float)$issuance->discount_amount, 2) }}</td></tr>
    <tr class="grand"><td>Total</td><td class="num">PHP {{ number_format((float)$issuance->total_amount, 2) }}</td></tr>
  </table>

  <table class="signatures"><tr>
    <td><div class="sig-line"></div><div class="sig-caption">Issued by (Signature over Printed Name)</div></td>
    <td><div class="sig-line"></div><div class="sig-caption">Received by (Signature over Printed Name)</div></td>
  </tr></table>
</div>
</body>
</html>
