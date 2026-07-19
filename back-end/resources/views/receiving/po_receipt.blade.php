<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>Goods Received Note – {{ $po->po_number }}</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: Arial, DejaVu Sans, sans-serif; font-size: 11px; color: #111; background: #fff; }
  .page { width: 100%; max-width: 720px; margin: 0 auto; padding: 28px 32px; }
  .company-header { text-align: center; border-bottom: 2px solid #111; padding-bottom: 10px; margin-bottom: 14px; }
  .company-name { font-size: 22px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.8px; }
  .company-sub  { font-size: 11px; color: #333; margin-top: 2px; }
  .divider-thin { border: none; border-top: 1px solid #bbb; margin: 8px 0; }
  .title-row    { display: table; width: 100%; margin: 10px 0 6px; }
  .title-left   { display: table-cell; font-size: 13px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.8px; }
  .title-right  { display: table-cell; text-align: right; font-size: 11px; }
  .title-right strong { font-size: 13px; }
  .meta-table   { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
  .meta-table td { padding: 2px 0; font-size: 11px; vertical-align: top; }
  .meta-label   { font-weight: bold; width: 90px; }
  .meta-value   { padding-left: 6px; }
  .wh-block     { margin-top: 14px; border: 1px solid #d1d5db; border-radius: 4px; }
  .wh-head      { background: #1e293b; color: #fff; padding: 6px 10px; font-size: 11px; font-weight: bold;
                  text-transform: uppercase; letter-spacing: .5px; }
  .wh-sub       { font-weight: normal; color: #cbd5e1; text-transform: none; letter-spacing: 0; float: right; font-size: 10px; }
  .items-table  { width: 100%; border-collapse: collapse; }
  .items-table th { background: #f1f5f9; color: #334155; padding: 5px 8px; text-align: left;
    font-size: 10px; letter-spacing: 0.3px; text-transform: uppercase; border-bottom: 1px solid #e2e8f0; }
  .items-table th.center { text-align: center; }
  .items-table td { padding: 5px 8px; border-bottom: 1px solid #eef2f7; font-size: 11px; vertical-align: top; }
  .items-table td.center { text-align: center; }
  .wh-foot      { padding: 5px 10px; font-size: 10px; color: #475569; background: #f8fafc; }
  .grand        { text-align: right; margin-top: 12px; font-size: 12px; }
  .sig-table    { width: 100%; border-collapse: collapse; margin-top: 24px; }
  .sig-table td { padding: 4px 0; font-size: 11px; vertical-align: top; width: 50%; }
  .sig-label    { font-weight: bold; color: #374151; }
  .sig-line     { border-bottom: 1px solid #374151; margin-top: 32px; margin-bottom: 4px; width: 80%; }
  .sig-caption  { font-size: 10px; color: #64748b; }
</style>
</head>
<body>
<div class="page">
  <div class="company-header">
    <div class="company-name">LGM Global Industries, Inc.</div>
    <div class="company-sub" style="font-size:10px;color:#666">Vat Reg. TIN#: 771-347-425-000</div>
  </div>

  <div class="title-row">
    <div class="title-left">Goods Received Note</div>
    <div class="title-right">PO&nbsp;<strong>{{ $po->po_number }}</strong></div>
  </div>

  <table class="meta-table">
    <tr>
      <td class="meta-label">SUPPLIER:</td>
      <td class="meta-value">{{ $po->supplier?->supplier_name ?? '—' }}</td>
      <td style="width:20px"></td>
      <td class="meta-label">ORDER DATE:</td>
      <td class="meta-value">{{ $po->order_date ? \Carbon\Carbon::parse($po->order_date)->format('m/d/Y') : '—' }}</td>
    </tr>
    <tr>
      <td class="meta-label">SESSIONS:</td>
      <td class="meta-value">{{ $session_count }}</td>
      <td></td>
      <td class="meta-label">TOTAL RECEIVED:</td>
      <td class="meta-value">{{ $total_received }} pcs</td>
    </tr>
  </table>

  @forelse($warehouses as $wh)
    <div class="wh-block">
      <div class="wh-head">
        {{ $wh['name'] }}
        <span class="wh-sub">{{ count($wh['lines']) }} item(s)</span>
      </div>
      <table class="items-table">
        <thead>
          <tr>
            <th>PRODUCT</th>
            <th class="center" style="width:70px">ORDERED</th>
            <th class="center" style="width:70px">RECEIVED</th>
          </tr>
        </thead>
        <tbody>
          @foreach($wh['lines'] as $line)
            <tr>
              <td>
                {{ $line['name'] }}
                @if($line['code'])<br><span style="font-size:9px;color:#94a3b8">SKU: {{ $line['code'] }}</span>@endif
              </td>
              <td class="center">{{ $line['ordered'] ?? '—' }}</td>
              <td class="center">{{ $line['received'] }}</td>
            </tr>
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

  <div class="grand"><strong>Total received across all warehouses:</strong> {{ $total_received }} pcs</div>

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
