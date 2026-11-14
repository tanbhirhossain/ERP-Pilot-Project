@php
    $title = 'Salary Payslip';
    $docNo = 'SLIP-'.$doc->id;
    $status = $doc->payment_status;
    $bd = is_array($doc->breakdown_json) ? $doc->breakdown_json : (json_decode((string) $doc->breakdown_json, true) ?? []);
    $base = $bd['period_base_components'] ?? $bd['base_components'] ?? [];
    $monthName = \Illuminate\Support\Carbon::createFromDate((int) $doc->pay_year, (int) $doc->pay_month, 1)->format('F Y');
    $meta = [
        'Employee' => $doc->employee_name,
        'Pay Period' => $monthName,
        'Employee ID' => $doc->employee_id,
        'Department' => $doc->department ?? '—',
        'Designation' => $doc->designation ?? '—',
        'Cost Center' => $doc->cost_center ?? '—',
    ];
    $signatures = ['Prepared By', 'HR Review', 'Employee Acknowledgement'];
    $earnings = [
        'Basic Salary' => (float) ($base['basic'] ?? $doc->basic_salary),
        'House Rent' => (float) ($base['house_rent'] ?? 0),
        'Medical Allowance' => (float) ($base['medical'] ?? 0),
        'Other Allowance' => (float) ($base['other_allowance'] ?? 0),
    ];
    foreach (($bd['benefits'] ?? []) as $b) {
        if (isset($b['name'])) { $earnings[(string) $b['name']] = (float) ($b['amount'] ?? 0); }
    }
    $deductions = [];
    foreach (($bd['recurring_deductions'] ?? []) as $d) {
        if (isset($d['name'])) { $deductions[(string) $d['name']] = (float) ($d['amount'] ?? 0); }
    }
    if ((float) data_get($bd, 'tax.amount', 0) > 0) { $deductions['Income Tax (AIT)'] = (float) data_get($bd, 'tax.amount'); }
    if ((float) data_get($bd, 'provident_fund.employee_amount', 0) > 0) { $deductions['Provident Fund'] = (float) data_get($bd, 'provident_fund.employee_amount'); }
    foreach (($bd['loan_installments'] ?? []) as $l) {
        if (isset($l['name'])) { $deductions[(string) $l['name']] = (float) ($l['amount'] ?? 0); }
    }
@endphp
@extends('core::documents.layout')
@section('body')
    <table class="items">
        <thead>
            <tr><th width="50%">Earnings</th><th width="18%" class="r">Amount (BDT)</th><th width="14%"></th><th width="18%" class="r"></th></tr>
        </thead>
        <tbody>
            @foreach($earnings as $name => $amount)
                <tr><td>{{ $name }}</td><td class="r">{{ number_format($amount, 2) }}</td><td></td><td class="r"></td></tr>
            @endforeach
            <tr><td><strong>Gross Salary</strong></td><td class="r"><strong>{{ number_format((float) $doc->gross_salary, 2) }}</strong></td><td></td><td class="r"></td></tr>
        </tbody>
    </table>
    <table class="items" style="margin-top: 8px;">
        <thead>
            <tr><th width="50%">Deductions</th><th width="18%" class="r">Amount (BDT)</th><th width="14%"></th><th width="18%" class="r"></th></tr>
        </thead>
        <tbody>
            @forelse($deductions as $name => $amount)
                <tr><td>{{ $name }}</td><td class="r">{{ number_format($amount, 2) }}</td><td></td><td class="r"></td></tr>
            @empty
                <tr><td>No deductions this period</td><td class="r">0.00</td><td></td><td class="r"></td></tr>
            @endforelse
            <tr><td><strong>Total Deductions</strong></td><td class="r"><strong>{{ number_format((float) $doc->total_deductions, 2) }}</strong></td><td></td><td class="r"></td></tr>
        </tbody>
    </table>
    <table class="totals">
        <tr class="grand"><td>Net Pay (BDT)</td><td class="r">{{ number_format((float) $doc->net_salary, 2) }}</td></tr>
    </table>
    @if(!empty($bd['salary_proration']['factor']) && $bd['salary_proration']['factor'] < 1)
        <div class="notes">Partial-month salary: {{ $bd['salary_proration']['payable_days'] }} of {{ $bd['salary_proration']['days_in_month'] }} calendar days.</div>
    @endif
    <div class="notes">This payslip is confidential and intended solely for the named employee.</div>
@endsection
