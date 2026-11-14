@php
    $title = 'Delivery Challan';
    $docNo = $doc->delivery_no;
    $status = $doc->status;
    $fmt = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d M Y') : '—';
    $meta = [
        'Customer' => $doc->customer?->name ?? '—',
        'Delivery Date' => $fmt($doc->delivery_date),
        'Order Ref' => $doc->order?->order_no ?? '—',
        'Invoice Ref' => $doc->invoice?->invoice_no ?? '—',
        'Challan No' => $doc->challan_no ?? '—',
        'Vehicle No' => $doc->vehicle_no ?? '—',
        'Ship To' => $doc->shipping_address ?? ($doc->customer?->address ?? '—'),
        'Warehouse' => $doc->warehouse?->name ?? '—',
    ];
    $signatures = ['Delivered By', 'Received By', 'Gate Pass'];
@endphp
@extends('core::documents.layout')
@section('body')
    <table class="items">
        <thead>
            <tr>
                <th width="4%">#</th><th>Description</th><th width="15%">SKU</th>
                <th width="10%" class="r">Qty</th><th width="12%" class="r">Delivered</th>
                <th width="13%">Lot</th><th width="13%">Serial</th>
            </tr>
        </thead>
        <tbody>
            @foreach($doc->items as $i => $line)
                <tr>
                    <td class="c">{{ $i + 1 }}</td>
                    <td>{{ $line->product_name }}</td>
                    <td>{{ $line->sku }}</td>
                    <td class="r">{{ rtrim(rtrim(number_format((float) $line->qty, 2), '0'), '.') }}</td>
                    <td class="r">{{ rtrim(rtrim(number_format((float) $line->qty_delivered, 2), '0'), '.') }}</td>
                    <td>{{ $line->lot_no ?? '—' }}</td>
                    <td>{{ $line->serial_no ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    @if($doc->notes)<div class="notes"><strong>Notes:</strong> {{ $doc->notes }}</div>@endif
    <div class="notes">Goods received in good order and condition unless otherwise noted above.</div>
@endsection
