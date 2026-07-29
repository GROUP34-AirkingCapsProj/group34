<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>Goods Received Note – {{ $po->po_number }}</title>
<style>
  /*
   * Keep the PDF page itself marginless and place the required margin on the
   * document box. This avoids Dompdf builds that ignore @page margins.
   * 36pt is exactly 0.5 inch.
   */
  @page { margin: 0; }
  * { margin: 0; padding: 0; box-sizing: border-box; }
  html, body { width: 100%; }
  body {
    padding: 36pt;
    font-family: "Times New Roman", DejaVu Serif, serif;
    font-size: 9.5px;
    line-height: 1.25;
    color: #000;
    background: #fff;
  }
  /*
   * A4 is 595pt wide. Subtracting the two 36pt margins leaves a 523pt
   * printable area. An explicit width prevents Dompdf from treating 100% as
   * the full paper width and overflowing through the right margin.
   */
  .page { width: {{ $printable_width_pt ?? 523.28 }}pt; margin: 0; padding: 0; }
  .company-header { text-align: center; border-bottom: 2px solid #000; padding: 2px 0 7px; margin-bottom: 7px; }
  .company-name { font-family: "Times New Roman", DejaVu Serif, serif; font-size: 20px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.7px; }
  .company-sub  { font-size: 8px !important; color: #000 !important; margin-top: 2px; }
  .divider-thin { border: none; border-top: 1px solid #bbb; margin: 8px 0; }
  .title-row    { display: table; width: 100%; margin: 5px 0 6px; }
  .title-left   { display: table-cell; font-family: "Times New Roman", DejaVu Serif, serif; font-size: 16px; font-weight: bold; letter-spacing: 0.2px; }
  .title-right  { display: table-cell; width: 44%; padding-right: 2px; text-align: right; font-size: 9px; white-space: nowrap; }
  .title-right strong { font-size: 12px; }
  .meta-table   { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
  .meta-table td { padding: 2px 0; font-size: 9px; vertical-align: top; }
  .meta-label   { font-weight: bold; width: 72px; white-space: nowrap; }
  .meta-value   { padding-left: 5px; }
  .document-box { border: 1px solid #000; padding: 5px 7px; margin: 8px 0 10px; line-height: 1.35; }
  .document-box strong { text-transform: uppercase; }
  .details-label { font-size: 11px; font-weight: bold; margin: 9px 0 3px; }
  .wh-block     { margin-top: 8px; }
  .wh-head      { border: 1px solid #000; border-bottom: 0; background: #fff; color: #000; padding: 5px 7px; font-size: 9px; font-weight: bold;
                  text-transform: uppercase; letter-spacing: .2px; }
  .wh-sub       { font-weight: normal; color: #000; text-transform: none; letter-spacing: 0; float: right; font-size: 8px; }
  .items-table  { width: 100%; border-collapse: collapse; }
  .items-table thead { display: table-header-group; }
  .items-table th { background: #fff; color: #000; padding: 4px; text-align: left;
    font-size: 8px; letter-spacing: 0.1px; text-transform: uppercase; border-top: 1.5px solid #000; border-bottom: 1.5px solid #000; }
  .items-table th.center { text-align: center; }
  .items-table td { padding: 4px; border-bottom: .5px solid #aaa; font-size: 8.5px; line-height: 1.2; vertical-align: top; }
  .items-table td.center { text-align: center; }
  .sku { color: #000; font-size: 8px; white-space: nowrap; }
  .item-code { color: #000; font-size: 8px; font-weight: normal; white-space: nowrap; }
  .detail { display: block; color: #000; font-size: 8px; margin-top: 1px; }
  .serials { font-family: DejaVu Sans Mono, monospace; color: #000; font-size: 7.5px; line-height: 1.3; word-wrap: break-word; }
  .items-table tr { page-break-inside: avoid; }
  .wh-foot      { padding: 4px 7px; border-bottom: 1px solid #000; font-size: 8px; line-height: 1.25; color: #000; background: #fff; }
  .grand        { text-align: right; margin-top: 8px; padding: 5px; border-top: 1.5px solid #000; border-bottom: 1.5px solid #000; font-size: 10px; }
  .sig-table    { width: 100%; border-collapse: collapse; margin-top: 14px; page-break-inside: avoid; }
  .sig-table td { padding: 1px 0; font-size: 9px; vertical-align: top; width: 50%; }
  .sig-label    { font-weight: bold; color: #000; }
  .sig-line     { border-bottom: 1px solid #000; margin-top: 25px; margin-bottom: 3px; width: 78%; }
  .sig-caption  { font-size: 8px; color: #000; }
</style>
</head>
<body>
<div class="page">
  <div class="company-header">
    <div class="company-name">{{ $company['name'] ?? 'Airking Air Conditioning' }}</div>
    @if(!empty($company['address']))<div class="company-sub">{{ $company['address'] }}</div>@endif
    @if(!empty($company['email']) || !empty($company['phone']))
      <div class="company-sub">{{ collect([$company['email'] ?? null, $company['phone'] ?? null])->filter()->implode(' · ') }}</div>
    @endif
  </div>

  <div class="title-row">
    <div class="title-left">Goods Received Note</div>
    <div class="title-right">
      GRN&nbsp;<strong>{{ implode(', ', $receiving_numbers) }}</strong><br>
      PO&nbsp;{{ $po->po_number }}
    </div>
  </div>

  <table class="meta-table">
    <tr>
      <td class="meta-label">SUPPLIER:</td>
      <td class="meta-value"><strong>{{ $po->supplier?->supplier_name ?? '—' }}</strong>@if($po->supplier?->address) · {{ $po->supplier->address }}@endif</td>
      <td style="width:20px"></td>
      <td class="meta-label">ORDER DATE:</td>
      <td class="meta-value">{{ $po->order_date ? \Carbon\Carbon::parse($po->order_date)->format('m/d/Y') : '—' }}</td>
    </tr>
    <tr>
      <td class="meta-label">RECEIVING NO.:</td>
      <td class="meta-value">{{ implode(', ', $receiving_numbers) }}</td>
      <td></td>
      <td class="meta-label">RECEIVED DATE:</td>
      <td class="meta-value">{{ $received_date }}</td>
    </tr>
  </table>

  <div class="document-box">
    <strong>Purchase Order Receiving Summary</strong><br>
    Order date: {{ $po->order_date ? \Carbon\Carbon::parse($po->order_date)->format('m/d/Y') : '—' }}
    &nbsp; | &nbsp; Warehouses received: {{ count($warehouses) }}
  </div>

  @php $rowNumber = 1; @endphp
  <div class="details-label">Received Item Details</div>
  @forelse($warehouses as $wh)
    <div class="wh-block">
      <div class="wh-head">
        {{ $wh['name'] }}
        <span class="wh-sub">{{ count($wh['lines']) }} item(s)</span>
      </div>
      <table class="items-table">
        <thead>
          <tr>
            <th class="center" style="width:5%">NO.</th>
            <th>PRODUCT / CODE</th>
            <th class="center" style="width:10%">ORDERED</th>
            <th class="center" style="width:12%">RECEIVED</th>
            <th style="text-align:right;width:11%">UNIT COST</th>
            <th style="text-align:right;width:12%">AMOUNT</th>
            <th style="width:24%">SERIAL / SCANNED ID</th>
          </tr>
        </thead>
        <tbody>
          @foreach($wh['lines'] as $line)
            @php
              $scanRows = count($line['scans'])
                  ? $line['scans']
                  : [['serial' => null, 'received' => $line['received'], 'condition' => count($line['conditions']) ? implode(', ', $line['conditions']) : 'Good']];
              $spec = implode(' · ', array_filter([$line['brand'], $line['capacity'], $line['variant']]));
            @endphp
            @foreach($scanRows as $scan)
              @php
                $rowReceived = (int) $scan['received'];
                $packageQty = $line['pieces_per_package'] > 0 ? $rowReceived / $line['pieces_per_package'] : 0;
                $packageQtyText = floor($packageQty) == $packageQty
                    ? number_format($packageQty, 0)
                    : number_format($packageQty, 2);
                $rowCost = $rowReceived * (float) $line['unit_cost'];
              @endphp
              <tr>
                <td class="center">{{ $rowNumber++ }}</td>
                <td>
                  <strong>{{ $line['name'] }}</strong>
                  @if($line['code']) <span class="item-code"> · {{ $line['code'] }}</span>@endif
                  @if($spec)<span class="detail">{{ $spec }}</span>@endif
                </td>
                <td class="center">
                  @if($loop->first)
                    {{ $line['ordered'] ?? '—' }}
                    <span class="detail">{{ $line['type'] === 'appliance' ? 'unit' : ($line['package_unit'] ?: 'pkg') }}</span>
                  @else
                    <span class="detail">—</span>
                  @endif
                </td>
                <td class="center">
                  {{ $rowReceived }} {{ $line['type'] === 'appliance' ? ($rowReceived === 1 ? 'unit' : 'units') : 'pcs' }}
                  @if($line['type'] !== 'appliance' && $line['pieces_per_package'] > 1)
                    <span class="detail">{{ $packageQtyText }} {{ $line['package_unit'] ?: 'pkg' }}</span>
                  @endif
                </td>
                <td style="text-align:right">&#8369;{{ number_format((float) $line['unit_cost'], 2) }}</td>
                <td style="text-align:right">&#8369;{{ number_format($rowCost, 2) }}</td>
                <td class="serials">{{ $scan['serial'] ?: '—' }}</td>
              </tr>
            @endforeach
          @endforeach
        </tbody>
      </table>
      <div class="wh-foot">
        GRN:
        @foreach($wh['sessions'] as $s){{ $s['number'] }}{{ $s['date'] ? ' ('.$s['date'].')' : '' }}@if(!$loop->last), @endif @endforeach
        @if(count($wh['receivers'])) &nbsp;·&nbsp; Received by: {{ implode(', ', $wh['receivers']) }} @endif
      </div>
    </div>
  @empty
    <p style="color:#94a3b8;padding:12px 0">No goods received yet.</p>
  @endforelse

  <div class="grand">
    <strong>Total received:</strong> {{ $total_received }} pcs
    &nbsp;&nbsp; <strong>Total received cost:</strong> &#8369;{{ number_format($total_received_cost, 2) }}
  </div>

  <table class="sig-table">
    <tr>
      <td>
        <div class="sig-line"></div>
        <div class="sig-caption">Received by (Signature over Printed Name)</div>
      </td>
      <td>
        <div class="sig-line"></div>
        <div class="sig-caption">Checked by (Signature over Printed Name)</div>
      </td>
    </tr>
  </table>
</div>
</body>
</html>
