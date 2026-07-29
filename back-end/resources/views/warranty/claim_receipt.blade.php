<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<title>Warranty Claim - {{ $claim->claim_number }}</title>
<style>
  @page { margin: .5in; }
  * { box-sizing: border-box; }
  body { margin: 0; color: #000; font-family: "Times New Roman", DejaVu Serif, serif; font-size: 10px; }
  .company { text-align: center; border-bottom: 2px solid #000; padding-bottom: 8px; margin-bottom: 13px; }
  .company h1 { margin: 0 0 2px; font-size: 20px; text-transform: uppercase; letter-spacing: .5px; }
  .company p { margin: 1px 0; font-size: 8px; }
  .header { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
  .header td { width: 50%; vertical-align: top; line-height: 1.45; }
  .customer strong { display: block; font-size: 11px; text-transform: uppercase; }
  .reference { margin-left: auto; border-collapse: collapse; }
  .reference caption { font-size: 16px; font-weight: bold; text-align: right; margin-bottom: 3px; }
  .reference td { width: auto; padding: 1px 3px; white-space: nowrap; }
  .reference td:first-child { font-weight: bold; text-align: right; }
  .claim-box { border: 1px solid #000; padding: 6px 8px; margin: 8px 0 10px; line-height: 1.45; }
  .claim-box strong { text-transform: uppercase; }
  .label { font-size: 11px; font-weight: bold; margin-bottom: 3px; }
  table.items { width: 100%; border-collapse: collapse; }
  .items thead { display: table-header-group; }
  .items th { padding: 4px; border-top: 1.5px solid #000; border-bottom: 1.5px solid #000; text-align: left; font-size: 8px; }
  .items td { padding: 4px; border-bottom: .5px solid #aaa; vertical-align: top; }
  .center { text-align: center !important; }
  .right { text-align: right !important; }
  .total { padding: 5px; border-top: 1.5px solid #000; border-bottom: 1.5px solid #000; text-align: right; font-size: 11px; }
  .signatures { width: 100%; border-collapse: collapse; margin-top: 25px; page-break-inside: avoid; }
  .signatures td { width: 33.33%; padding-right: 16px; font-weight: bold; }
  .sig-line { border-bottom: 1px solid #000; min-height: 26px; padding-top: 13px; }
</style>
</head>
<body>
@php
  $claimedProductCode = $claim->product?->applianceDetail?->model_code ?? $claim->product?->product_code;
  $person = function ($user) {
      if (!$user) return '—';
      return trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: ($user->username ?? '—');
  };
@endphp
<div class="company">
  <h1>{{ $company['name'] ?: 'Airking Air Conditioning' }}</h1>
  @if($company['address'])<p>{{ $company['address'] }}</p>@endif
  @if($company['email'] || $company['phone'])<p>{{ collect([$company['email'], $company['phone']])->filter()->implode(' · ') }}</p>@endif
</div>

<table class="header"><tr>
  <td class="customer">
    <strong>{{ $claim->customer?->customer_name ?? 'Customer' }}</strong>
    {{ collect([$claim->customer?->company_name, $claim->customer?->contact_number])->filter()->implode(' · ') }}
  </td>
  <td>
    <table class="reference">
      <caption>Warranty Claim Receipt</caption>
      <tr><td>Date:</td><td>{{ ($claim->completed_at ?? $claim->created_at)?->format('m/d/Y') }}</td></tr>
      <tr><td>Claim #:</td><td>{{ $claim->claim_number }}</td></tr>
      <tr><td>Issue-out Ref:</td><td>{{ $claim->warranty?->issuance?->dr_number ?? '—' }}</td></tr>
    </table>
  </td>
</tr></table>

<div class="claim-box">
  <strong>Claimed Product: {{ $claim->product?->product_name ?? '—' }}@if($claimedProductCode) · {{ $claimedProductCode }}@endif</strong><br>
  Serial: {{ $claim->serial_number }} &nbsp; | &nbsp;
  Type: {{ ucfirst($claim->claim_type) }} &nbsp; | &nbsp;
  Status: {{ $claim->status?->status_name ?? '—' }}<br>
  Original coverage: {{ $claim->warranty?->warranty_start_date?->format('m/d/Y') ?? '—' }}
  to {{ $claim->warranty?->warranty_end_date?->format('m/d/Y') ?? '—' }}
  ({{ $claim->warranty?->warranty_duration_label ?? '—' }})<br>
  Processing location: {{ $claim->location?->location_name ?? '—' }}
  @if($claim->notes)<br>Reported problem: {{ $claim->notes }}@endif
</div>

<div class="label">{{ $claim->claim_type === 'replacement' ? 'Replacement Unit' : 'Repair Parts Used' }}</div>
<table class="items">
  <thead><tr>
    <th style="width:5%">#</th>
    <th>PRODUCT / CODE</th>
    <th style="width:22%">SERIAL</th>
    <th class="center" style="width:12%">ISSUED / ORDERED</th>
    <th class="right" style="width:13%">UNIT COST</th>
    <th class="right" style="width:13%">AMOUNT</th>
  </tr></thead>
  <tbody>
  @forelse($claim->details as $i => $detail)
    @php $code = $detail->product?->applianceDetail?->model_code ?? $detail->product?->product_code; @endphp
    <tr>
      <td>{{ $i + 1 }}</td>
      <td><strong>{{ $detail->product?->product_name ?? '—' }}</strong>@if($code) · {{ $code }}@endif</td>
      <td>{{ $detail->serial_number ?? '—' }}</td>
      <td class="center">{{ $detail->quantity_issued }} / {{ $detail->quantity_requested }} {{ $detail->quantity_unit }}</td>
      <td class="right">&#8369;{{ number_format((float)$detail->unit_cost, 2) }}</td>
      <td class="right">&#8369;{{ number_format((float)$detail->line_cost, 2) }}</td>
    </tr>
  @empty
    <tr><td colspan="6" class="center">No claim items.</td></tr>
  @endforelse
  </tbody>
</table>
<div class="total"><strong>Total Claim Cost:</strong> &#8369;{{ number_format((float)$claim->parts_cost, 2) }}</div>

<table class="signatures"><tr>
  <td>Processed By:<div class="sig-line">{{ $person($claim->createdByUser) }}</div></td>
  <td>Customer Acknowledgment:<div class="sig-line"></div></td>
  <td>Approved By:<div class="sig-line"></div></td>
</tr></table>
</body>
</html>
