@php
    $title = 'Payment Receipt';
    $docNo = $doc->payment_no;
    $status = $doc->status;
    $fmt = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d M Y') : '—';
    $meta = [
        'Received From' => $doc->customer?->name ?? '—',
        'Payment Date' => $fmt($doc->payment_date),
        'Invoice Ref' => $doc->invoice?->invoice_no ?? '—',
        'Method' => ucfirst((string) $doc->method),
        'Reference' => $doc->reference_no ?? '—',
        'Invoice Total' => $doc->invoice ? number_format((float) $doc->invoice->total_amount, 2) : '—',
    ];
    $signatures = ['Received By', 'Authorised Signatory'];
@endphp
@extends('core::documents.layout')
@section('body')
    <table class="items">
        <thead>
            <tr><th>Description</th><th width="22%" class="r">Amount (BDT)</th></tr>
        </thead>
        <tbody>
            <tr>
                <td>Payment received against {{ $doc->invoice?->invoice_no ?? 'account' }}{{ $doc->notes ? ' — '.$doc->notes : '' }}</td>
                <td class="r">{{ number_format((float) $doc->amount, 2) }}</td>
            </tr>
        </tbody>
    </table>
    <table class="totals">
        <tr class="grand"><td>Received (BDT)</td><td class="r">{{ number_format((float) $doc->amount, 2) }}</td></tr>
        @if($doc->invoice)
            <tr><td>Invoice Balance After</td><td class="r">{{ number_format((float) $doc->invoice->total_amount - (float) $doc->invoice->paid_amount, 2) }}</td></tr>
        @endif
    </table>
    <div class="notes">Thank you for your payment. This receipt confirms the amount above was received on the stated date.</div>
@endsection
