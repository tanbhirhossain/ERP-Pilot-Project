<?php

use Illuminate\Support\Facades\DB;
use Modules\PAYROLL\Models\PayrollSalarySheet;
use Modules\PAYROLL\Services\PayrollSalarySheetWorkspaceService;

/**
 * The salary-sheet workspace's reads.
 *
 * The point of these tests is not that the figures add up — `SUM()` does that. It is that the
 * workspace reports the *slips* rather than the sheet's cached columns, because the two can disagree
 * the moment anything rewrites a payslip after the run finished. That disagreement is real: this
 * database had 981 slips re-priced and the sheet columns were left describing the old payroll until
 * the recalculate command re-stated them. A workspace that trusted the cache would have shown ৳0
 * income tax on a month that withholds ৳88,722.98.
 */

function wsSheet(int $year, int $month, string $status = 'ready', array $overrides = []): PayrollSalarySheet
{
    return PayrollSalarySheet::query()->create(array_merge([
        'pay_year' => $year, 'pay_month' => $month, 'status' => $status,
        'employee_count' => 0, 'processed_count' => 0,
        'gross_total' => 0, 'deduction_total' => 0, 'net_total' => 0,
        'include_punishments' => false,
    ], $overrides));
}

function wsEmployee(string $id): void
{
    if (DB::table('employees')->where('employee_id', $id)->exists()) {
        return;
    }
    DB::table('employees')->insert([
        'employee_id' => $id, 'first_name' => "Emp {$id}", 'full_name' => "Emp {$id}",
        'employment_type' => 'Permanent', 'status' => 'Active', 'is_active' => 1,
        'allow_overtime' => 1, 'duty_hours' => 8, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function wsSlip(PayrollSalarySheet $sheet, string $employee, float $gross, float $deductions, float $tax = 0.0, array $overrides = []): int
{
    wsEmployee($employee);
    $net = round($gross - $deductions, 2);

    return DB::table('payroll_slips')->insertGetId(array_merge([
        'salary_sheet_id' => $sheet->id, 'employee_id' => $employee, 'employee_name' => "Emp {$employee}",
        'department' => 'Ward', 'designation' => 'Staff',
        'pay_year' => $sheet->pay_year, 'pay_month' => $sheet->pay_month,
        'basic_salary' => round($gross / 2, 2), 'gross_salary' => $gross, 'total_benefits' => 0,
        'total_deductions' => $deductions, 'net_salary' => $net, 'payment_status' => 'unpaid',
        'breakdown_json' => json_encode([
            'tax' => ['monthly_tax' => $tax, 'mode' => $tax > 0 ? 'progressive' : 'unconfigured'],
            'totals' => ['gross' => $gross, 'deductions' => $deductions, 'net' => $net],
        ]),
        'created_at' => now(), 'updated_at' => now(),
    ], $overrides));
}

it('sums its figures from the slips, not from the sheet columns', function (): void {
    $sheet = wsSheet(2026, 10);
    wsSlip($sheet, 'W1', 100000.0, 20000.0, 5000.0);
    wsSlip($sheet, 'W2', 60000.0, 10000.0, 1500.0);
    // The columns are deliberately wrong — this is the state a re-priced month is left in.
    $sheet->update(['gross_total' => 999.0, 'deduction_total' => 999.0, 'net_total' => 999.0]);

    $context = (new PayrollSalarySheetWorkspaceService)->sheetContext($sheet->id);

    expect($context['aggregate']['slips'])->toBe(2)
        ->and($context['aggregate']['gross'])->toBe(160000.0)
        ->and($context['aggregate']['deductions'])->toBe(30000.0)
        ->and($context['aggregate']['net'])->toBe(130000.0)
        ->and($context['aggregate']['tax'])->toBe(6500.0)
        // And the disagreement is reported rather than hidden.
        ->and($context['variance']['net'])->toBe(999.0 - 130000.0);
});

it('reports no variance when the columns still describe the slips', function (): void {
    $sheet = wsSheet(2026, 10, 'ready', ['gross_total' => 160000, 'deduction_total' => 30000, 'net_total' => 130000]);
    wsSlip($sheet, 'W3', 100000.0, 20000.0, 5000.0);
    wsSlip($sheet, 'W4', 60000.0, 10000.0, 1500.0);

    $context = (new PayrollSalarySheetWorkspaceService)->sheetContext($sheet->id);

    expect($context['variance'])->toBe(['gross' => 0.0, 'deductions' => 0.0, 'net' => 0.0]);
});

it('blocks release when a slip pays a negative net', function (): void {
    $sheet = wsSheet(2026, 10, 'ready', ['employee_count' => 1]);
    wsSlip($sheet, 'W5', 10000.0, 12000.0); // deductions exceed pay

    $readiness = (new PayrollSalarySheetWorkspaceService)->readiness($sheet->id);

    expect($readiness['blocking_failures'])->toBeGreaterThan(0)
        ->and($readiness['can_release_data'])->toBeFalse()
        ->and(collect($readiness['checks'])->firstWhere('key', 'no_negative')['ok'])->toBeFalse();
});

it('does not block release over zero-tax slips, only advises', function (): void {
    $sheet = wsSheet(2026, 10, 'ready', ['employee_count' => 2]);
    wsSlip($sheet, 'W6', 20000.0, 2000.0, 0.0);
    wsSlip($sheet, 'W7', 50000.0, 5000.0, 3000.0);

    $readiness = (new PayrollSalarySheetWorkspaceService)->readiness($sheet->id);
    $tax = collect($readiness['checks'])->firstWhere('key', 'tax');

    // Paying people who earned their wages is not something a warning should stop.
    expect($tax['ok'])->toBeFalse()
        ->and($tax['blocking'])->toBeFalse()
        ->and($readiness['blocking_failures'])->toBe(0)
        ->and($readiness['can_release_data'])->toBeTrue();
});

it('reconciles gross minus deductions against net on the checklist', function (): void {
    $sheet = wsSheet(2026, 10, 'ready', ['employee_count' => 1]);
    wsSlip($sheet, 'W8', 40000.0, 9000.0, 2000.0);

    $readiness = (new PayrollSalarySheetWorkspaceService)->readiness($sheet->id);
    $balances = collect($readiness['checks'])->firstWhere('key', 'balances');

    expect($balances['ok'])->toBeTrue();
});

it('groups the month by cost centre with shares that add to the whole', function (): void {
    $sheet = wsSheet(2026, 10);
    wsSlip($sheet, 'W9', 100000.0, 20000.0, 5000.0);
    wsSlip($sheet, 'W10', 50000.0, 10000.0, 1000.0);
    wsSlip($sheet, 'W11', 30000.0, 6000.0, 500.0);
    DB::table('payroll_slips')->where('employee_id', 'W11')->update(['department' => 'Theatre']);

    $departments = (new PayrollSalarySheetWorkspaceService)->departments($sheet->id);

    expect($departments['totals']['departments'])->toBe(2)
        ->and($departments['totals']['slips'])->toBe(3)
        ->and($departments['totals']['net'])->toBe(144000.0)
        // Shares are against the month, not against the row, so they always sum to 100.
        ->and(array_sum(array_column($departments['rows'], 'net_pct')))->toEqualWithDelta(100.0, 0.11)
        ->and(array_sum(array_column($departments['rows'], 'headcount_pct')))->toEqualWithDelta(100.0, 0.11);
});

it('treats a blank department as a cost centre rather than dropping the slip', function (): void {
    $sheet = wsSheet(2026, 10);
    wsSlip($sheet, 'W12', 25000.0, 5000.0, 0.0);
    DB::table('payroll_slips')->where('employee_id', 'W12')->update(['department' => null]);

    $departments = (new PayrollSalarySheetWorkspaceService)->departments($sheet->id);

    // 981 slips minus the ones with a department is headcount that went missing from the total.
    expect($departments['totals']['slips'])->toBe(1)
        ->and($departments['rows'][0]['department'])->toBe('Unassigned');
});

it('builds the month-by-month trend without tripping over its own aggregate row', function (): void {
    // This exists because the trend shipped broken: `overview()` returns arrays, `trend()` reads a
    // query row, and a change that made one of them consistent with the other reached both. None of
    // the other tests call `trend()`, so the page 500'd while a green suite said everything was fine.
    $earlier = wsSheet(2026, 9, 'released');
    wsSlip($earlier, 'W15', 40000.0, 8000.0, 1000.0);
    $later = wsSheet(2026, 10);
    wsSlip($later, 'W16', 50000.0, 10000.0, 2000.0);

    $trend = (new PayrollSalarySheetWorkspaceService)->trend();

    $months = array_column($trend, 'short');
    expect($trend)->toHaveCount(2)
        // Chronological, because a run-rate read right-to-left is a different story.
        ->and($months[0])->toBe('Sep 2026')
        ->and($months[1])->toBe('Oct 2026')
        ->and($trend[1]['net'])->toBe(40000.0)
        ->and($trend[1]['average'])->toBe(40000.0);
});

it('distinguishes computed-but-unreleased money from released money', function (): void {
    $ready = wsSheet(2026, 10, 'ready', ['employee_count' => 1]);
    wsSlip($ready, 'W13', 50000.0, 10000.0, 2000.0);
    $released = wsSheet(2026, 9, 'released', ['employee_count' => 1]);
    wsSlip($released, 'W14', 40000.0, 8000.0, 1000.0);

    $overview = (new PayrollSalarySheetWorkspaceService)->overview();

    expect($overview['totals']['unreleased_net'])->toBe(40000.0)
        ->and($overview['totals']['waiting_slips'])->toBe(1)
        ->and($overview['totals']['net'])->toBe(72000.0)
        ->and($overview['counts']['ready'])->toBe(1)
        ->and($overview['counts']['released'])->toBe(1);
});
