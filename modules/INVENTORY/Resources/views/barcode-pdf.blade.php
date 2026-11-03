<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
@page { margin: 8mm; }
body { font-family: DejaVu Sans, sans-serif; margin:0; }
.grid { width: 100%; border-collapse: collapse; }
.grid td { border: 1px dashed #ccc; padding: 4px 6px; text-align: center; vertical-align: middle; height: 86px; }
.label-name { font-size: 7pt; font-weight: bold; white-space: nowrap; overflow:hidden; text-overflow: ellipsis; max-width: 180px; margin:0 auto; }
.label-sku { font-size: 6pt; color: #666; }
.label-price { font-size: 7pt; font-weight: bold; }
.barcode img { max-width: 160px; max-height: 38px; }
.thermal td { border: none; height: 110px; }
</style>
</head>
<body>
<table class="grid {{ $paper==='thermal' ? 'thermal' : '' }}">
@php $i=0; @endphp
@foreach($labels->chunk($cols) as $row)
<tr>
  @foreach($row as $it)
    @php $p=$it['product']; $code=$p->barcode ?: $p->sku; @endphp
    <td>
      <div class="label-name">{{ Str::limit($p->name, 28) }}</div>
      <div class="barcode">
        <img src="data:image/png;base64,{{ app('DNS1D')->getBarcodePNG($code, 'C128', 1.4, 30) }}" alt="barcode">
      </div>
      <div class="label-sku">{{ $code }}</div>
      <div class="label-price">{{ number_format((float)$p->selling_price,2) }} BDT</div>
    </td>
  @endforeach
  @for($k=0;$k<$cols - $row->count();$k++) <td></td> @endfor
</tr>
@endforeach
</table>
<div style="font-size:6pt; color:#999; margin-top:6px; text-align:center;">Generated {{ now()->format('d-m-Y H:i') }} | ERP Pilot | milon/barcode C128 | {{ $labels->count() }} labels</div>
</body>
</html>
