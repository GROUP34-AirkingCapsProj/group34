<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>Goods Received Note – {{ $receiving->receiving_number }}</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: "Times New Roman", DejaVu Serif, serif; font-size: 11px; color: #000; background: #fff; }
  @page { margin: 0; }
  body { padding: 36pt; }
  .page { width: {{ $printable_width_pt ?? 523.28 }}pt; margin: 0; padding: 0; }
  .company-header { text-align: center; border-bottom: 2px solid #000; padding-bottom: 8px; margin-bottom: 14px; }
  .company-name { font-size: 21px; font-weight: bold; text-transform: uppercase; letter-spacing: .5px; }
  .company-sub  { font-size: 9px; margin-top: 1px; }
  .divider-thin { border: none; border-top: 1px solid #bbb; margin: 8px 0; }
  .title-row    { display: table; width: 100%; margin: 10px 0 6px; }
  .title-left   { display: table-cell; font-size: 17px; font-weight: bold; }
  .title-right  { display: table-cell; text-align: right; font-size: 11px; }
  .title-right strong { font-size: 13px; }
  .meta-table   { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
  .meta-table td { padding: 2px 0; font-size: 11px; vertical-align: top; }
  .meta-label   { font-weight: bold; width: 90px; }
  .meta-value   { padding-left: 6px; }
  .items-table  { width: 100%; border-collapse: collapse; margin: 10px 0; }
  .items-table thead { display: table-header-group; }
  .items-table th { background: #fff; color: #000; padding: 4px 5px; text-align: left;
    font-size: 9px; letter-spacing: 0.2px; text-transform: uppercase; border-top: 1.5px solid #000; border-bottom: 1.5px solid #000; }
  .items-table th.center { text-align: center; }
  .items-table td { padding: 4px 5px; border-bottom: .5px solid #bbb; font-size: 11px; vertical-align: top; }
  .items-table td.center { text-align: center; }
  .document-box { border: 1px solid #000; padding: 6px 8px; margin: 8px 0 10px; line-height: 1.4; }
  .document-box strong { text-transform: uppercase; }
  .details-label { font-size: 12px; font-weight: bold; margin: 10px 0 3px; }
  .total-row { border-top: 1.5px solid #000; border-bottom: 1.5px solid #000; padding: 5px; }
  .sig-table    { width: 100%; border-collapse: collapse; margin-top: 20px; }
  .sig-table td { padding: 4px 0; font-size: 11px; vertical-align: top; width: 50%; }
  .sig-label    { font-weight: bold; color: #000; }
  .sig-line     { border-bottom: 1px solid #000; margin-top: 32px; margin-bottom: 4px; width: 85%; }
  .sig-caption  { font-size: 9px; color: #000; }
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

  <div class="document-box">
    <strong>Received At (Warehouse): {{ $loc?->location_name ?? '—' }}</strong><br>
    {{ collect([$loc?->address, $loc?->city, $loc?->province, $loc?->region])->filter()->implode(', ') }}
  </div>

  <div class="details-label">Received Item Details</div>
  <table class="items-table">
    <thead>
      <tr>
        <th>PRODUCT / CODE</th>
        <th class="center" style="width:70px">ORDERED</th>
        <th class="center" style="width:70px">RECEIVED</th>
        <th style="text-align:right;width:72px">UNIT COST</th>
        <th style="text-align:right;width:78px">AMOUNT</th>
        <th class="center" style="width:80px">CONDITION</th>
      </tr>
    </thead>
    <tbody>
      @php
        $orderedByProduct = collect($receiving->purchaseOrder?->details ?? [])
            ->groupBy('product_id')->map(fn($g) => (int) $g->sum('quantity_ordered'));
        $poDetailsById = collect($receiving->purchaseOrder?->details ?? [])->keyBy('po_detail_id');
        $poDetailsByProduct = collect($receiving->purchaseOrder?->details ?? [])->groupBy('product_id');
        $receiptCostTotal = 0;
      @endphp
      @forelse($receiving->details as $d)
        @php
          $poLine = $poDetailsById[$d->po_detail_id] ?? ($poDetailsByProduct[$d->product_id] ?? collect())->first();
          $piecesPerOrderedUnit = $d->product?->product_type === 'appliance'
              ? 1
              : max(1, (int) ($d->product?->pieces_per_package ?? 1));
          $baseUnitCost = $poLine
              ? ((float) $poLine->unit_price / $piecesPerOrderedUnit)
              : (float) ($d->product?->cost_price ?? 0);
          $lineCost = $baseUnitCost * (int) $d->quantity_amount;
          $receiptCostTotal += $lineCost;
        @endphp
        <tr>
          <td>
            <strong>{{ $d->product?->product_name ?? "Product #{$d->product_id}" }}</strong>
            @if($d->product?->product_code)
              &middot; {{ $d->product->product_code }}
            @endif
          </td>
          <td class="center">{{ $orderedByProduct[$d->product_id] ?? '—' }}</td>
          <td class="center">{{ (int) $d->quantity_amount }}</td>
          <td style="text-align:right">&#8369;{{ number_format($baseUnitCost, 2) }}</td>
          <td style="text-align:right">&#8369;{{ number_format($lineCost, 2) }}</td>
          <td class="center">{{ $d->condition ?? 'Good' }}</td>
        </tr>
      @empty
        <tr><td colspan="6" class="center" style="color:#94a3b8;padding:12px">No line items.</td></tr>
      @endforelse
    </tbody>
  </table>

  <div class="total-row" style="text-align:right;font-size:11px;margin-top:4px">
    <strong>Total received:</strong> {{ (int) $receiving->total_quantity_received }} pcs
    @if((int) $receiving->total_quantity_damaged > 0)
      &nbsp;·&nbsp; <strong>Damaged:</strong> {{ (int) $receiving->total_quantity_damaged }}
    @endif
    &nbsp;&nbsp; <strong>Received cost:</strong> &#8369;{{ number_format($receiptCostTotal, 2) }}
  </div>

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
