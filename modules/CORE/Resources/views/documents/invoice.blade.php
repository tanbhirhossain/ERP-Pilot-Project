@php
    $title = 'Tax Invoice';
    $docNo = $doc->invoice_no;
    $status = $doc->status;
    $fmt = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d M Y') : '—';
    $meta = [
        'Customer' => $doc->customer?->name ?? '—',
        'Invoice Date' => $fmt($doc->invoice_date),
        'Address' => $doc->customer?->address ?? '—',
        'Due Date' => $fmt($doc->due_date),
        'Customer BIN' => $doc->customer?->tax_id ?? '—',
        'Warehouse' => $doc->warehouse?->name ?? '—',
    ];
    $signatures = ['Prepared By', 'Received By', 'Authorised Signatory'];
@endphp
@extends('core::documents.layout')
@section('body')
    <table class="items">
        <thead>
            <tr>
                <th width="4%">#</th><th>Description</th><th width="14%">SKU</th>
                <th width="8%" class="r">Qty</th><th width="13%" class="r">Unit Price</th>
                <th width="11%" class="r">VAT</th><th width="14%" class="r">Line Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($doc->items as $i => $line)
                <tr>
                    <td class="c">{{ $i + 1 }}</td>
                    <td>{{ $line->product_name }}</td>
                    <td>{{ $line->sku }}</td>
                    <td class="r">{{ rtrim(rtrim(number_format((float) $line->qty, 2), '0'), '.') }}</td>
                    <td class="r">{{ number_format((float) $line->unit_price, 2) }}</td>
                    <td class="r">{{ number_format((float) $line->vat_amount, 2) }}</td>
                    <td class="r">{{ number_format((float) $line->line_total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <table class="totals">
        <tr><td>Subtotal</td><td class="r">{{ number_format((float) $doc->subtotal, 2) }}</td></tr>
        <tr><td>Discount</td><td class="r">− {{ number_format((float) $doc->discount_amount, 2) }}</td></tr>
        <tr><td>VAT</td><td class="r">{{ number_format((float) $doc->vat_amount, 2) }}</td></tr>
        <tr class="grand"><td>Total (BDT)</td><td class="r">{{ number_format((float) $doc->total_amount, 2) }}</td></tr>
        <tr><td>Paid</td><td class="r">{{ number_format((float) $doc->paid_amount, 2) }}</td></tr>
        <tr><td>Balance Due</td><td class="r">{{ number_format((float) $doc->total_amount - (float) $doc->paid_amount, 2) }}</td></tr>
    </table>
    @if($doc->notes)<div class="notes"><strong>Notes:</strong> {{ $doc->notes }}</div>@endif
@endsection
