<!doctype html>
<html><head><meta charset="utf-8"><title>DR {{ $issuance->dr_number }}</title>
<style>
body{font-family:DejaVu Sans,sans-serif;color:#111827;font-size:10px}h1{text-align:center;margin:0;font-size:20px}.sub{text-align:center;color:#4b5563;margin:4px 0 16px}.info{width:100%;margin-bottom:14px}.info td{width:50%;padding:3px 8px}.items{width:100%;border-collapse:collapse}.items th{background:#e5e7eb;text-align:left;padding:6px 4px;border:1px solid #9ca3af}.items td{padding:5px 4px;border:1px solid #d1d5db;vertical-align:top}.num{text-align:right;white-space:nowrap}.muted{color:#6b7280}.totals{margin:14px 0 0 auto;width:310px;border-collapse:collapse}.totals td{padding:4px 6px}.grand td{border-top:2px solid #111827;font-weight:bold;font-size:12px}
</style></head><body>
<h1>DELIVERY / ISSUANCE RECEIPT</h1><div class="sub">DR {{ $issuance->dr_number }}</div>
<table class="info">
<tr><td><b>Date:</b> {{ $issuance->issuance_date }}</td><td><b>Issued by:</b> {{ trim(($issuance->issuedByUser?->first_name ?? '').' '.($issuance->issuedByUser?->last_name ?? '')) ?: '—' }}</td></tr>
<tr><td><b>Customer:</b> {{ $issuance->customer?->customer_name ?? '—' }}</td><td><b>Warehouse:</b> {{ $issuance->location?->location_name ?? '—' }}</td></tr>
<tr><td><b>Contact:</b> {{ $issuance->customer?->contact_number ?? '—' }}</td><td><b>Status:</b> {{ $issuance->status?->status_name ?? '—' }}</td></tr>
</table>
<table class="items"><thead><tr>
<th>Product / Serial</th><th>FIFO Batch / Zone</th><th>Stock Movement</th>
<th>Qty</th><th>Price / Piece</th><th>Amount</th><th>Warranty</th>
</tr></thead><tbody>
@foreach($items as $item)
<tr>
<td><b>{{ $item->product?->product_name }}</b><br><span class="muted">{{ $item->product?->product_code }}</span><br>Serial: {{ $item->serial_number ?: 'Not serialized' }}</td>
<td>Batch #{{ $item->batch_id ?: '—' }}<br>Zone: {{ $item->zone_label ?: 'Unassigned' }}</td>
<td>Before: {{ $item->stock_before ?? '—' }}<br>Deducted: {{ $item->quantity_deducted ?? $item->quantity_in_pieces }}<br>Remaining: {{ $item->stock_after ?? '—' }}</td>
<td class="num">{{ $item->quantity_in_pieces }}</td>
<td class="num">PHP {{ number_format((float)($item->unit_price_per_piece ?: $item->unit_price),2) }}</td>
<td class="num">PHP {{ number_format((float)$item->subtotal,2) }}</td>
<td>@if($item->warrantyRecord){{ $item->warrantyRecord->warranty_duration_label }}<br>{{ $item->warrantyRecord->warranty_start_date?->format('Y-m-d') }} to {{ $item->warrantyRecord->warranty_end_date?->format('Y-m-d') }}@else No Warranty @endif</td>
</tr>
@endforeach
</tbody></table>
<table class="totals">
<tr><td>Subtotal</td><td class="num">PHP {{ number_format((float)$issuance->subtotal,2) }}</td></tr>
<tr><td>Discount ({{ number_format((float)$issuance->discount_percent,2) }}%)</td><td class="num">- PHP {{ number_format((float)$issuance->discount_amount,2) }}</td></tr>
<tr class="grand"><td>Total</td><td class="num">PHP {{ number_format((float)$issuance->total_amount,2) }}</td></tr>
</table>
</body></html>
