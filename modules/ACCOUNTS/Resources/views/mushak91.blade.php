<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<style>
@page { margin: 14mm 12mm 14mm 12mm; }
* { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; }
h1 { text-align: center; font-size: 14pt; margin: 0; }
.subtitle { text-align: center; font-size: 8pt; color: #333; margin-bottom: 8px; }
.meta-table { width: 100%; border-collapse: collapse; margin: 6px 0 10px; font-size: 8.5pt; }
.meta-table td { padding: 2px 4px; }
table.return { width: 100%; border-collapse: collapse; font-size: 8.5pt; }
table.return th { background: #1e3a5f; color: #fff; padding: 5px; border: 1px solid #333; text-align: left; }
table.return td { padding: 5px; border: 1px solid #999; }
.text-right { text-align: right; }
.section-row td { background: #eef2f7; font-weight: bold; font-size: 8pt; text-transform: uppercase; letter-spacing: 0.4px; }
.total-row td { font-weight: bold; background: #f7f9fc; }
.net-row td { font-weight: bold; font-size: 10pt; background: #e8f0e8; }
.muted { color: #666; }
.footer { margin-top: 14px; font-size: 7pt; color: #666; }
.sign-box { display: inline-block; width: 200px; text-align: center; border-top: 1px solid #333; padding-top: 4px; margin-top: 34px; }
</style>
</head>
<body>
<h1>মূসক ৯.১ — মূল্য সংযোজন কর দাখিলপত্র (Mushak 9.1 — VAT Return)</h1>
<p class="subtitle">[ মূল্য সংযোজন কর ও সম্পূরক শুল্ক আইন ২০১২ ] — ERP Pilot System Generated Working Paper</p>

<table class="meta-table">
<tr>
  <td width="60%"><strong>প্রতিষ্ঠান / Entity:</strong> {{ $ret['company']['name'] }}</td>
  <td class="text-right"><strong>কর মেয়াদ / Period:</strong> {{ $ret['period']['label'] }}</td>
</tr>
<tr>
  <td><strong>BIN:</strong> {{ $ret['company']['bin'] }}</td>
  <td class="text-right"><strong>From {{ $ret['period']['from'] }}</strong> to {{ $ret['period']['to'] }}</td>
</tr>
<tr>
  <td><strong>Address:</strong> {{ $ret['company']['address'] }}</td>
  <td class="text-right"><strong>Generated:</strong> {{ $generatedAt }}</td>
</tr>
</table>

<table class="return">
<thead>
<tr><th width="72%">বিবরণ / Description</th><th width="28%" class="text-right">টাকার পরিমাণ (BDT)</th></tr>
</thead>
<tbody>
<tr class="section-row"><td colspan="2">Output tax on supplies / সরবরাহের উপর উৎপাদ কর</td></tr>
<tr><td>Standard-rated supplies (15%)</td><td class="text-right">{{ number_format($ret['output']['standard']['vat'], 2) }}</td></tr>
<tr><td>Reduced-rate supplies</td><td class="text-right">{{ number_format($ret['output']['reduced']['vat'], 2) }}</td></tr>
<tr><td class="muted">Zero-rated / exempt supplies (value {{ number_format($ret['output']['zero']['base'], 2) }})</td><td class="text-right muted">—</td></tr>
<tr class="total-row"><td>Total output VAT</td><td class="text-right">{{ number_format($ret['output']['total_vat'], 2) }}</td></tr>
<tr><td class="muted">Less: credit notes (sales returns)</td><td class="text-right">({{ number_format($ret['output_adjustment'], 2) }})</td></tr>
<tr class="total-row"><td>Net output VAT</td><td class="text-right">{{ number_format($ret['net_output_vat'], 2) }}</td></tr>

<tr class="section-row"><td colspan="2">Input tax credit / উপকরণ কর রেয়াত</td></tr>
<tr><td>Input VAT on purchases</td><td class="text-right">{{ number_format($ret['input']['total_vat'], 2) }}</td></tr>
<tr><td class="muted">Less: debit notes (purchase returns)</td><td class="text-right">({{ number_format($ret['input_adjustment'], 2) }})</td></tr>
<tr class="total-row"><td>Net input credit</td><td class="text-right">{{ number_format($ret['net_input_vat'], 2) }}</td></tr>
<tr><td>VDS deducted at source (certified Mushak 6.6)</td><td class="text-right">{{ number_format($ret['vds_credit'], 2) }}</td></tr>

<tr class="net-row">
  <td>{{ $ret['net_payable'] > 0 ? 'নিট প্রদেয় কর / Net VAT payable' : 'পরবর্তী মেয়াদে জের / Credit carried forward' }}</td>
  <td class="text-right">{{ number_format($ret['net_payable'] > 0 ? $ret['net_payable'] : $ret['credit_carried_forward'], 2) }}</td>
</tr>
</tbody>
</table>

<p class="footer">
Supplies: {{ count($ret['registers']['sales']) }} &nbsp;|&nbsp; Purchase bills: {{ count($ret['registers']['purchases']) }}.
This working paper is computed from posted invoices, completed POS sales, posted bills and approved returns in the period.
It is a preparation aid for filing on vat.gov.bd and is not itself a lodged return. File within 15 days of period end.
</p>

<table width="100%" style="margin-top:8px;"><tr>
<td><span class="sign-box">প্রস্তুতকারী / Prepared by</span></td>
<td style="text-align:right;"><span class="sign-box">অনুমোদনকারী / Authorised signatory</span></td>
</tr></table>
</body>
</html>
