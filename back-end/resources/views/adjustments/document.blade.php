<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<title>Inventory Adjustment - {{ $adjustment->adjustment_number }}</title>
<style>
@page{margin:0}*{margin:0;padding:0;box-sizing:border-box}
body{padding:36pt;font-family:Arial,DejaVu Sans,sans-serif;color:#000;font-size:9px}
.page{width:{{ $printable_width_pt ?? 523.28 }}pt}.company{text-align:center;border-bottom:2px solid #000;padding-bottom:7px;margin-bottom:9px}
.company h1{font-family:"Times New Roman",DejaVu Serif,serif;font-size:20px;text-transform:uppercase}.company div{font-size:8px;margin-top:2px}
.title{display:table;width:100%;margin-bottom:8px}.title h2{display:table-cell;font-family:"Times New Roman",DejaVu Serif,serif;font-size:15px;text-transform:uppercase}
.title div{display:table-cell;text-align:right}.meta,.items,.signatures{width:100%;border-collapse:collapse}.meta{margin-bottom:8px}.meta td{padding:2px 4px}
.label{font-weight:bold;width:78px;white-space:nowrap}.items th,.items td{border:1px solid #000;padding:4px;vertical-align:top}.items th{text-align:left;text-transform:uppercase;font-size:8px}
.center{text-align:center}.right{text-align:right}.summary{text-align:right;margin-top:8px;font-weight:bold}.notes{margin-top:9px;border-bottom:1px solid #000;padding-bottom:4px}
.signatures{margin-top:28px;page-break-inside:avoid}.signatures td{width:50%;padding-right:25px}.line{height:24px;border-bottom:1px solid #000}.caption{font-size:8px;margin-top:3px}
</style></head><body><div class="page">
<div class="company"><h1>{{ $company['name'] }}</h1>
@if($company['address'])<div>{{ $company['address'] }}</div>@endif
@if($company['email'] || $company['phone'])<div>{{ collect([$company['email'],$company['phone']])->filter()->implode(' · ') }}</div>@endif</div>
<div class="title"><h2>Inventory Adjustment</h2><div>No. <strong>{{ $adjustment->adjustment_number }}</strong></div></div>
<table class="meta">
<tr><td class="label">LOCATION:</td><td>{{ $adjustment->location?->location_name ?? '—' }}</td><td class="label">DATE:</td><td>{{ $adjustment->adjustment_date?->format('m/d/Y') ?? '—' }}</td></tr>
<tr><td class="label">TYPE:</td><td>{{ $adjustment->adjustment_type ?? '—' }}</td><td class="label">STATUS:</td><td>{{ $adjustment->status?->status_name ?? '—' }}</td></tr>
<tr><td class="label">CREATED BY:</td><td>{{ trim(($adjustment->createdBy?->first_name ?? '').' '.($adjustment->createdBy?->last_name ?? '')) ?: '—' }}</td><td class="label">APPROVED BY:</td><td>{{ trim(($adjustment->approvedBy?->first_name ?? '').' '.($adjustment->approvedBy?->last_name ?? '')) ?: '—' }}</td></tr>
</table>
<table class="items"><thead><tr><th class="center" style="width:7%">No.</th><th>Item / Model Code</th><th class="center" style="width:13%">Added</th><th class="center" style="width:13%">Deducted</th><th class="center" style="width:13%">Net Change</th><th style="width:25%">Reason</th></tr></thead><tbody>
@forelse($adjustment->details as $detail)
@php $p=$detail->product; $code=$p?->applianceDetail?->model_code ?? $p?->product_code ?? '—'; $net=(int)$detail->add_quantity-(int)$detail->deduct_quantity; @endphp
<tr><td class="center">{{ $loop->iteration }}</td><td><strong>{{ $p?->product_name ?? '—' }}</strong><br>{{ $code }}</td><td class="center">{{ (int)$detail->add_quantity }}</td><td class="center">{{ (int)$detail->deduct_quantity }}</td><td class="center">{{ $net > 0 ? '+' : '' }}{{ $net }}</td><td>{{ $detail->reason ?: '—' }}</td></tr>
@empty<tr><td colspan="6" class="center">No adjustment lines.</td></tr>@endforelse
</tbody></table>
<div class="summary">Total added: {{ $adjustment->details->sum('add_quantity') }} pcs &nbsp; | &nbsp; Total deducted: {{ $adjustment->details->sum('deduct_quantity') }} pcs</div>
<div class="notes">Notes: {{ $adjustment->notes ?: '—' }}</div>
<table class="signatures"><tr><td><div class="line"></div><div class="caption">Prepared by (Signature over Printed Name)</div></td><td><div class="line"></div><div class="caption">Approved by (Signature over Printed Name)</div></td></tr></table>
</div></body></html>
