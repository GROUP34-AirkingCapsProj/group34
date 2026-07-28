<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>Goods Received Note – {{ $receiving->receiving_number }}</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: Arial, DejaVu Sans, sans-serif; font-size: 11px; color: #111; background: #fff; }
  @page { margin: 0; }
  body { padding: 36pt; }
  .page { width: {{ $printable_width_pt ?? 523.28 }}pt; margin: 0; padding: 0; }
  .company-header { text-align: center; border-bottom: 2px solid #111; padding-bottom: 10px; margin-bottom: 14px; }
  .company-name { font-size: 22px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.8px; }
  .company-sub  { font-size: 11px; color: #333; margin-top: 2px; }
  .divider-thin { border: none; border-top: 1px solid #bbb; margin: 8px 0; }
  .title-row    { display: table; width: 100%; margin: 10px 0 6px; }
  .title-left   { display: table-cell; font-size: 13px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.8px; }
  .title-right  { display: table-cell; text-align: right; font-size: 11px; }
  .title-right strong { font-size: 13px; }
  .meta-table   { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
  .meta-table td { padding: 2px 0; font-size: 11px; vertical-align: top; }
  .meta-label   { font-weight: bold; width: 90px; }
  .meta-value   { padding-left: 6px; }
  .items-table  { width: 100%; border-collapse: collapse; margin: 10px 0; }
  .items-table th { background: #1e293b; color: #fff; padding: 6px 8px; text-align: left;
    font-size: 10px; letter-spacing: 0.4px; text-transform: uppercase; border: 1px solid #1e293b; }
  .items-table th.center { text-align: center; }
  .items-table td { padding: 5px 8px; border: 1px solid #d1d5db; font-size: 11px; vertical-align: top; }
  .items-table td.center { text-align: center; }
  .items-table tr:nth-child(even) td { background: #f8fafc; }
  .sig-table    { width: 100%; border-collapse: collapse; margin-top: 20px; }
  .sig-table td { padding: 4px 0; font-size: 11px; vertical-align: top; width: 50%; }
  .sig-label    { font-weight: bold; color: #374151; }
  .sig-line     { border-bottom: 1px solid #374151; margin-top: 32px; margin-bottom: 4px; width: 80%; }
  .sig-caption  { font-size: 10px; color: #64748b; }
</style>
</head>
<body>
<div class="page">
  @php $loc = $receiving->location; @endphp
  <div class="company-header">
    <div class="company-name">{{ $company['name'] ?? 'Airking Air Conditioning' }}</div>
    @if(!empty($company['address']))<div class="company-sub">{{ $company['address'] }}</div>@endif
    @if(!empty($company['email']) || !empty($company['phone']))
      <div class="company-sub">{{ collect([$company['email'] ?? null, $company['phone'] ?? null])->filter()->implode(' · ') }}</div>
    @endif
  </div>

  <div class="title-row">
    <div class="title-left">Goods Received Note</div>
    <div class="title-right">No.&nbsp;<strong>{{ $receiving->receiving_number }}</strong></div>
  </div>

  <table class="meta-table">
    <tr>
      <td class="meta-label">PO #:</td>
      <td class="meta-value">{{ $receiving->purchaseOrder?->po_number ?? '—' }}</td>
      <td style="width:20px"></td>
      <td class="meta-label">RECEIVED:</td>
      <td class="meta-value">{{ $receiving->receiving_date ? \Carbon\Carbon::parse($receiving->receiving_date)->format('m/d/Y') : '—' }}</td>
    </tr>
    <tr>
      <td class="meta-label">SUPPLIER:</td>
      <td class="meta-value">{{ $receiving->supplier?->supplier_name ?? $receiving->purchaseOrder?->supplier?->supplier_name ?? '—' }}</td>
      <td></td>
      <td class="meta-label">WAREHOUSE:</td>
      <td class="meta-value">{{ $loc?->location_name ?? '—' }}</td>
    </tr>
  </table>

  <hr class="divider-thin">

  <table class="items-table">
    <thead>
      <tr>
        <th>PRODUCT</th>
        <th class="center" style="width:70px">ORDERED</th>
        <th class="center" style="width:70px">RECEIVED</th>
        <th class="center" style="width:80px">CONDITION</th>
      </tr>
    </thead>
    <tbody>
      @php
        $orderedByProduct = collect($receiving->purchaseOrder?->details ?? [])
            ->groupBy('product_id')->map(fn($g) => (int) $g->sum('quantity_ordered'));
      @endphp
      @forelse($receiving->details as $d)
        <tr>
          <td>
            {{ $d->product?->product_name ?? "Product #{$d->product_id}" }}
            @if($d->product?->product_code)
              <br><span style="font-size:9px;color:#94a3b8">SKU: {{ $d->product->product_code }}</span>
            @endif
          </td>
          <td class="center">{{ $orderedByProduct[$d->product_id] ?? '—' }}</td>
          <td class="center">{{ (int) $d->quantity_amount }}</td>
          <td class="center">{{ $d->condition ?? 'Good' }}</td>
        </tr>
      @empty
        <tr><td colspan="4" class="center" style="color:#94a3b8;padding:12px">No line items.</td></tr>
      @endforelse
    </tbody>
  </table>

  <div style="text-align:right;font-size:11px;margin-top:4px">
    <strong>Total received:</strong> {{ (int) $receiving->total_quantity_received }} pcs
    @if((int) $receiving->total_quantity_damaged > 0)
      &nbsp;·&nbsp; <strong>Damaged:</strong> {{ (int) $receiving->total_quantity_damaged }}
    @endif
  </div>

  <hr class="divider-thin">

  <table class="sig-table">
    <tr>
      <td>
        <span class="sig-label">Received by:</span>&nbsp;
        {{ trim(($receiving->receivedBy?->first_name ?? '') . ' ' . ($receiving->receivedBy?->last_name ?? '')) ?: '—' }}
      </td>
      <td>
        <span class="sig-label">Checked by:</span>&nbsp; ____________________
      </td>
    </tr>
  </table>

  <div style="margin-top:32px">
    <div class="sig-line"></div>
    <div class="sig-caption">Receiver's Signature over Printed Name</div>
  </div>
</div>
</body>
</html>
