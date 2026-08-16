<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<title>{{ $title }}</title>
<style>
@page{margin:0}*{margin:0;padding:0;box-sizing:border-box}
body{padding:32pt;font-family:Arial,DejaVu Sans,sans-serif;color:#000;font-size:9px}
.company{text-align:center;border-bottom:2px solid #000;padding-bottom:7px;margin-bottom:9px}
.company h1{font-family:"Times New Roman",DejaVu Serif,serif;font-size:20px;text-transform:uppercase}
.company div{font-size:8px;margin-top:2px}
.title{margin-bottom:8px}
.title h2{font-family:"Times New Roman",DejaVu Serif,serif;font-size:15px;text-transform:uppercase}
.meta{width:100%;border-collapse:collapse;margin-bottom:10px}
.meta td{padding:2px 4px;vertical-align:top}
.meta .label{font-weight:bold;width:110px;white-space:nowrap;text-transform:uppercase;font-size:8px}
.section-title{font-weight:bold;text-transform:uppercase;font-size:9px;margin:12px 0 4px}
table.data{width:100%;border-collapse:collapse;page-break-inside:auto}
table.data th,table.data td{border:1px solid #000;padding:3px 4px;vertical-align:top}
table.data th{text-align:left;text-transform:uppercase;font-size:8px;background:#eee}
table.data tr{page-break-inside:avoid}
.summary{margin-top:10px;border-top:1px solid #000;padding-top:5px}
.summary td{padding:1px 4px;font-size:9px}
.summary .label{font-weight:bold}
.notes{margin-top:10px;font-size:8px;font-style:italic}
.footer{margin-top:16px;font-size:7px;text-align:center;color:#444}
</style></head><body>

<div class="company"><h1>{{ $company['name'] }}</h1>
@if($company['address'])<div>{{ $company['address'] }}</div>@endif
@if($company['email'] || $company['phone'])<div>{{ collect([$company['email'],$company['phone']])->filter()->implode(' · ') }}</div>@endif</div>

<div class="title"><h2>{{ $title }}</h2></div>

<table class="meta">
@foreach($meta as $label => $value)
<tr><td class="label">{{ $label }}:</td><td>{{ $value }}</td></tr>
@endforeach
<tr><td class="label">Generated:</td><td>{{ $generated_at }}</td></tr>
</table>

@forelse($sections as $section)
<div class="section-title">{{ $section['title'] }}</div>
<table class="data"><thead><tr>
@foreach($section['columns'] as $label)<th>{{ $label }}</th>@endforeach
</tr></thead><tbody>
@forelse($section['rows'] as $row)
<tr>@foreach(array_keys($section['columns']) as $key)<td>{{ $row[$key] ?? '—' }}</td>@endforeach</tr>
@empty
<tr><td colspan="{{ count($section['columns']) }}">No data.</td></tr>
@endforelse
</tbody></table>
@empty
<table class="data"><thead><tr>
@foreach($columns as $label)<th>{{ $label }}</th>@endforeach
</tr></thead><tbody>
@forelse($rows as $row)
<tr>@foreach(array_keys($columns) as $key)<td>{{ $row[$key] ?? '—' }}</td>@endforeach</tr>
@empty
<tr><td colspan="{{ count($columns) }}">No data.</td></tr>
@endforelse
</tbody></table>
@endforelse

@if(!empty($summary))
<table class="summary">
@foreach($summary as $label => $value)
<tr><td class="label">{{ $label }}:</td><td>{{ $value }}</td></tr>
@endforeach
</table>
@endif

@foreach($notes as $note)<div class="notes">{{ $note }}</div>@endforeach

<div class="footer">{{ $company['name'] }} · {{ $title }} · generated {{ $generated_at }}</div>
</body></html>
