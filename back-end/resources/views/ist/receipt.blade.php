<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>IST Receipt – {{ $ist->transfer_number }}</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: Arial, DejaVu Sans, sans-serif; font-size: 11px; color: #111; background: #fff; }
  .page { width: 100%; max-width: 700px; margin: 0 auto; padding: 28px 32px; }

  /* Header */
  .company-name { font-size: 15px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; }
  .company-sub  { font-size: 10px; color: #444; margin-top: 2px; }
  .divider      { border: none; border-top: 1.5px solid #111; margin: 10px 0; }
  .divider-thin { border: none; border-top: 1px solid #bbb; margin: 8px 0; }

  /* Title row */
  .title-row    { display: table; width: 100%; margin: 10px 0 6px; }
  .title-left   { display: table-cell; font-size: 13px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.8px; }
  .title-right  { display: table-cell; text-align: right; font-size: 11px; }
  .title-right strong { font-size: 13px; }

  /* From/To row */
  .meta-table   { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
  .meta-table td { padding: 2px 0; font-size: 11px; vertical-align: top; }
  .meta-label   { font-weight: bold; width: 60px; }
  .meta-value   { padding-left: 6px; }
  .meta-right   { text-align: right; }

  /* Products table */
  .items-table  { width: 100%; border-collapse: collapse; margin: 10px 0; }
  .items-table th {
    background: #1e293b; color: #fff;
    padding: 6px 8px; text-align: left;
    font-size: 10px; letter-spacing: 0.4px; text-transform: uppercase;
    border: 1px solid #1e293b;
  }
  .items-table th.center { text-align: center; }
  .items-table td {
    padding: 5px 8px; border: 1px solid #d1d5db;
    font-size: 11px; vertical-align: top;
  }
  .items-table td.center { text-align: center; }
  .items-table tr:nth-child(even) td { background: #f8fafc; }

  /* Serial tags */
  .serial-list  { font-size: 10px; color: #374151; }

  /* Signature section */
  .sig-table    { width: 100%; border-collapse: collapse; margin-top: 20px; }
  .sig-table td { padding: 4px 0; font-size: 11px; vertical-align: top; width: 50%; }
  .sig-label    { font-weight: bold; color: #374151; }
  .sig-line     { border-bottom: 1px solid #374151; margin-top: 32px; margin-bottom: 4px; width: 80%; }
  .sig-caption  { font-size: 10px; color: #64748b; }

  /* Watermark for not-yet-received */
  .status-badge { display: inline-block; padding: 3px 10px; border-radius: 12px;
    font-size: 10px; font-weight: bold; letter-spacing: 0.5px;
    background: #dcfce7; color: #16a34a; border: 1px solid #86efac; }
</style>
</head>
<body>
<div class="page">

  {{-- Company Header --}}
  <div class="company-name">LGM Global Industries, Inc.</div>
  <div class="company-sub">Bluz Bldg. Tadeco Road San Francisco Panabo City</div>
  <div class="company-sub">Vat Reg. TIN#: 771-347-425-000</div>

  <hr class="divider">

  {{-- Title + IST Number --}}
  <div class="title-row">
    <div class="title-left">Interbranch Stock Transfer</div>
    <div class="title-right">No.&nbsp;<strong>{{ $ist->transfer_number }}</strong></div>
  </div>

  {{-- From / To / Date --}}
  <table class="meta-table">
    <tr>
      <td class="meta-label">FROM:</td>
      <td class="meta-value">{{ $ist->fromLocation?->location_name ?? '—' }}</td>
      <td style="width:20px"></td>
      <td class="meta-label">DATE:</td>
      <td class="meta-value">{{ $ist->transfer_date ? \Carbon\Carbon::parse($ist->transfer_date)->format('m/d/Y') : '—' }}</td>
    </tr>
    <tr>
      <td class="meta-label">TO:</td>
      <td class="meta-value">{{ $ist->toLocation?->location_name ?? '—' }}</td>
      <td></td>
      <td class="meta-label">MR #:</td>
      <td class="meta-value">{{ $ist->transfer_number }}</td>
    </tr>
  </table>

  <hr class="divider-thin">

  {{-- Products Table --}}
  <table class="items-table">
    <thead>
      <tr>
        <th class="center" style="width:80px">QUANTITY</th>
        <th>MODEL / PRODUCT</th>
        <th>SERIAL NO.</th>
      </tr>
    </thead>
    <tbody>
      @forelse($ist->details as $detail)
        @php
          $product  = $detail->product;
          $name     = $product?->product_name ?? "Product #{$detail->product_id}";
          $model    = $product?->model_name ?? ($product?->model?->model_name ?? null);
          $label    = $model ? "{$name} — {$model}" : $name;
          $qty      = $detail->quantity_received > 0 ? $detail->quantity_received : $detail->quantity_transferred;
          $serials  = $detail->item_serials ?? collect();
        @endphp
        <tr>
          <td class="center">{{ $qty }}</td>
          <td>{{ $label }}</td>
          <td class="serial-list">
            @if($serials->isEmpty())
              —
            @else
              {{ $serials->implode(', ') }}
            @endif
          </td>
        </tr>
      @empty
        <tr><td colspan="3" class="center" style="color:#94a3b8;padding:12px">No line items.</td></tr>
      @endforelse
    </tbody>
  </table>

  <hr class="divider-thin">

  {{-- Signatures --}}
  <table class="sig-table">
    <tr>
      <td>
        <span class="sig-label">Prepared by:</span>&nbsp;
        {{ trim(($ist->preparedBy?->first_name ?? '') . ' ' . ($ist->preparedBy?->last_name ?? '')) ?: '—' }}
      </td>
      <td>
        <span class="sig-label">Approved by:</span>&nbsp;
        {{ trim(($ist->approvedBy?->first_name ?? '') . ' ' . ($ist->approvedBy?->last_name ?? '')) ?: '—' }}
      </td>
    </tr>
    <tr>
      <td>
        <span class="sig-label">Received by:</span>&nbsp;
        {{ trim(($ist->receivedBy?->first_name ?? '') . ' ' . ($ist->receivedBy?->last_name ?? '')) ?: '—' }}
      </td>
      <td></td>
    </tr>
  </table>

  <div style="margin-top:32px">
    <div class="sig-line"></div>
    <div class="sig-caption">Receiver's Signature over Printed Name</div>
  </div>

</div>
</body>
</html>
