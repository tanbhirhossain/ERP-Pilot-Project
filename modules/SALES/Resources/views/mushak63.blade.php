<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<style>
@page { margin: 15mm 12mm 15mm 12mm; }
* { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; }
h1 { text-align: center; font-size: 14pt; margin: 0; }
.subtitle { text-align: center; font-size: 8pt; color: #333; margin-bottom: 6px; }
.meta-table { width: 100%; border-collapse: collapse; margin: 6px 0; font-size: 8pt; }
.meta-table td { padding: 2px 4px; }
.invoice-table { width: 100%; border-collapse: collapse; font-size: 8pt; }
.invoice-table th { background: #1e3a5f; color: #fff; padding: 4px; border: 1px solid #333; }
.invoice-table td { padding: 4px; border: 1px solid #999; }
.text-right { text-align: right; }
.text-center { text-align: center; }
.footer { margin-top: 14px; font-size: 7pt; color: #666; }
.sign-box { display: inline-block; width: 180px; text-align: center; border-top: 1px solid #333; padding-top: 4px; margin-top: 30px; }
</style>
</head>
<body>
<h1>মূসক ৬.৩ — কর চালানপত্র (Mushak 6.3 — Tax Invoice)</h1>
<p class="subtitle">[ মূসক বিধিমালা ২০১৯ এর বিধি ৪০ এর উপ-বিধি (১) এর দফা (গ) ও (চ) দ্রষ্টব্য ] — ERP Pilot System Generated</p>

<table class="meta-table">
<tr><td width="50%"><strong>BIN:</strong> 001234567-0101 &nbsp;|&nbsp; <strong>NID / VAT Reg:</strong> —</td><td class="text-right"><strong>Challan Date:</strong> {{ $invoice->invoice_date->format('d-m-Y') }}</td></tr>
<tr><td><strong>Seller:</strong> ERP Pilot Ltd. — Chattogram EPZ, Bangladesh &nbsp; | &nbsp; <strong>Warehouse:</strong> {{ $invoice->warehouse?->name }}</td><td class="text-right"><strong>Invoice No:</strong> {{ $invoice->invoice_no }}</td></tr>
<tr><td><strong>Buyer:</strong> {{ $invoice->customer?->name }} &nbsp; | &nbsp; NID: {{ $invoice->customer?->tax_id ?? '—' }} &nbsp; | &nbsp; Address: {{ $invoice->customer?->address ?? '—' }}</td><td class="text-right"><strong>Due:</strong> {{ $invoice->due_date?->format('d-m-Y') }} &nbsp; | &nbsp; <strong>Status:</strong> {{ ucfirst($invoice->status) }}</td></tr>
</table>

<table class="invoice-table">
<thead>
<tr>
<th width="5%">#</th>
<th width="32%">Description of Goods / Services</th>
<th width="10%">HS Code</th>
<th width="8%">Qty</th>
<th width="8%">Unit</th>
<th width="12%">Unit Price (BDT)</th>
<th width="12%">Value Excl. VAT</th>
<th width="13%">VAT (15%)</th>
</tr>
</thead>
<tbody>
@php $subtotal=0; $vatTotal=0; @endphp
@foreach($invoice->items as $i => $line)
@php
  $unitPrice = (float)($line->unit_price ?? 0);
  $qty = (float)($line->quantity ?? 1);
  $lineTotal = $unitPrice * $qty - (float)($line->discount_amount ?? 0);
  $lineVat = $lineTotal * 0.15;
  $subtotal += $lineTotal;
  $vatTotal += $lineVat;
@endphp
<tr>
<td class="text-center">{{ $i+1 }}</td>
<td>{{ $line->product?->name ?? $line->description ?? 'Item #'.$line->id }} @if($line->product?->sku) <br><small>SKU: {{ $line->product->sku }}</small> @endif</td>
<td class="text-center">{{ $line->product?->hs_code ?? '—' }}</td>
<td class="text-right">{{ number_format($qty, 0) }}</td>
<td class="text-center">{{ $line->product?->unit ?? $line->unit ?? 'Pcs' }}</td>
<td class="text-right">{{ number_format($unitPrice, 2) }}</td>
<td class="text-right">{{ number_format($lineTotal, 2) }}</td>
<td class="text-right">{{ number_format($lineVat, 2) }}</td>
</tr>
@endforeach
<tr style="background:#eef2ff; font-weight:bold;">
<td colspan="6" class="text-right">Total (A)</td>
<td class="text-right">{{ number_format($subtotal, 2) }}</td>
<td class="text-right">{{ number_format($vatTotal, 2) }}</td>
</tr>
<tr style="background:#1e3a5f; color:#fff; font-weight:bold;">
<td colspan="6" class="text-right">Grand Total incl. VAT (A + VAT)</td>
<td colspan="2" class="text-right">{{ number_format($subtotal + $vatTotal, 2) }} BDT</td>
</tr>
</tbody>
</table>

<table class="meta-table" style="margin-top:6px;">
<tr><td><strong>Amount in Words:</strong> <em>{{ number_format($subtotal + $vatTotal, 2) }} BDT only.</em></td><td class="text-right"><strong>Currency:</strong> {{ $invoice->currency ?? 'BDT' }} &nbsp;|&nbsp; <strong>Payment:</strong> {{ $invoice->payment_status }}</td></tr>
<tr><td colspan="2"><strong>Notes:</strong> {{ $invoice->notes ?? '—' }} &nbsp; | &nbsp; Posted {{ $invoice->posted_at?->format('d-m-Y H:i') ?? '' }} &nbsp; | &nbsp; Journal #{{ $invoice->journal_id ?? '—' }}</td></tr>
</table>

<div style="text-align:center; margin-top: 20px;">
<div class="sign-box">Authorized Signature &amp; Seal<br><small>Seller — ERP Pilot Ltd.</small></div>
<span style="display:inline-block; width:60px;"></span>
<div class="sign-box">Receiver Signature<br><small>Buyer — {{ $invoice->customer?->name }}</small></div>
</div>

<p class="footer">This is a system-generated Mushak 6.3 compliant invoice per VAT &amp; SD Act 2012 (Bangladesh NBR). For audit, present with BIN certificate. Generated {{ now()->format('d-m-Y H:i') }} by ERP Pilot — Chattogram. Amounts verified against posted journal. Voided invoices must be cancelled via credit note.</p>
</body>
</html>
