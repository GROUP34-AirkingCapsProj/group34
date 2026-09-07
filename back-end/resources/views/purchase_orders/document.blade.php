<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<title>Purchase Order - {{ $po->po_number }}</title>
<style>
  @page { margin: .5in; }
  * { box-sizing: border-box; }
  body { margin: 0; color: #000; font-family: "Times New Roman", DejaVu Serif, serif; font-size: 11px; }
  .page { page-break-after: always; }
  .page:last-child { page-break-after: auto; }
  .company { text-align: center; border-bottom: 2px solid #000; padding-bottom: 8px; margin-bottom: 14px; }
  .company h1 { margin: 0 0 3px; font-size: 21px; text-transform: uppercase; letter-spacing: .5px; }
  .company p { margin: 1px 0; font-size: 9px; }
  .header { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
  .header td { width: 50%; vertical-align: top; line-height: 1.5; }
  .supplier { font-size: 11px; }
  .supplier strong { display: block; font-size: 12px; text-transform: uppercase; }
  .reference { margin-left: auto; border-collapse: collapse; }
  .reference caption { font-size: 17px; font-weight: bold; text-align: right; margin-bottom: 4px; }
  .reference td { width: auto; padding: 1px 3px; white-space: nowrap; }
  .reference td:first-child { font-weight: bold; text-align: right; }
  .deliver { border: 1px solid #000; padding: 6px 8px; margin: 8px 0 10px; line-height: 1.4; }
  .deliver strong { display: block; text-transform: uppercase; }
  .details-label { font-size: 12px; font-weight: bold; margin-bottom: 3px; }
  .items { width: 100%; border-collapse: collapse; }
  .items thead { display: table-header-group; }
  .items th { padding: 4px 5px; border-top: 1.5px solid #000; border-bottom: 1.5px solid #000; text-align: left; font-size: 9px; }
  .items td { padding: 4px 5px; border-bottom: .5px solid #bbb; vertical-align: top; }
  .items .center { text-align: center; }
  .items .right { text-align: right; }
  .items tfoot td { border-top: 1.5px solid #000; border-bottom: 1.5px solid #000; font-weight: bold; }
  .notes { margin: 9px 0 20px; line-height: 1.8; }
  .notes div { border-bottom: 1px solid #bbb; }
  .signatures { width: 100%; border-collapse: collapse; margin-top: 26px; page-break-inside: avoid; }
  .signatures td { width: 33.33%; padding-right: 18px; font-weight: bold; }
  .sig-line { border-bottom: 1px solid #000; min-height: 28px; padding-top: 14px; }
</style>
</head>
<body>
@php
  $groups = $po->details->groupBy(fn($detail) => (string)($detail->location_id ?? $po->location_id ?? 'none'));
  if ($groups->isEmpty()) $groups = collect(['none' => collect()]);
  $person = function ($user) {
      if (!$user) return '—';
      return trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: ($user->username ?? '—');
  };
@endphp
@foreach($groups as $locationId => $details)
@php
  $location = $details->first()?->location ?? $po->location;
  $pageTotal = $details->sum(fn($detail) => (float)$detail->subtotal);
@endphp
<div class="page">
  <div class="company">
    <h1>{{ $company['name'] ?: 'Airking Air Conditioning' }}</h1>
    @if($company['address'])<p>{{ $company['address'] }}</p>@endif
    @if($company['email'])<p>{{ $company['email'] }}</p>@endif
  </div>

  <table class="header"><tr>
    <td class="supplier">
      <strong>{{ $po->supplier?->supplier_name ?? '—' }}</strong>
      {{ $po->supplier?->address ?? '' }}
    </td>
    <td>
      <table class="reference">
        <caption>Purchase Order</caption>
        <tr><td>Date:</td><td>{{ $po->order_date?->format('m/d/Y') ?? '—' }}</td></tr>
        <tr><td>Reference #:</td><td>{{ $po->po_number }}</td></tr>
        <tr><td>Payment Terms:</td><td>{{ $po->payment_terms ?: 'Net 30' }}</td></tr>
      </table>
    </td>
  </tr></table>

  <div class="deliver">
    <strong>Deliver To (Warehouse): {{ $location?->location_name ?? $po->ship_to_name ?? '—' }}</strong>
    {{ collect([$location?->address, $location?->city, $location?->province, $location?->region])->filter()->implode(', ') ?: ($po->ship_to_address ?? '') }}
  </div>

  <div class="details-label">Details</div>
  <table class="items">
    <thead><tr>
      <th class="center" style="width:9%">Quantity</th>
      <th class="center" style="width:9%">Unit</th>
      <th style="width:18%">Model Code</th>
      <th>Description</th>
      <th class="right" style="width:14%">Unit Price</th>
      <th class="right" style="width:14%">Amount</th>
    </tr></thead>
    <tbody>
    @forelse($details as $detail)
      @php
        $product = $detail->product;
        $appliance = $product?->applianceDetail;
        $material = $product?->installationMaterialDetail;
        $code = $appliance?->model_code ?? $product?->product_code ?? '—';
        $unit = $product?->product_type === 'appliance' ? 'unit' : ($material?->package_unit ?? $material?->unit?->unit_abbreviation ?? 'pkg');
        $spec = collect([$product?->product_name, $appliance?->brand?->brand_name, $appliance?->capacity_rating, $appliance?->variant])->filter()->implode(' ');
        if ($product?->product_type !== 'appliance' && $material?->pieces_per_package) {
            $spec .= ' ('.$material->pieces_per_package.' pcs/'.($material->package_unit ?: 'pkg').')';
        }
      @endphp
      <tr>
        <td class="center">{{ $detail->quantity_ordered }}</td>
        <td class="center">{{ $unit }}</td>
        <td>{{ $code }}</td>
        <td>{{ $spec ?: '—' }}</td>
        <td class="right">{{ number_format((float)$detail->unit_price, 2) }}</td>
        <td class="right">{{ number_format((float)$detail->subtotal, 2) }}</td>
      </tr>
    @empty
      <tr><td colspan="6" class="center">No line items.</td></tr>
    @endforelse
    </tbody>
    <tfoot><tr>
      <td colspan="5" class="right">Total Amount →</td>
      <td class="right">₱{{ number_format($pageTotal, 2) }}</td>
    </tr></tfoot>
  </table>

  <div class="notes">
    <div>Notes: {{ $po->notes }}</div>
    <div>References: {{ $po->references_note }}</div>
  </div>

  <table class="signatures"><tr>
    <td>Prepared By:<div class="sig-line">{{ $person($po->createdBy) }}</div></td>
    <td>Verified By:<div class="sig-line">{{ $person($po->verifiedBy) }}</div></td>
    <td>Approved By:<div class="sig-line">{{ $person($po->approvedBy) }}</div></td>
  </tr></table>
</div>
@endforeach
</body>
</html>
