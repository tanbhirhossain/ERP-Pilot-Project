<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\PAYROLL\Models\PayrollSalarySheet;

/**
 * Re-pricing tax on payslips that were already calculated.
 *
 * The hard part is not the arithmetic — that is `CalculateEmployeeTaxAction`, pinned separately. The
 * hard part is that this rewrites three numbers on a document somebody may already have: total
 * deductions, net salary, and the tax block inside an "immutable" snapshot. So the tests are about
 * what it refuses to touch as much as what it changes.
 */
function rtSheet(int $year, int $month): PayrollSalarySheet
{
    return PayrollSalarySheet::query()->create([
        'pay_year' => $year, 'pay_month' => $month, 'status' => 'ready',
        'employee_count' => 0, 'processed_count' => 0,
        'gross_total' => 0, 'deduction_total' => 0, 'net_total' => 0,
    ]);
}

function rtJournal(): int
{
    // Both `payroll_slips.payment_journal_id` and `payroll_salary_sheets.accrual_journal_id` are
    // real foreign keys into the ledger, so a "paid" slip needs a journal that actually exists.
    return DB::table('acc_journals')->insertGetId([
        'journal_no' => 'TEST-'.uniqid(), 'journal_date' => '2026-10-31',
        'narration' => 'test', 'total_debit' => 0, 'total_credit' => 0,
        'status' => 'posted', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function rtPolicy(): void
{
    $config = DB::table('tax_configurations')->insertGetId([
        'name' => 'Test', 'mode' => 'progressive', 'fixed_monthly_tax' => 0,
        'annual_exemption' => 350000, 'taxable_salary_percent' => 100,
        'is_active' => 1, 'effective_from' => '2026-01-01', 'effective_to' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ([
        [0, 350000, 0], [350000, 450000, 5], [450000, 750000, 10],
        [750000, 1150000, 15], [1150000, 1650000, 20], [1650000, null, 25],
    ] as $order => [$lower, $upper, $rate]) {
        DB::table('tax_slabs')->insert([
            'tax_configuration_id' => $config, 'lower_limit' => $lower, 'upper_limit' => $upper,
            'rate_percent' => $rate, 'sort_order' => $order,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

function rtSlip(PayrollSalarySheet $sheet, string $employeeId, float $gross, float $pf, float $punishment, float $tax, array $overrides = []): int
{
    $deductions = round($pf + $punishment + $tax, 2);
    $breakdown = [
        'version' => 1, 'currency' => 'BDT',
        'provident_fund' => ['employee_contribution' => $pf, 'employer_contribution' => $pf],
        'loan_installments' => [],
        'recurring_deductions' => [],
        'benefits' => [],
        'cutlist' => ['unpaid_leave' => 0, 'missing_punch' => 0, 'punishment' => $punishment],
        'tax' => [
            'monthly_tax' => $tax, 'annual_tax' => $tax * 12, 'mode' => 'unconfigured', 'breakdown' => [],
            'allocation' => ['monthly_taxable_income' => $gross, 'annual_exemption' => 0, 'taxable_salary_percent' => 100],
        ],
        'totals' => ['gross' => $gross, 'deductions' => $deductions, 'net' => round($gross - $deductions, 2)],
    ];

    // `payroll_slips.employee_id` is a real foreign key, so the employee has to exist.
    if (! DB::table('employees')->where('employee_id', $employeeId)->exists()) {
        DB::table('employees')->insert([
            'employee_id' => $employeeId, 'first_name' => "Emp {$employeeId}", 'full_name' => "Emp {$employeeId}",
            'employment_type' => 'Permanent', 'status' => 'Active', 'is_active' => 1,
            'allow_overtime' => 1, 'duty_hours' => 8,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return DB::table('payroll_slips')->insertGetId(array_merge([
        'salary_sheet_id' => $sheet->id, 'employee_id' => $employeeId, 'employee_name' => "Emp {$employeeId}",
        'department' => 'Test', 'designation' => 'Staff',
        'pay_year' => $sheet->pay_year, 'pay_month' => $sheet->pay_month,
        'basic_salary' => $gross / 2, 'gross_salary' => $gross, 'total_benefits' => 0,
        'total_deductions' => $deductions, 'net_salary' => round($gross - $deductions, 2),
        'payment_status' => 'unpaid', 'breakdown_json' => json_encode($breakdown),
        'created_at' => now(), 'updated_at' => now(),
    ], $overrides));
}

it('re-prices a payslip that was calculated without a policy and leaves every other figure alone', function (): void {
    rtPolicy();
    $sheet = rtSheet(2026, 10);
    // 102,500/month => 1,230,000/year: 100,000@5% + 300,000@10% + 400,000@15% + 80,000@20% = 111,000/yr
    $id = rtSlip($sheet, 'E1', 102500.0, 6150.0, 0.0, 0.0);

    Artisan::call('payroll:recalculate-tax', ['sheet' => $sheet->id, '--apply' => true, '--date' => '2026-12-31']);

    $slip = DB::table('payroll_slips')->find($id);
    $breakdown = json_decode($slip->breakdown_json, true);

    expect((float) $breakdown['tax']['monthly_tax'])->toBe(9250.0)
        ->and((float) $breakdown['tax']['annual_tax'])->toBe(111000.0)
        ->and($breakdown['tax']['mode'])->toBe('progressive')
        ->and((float) $slip->total_deductions)->toBe(round(6150 + 9250, 2))
        ->and((float) $slip->net_salary)->toBe(round(102500 - 6150 - 9250, 2))
        // Nothing else moved.
        ->and((float) $slip->gross_salary)->toBe(102500.0)
        ->and((float) $breakdown['provident_fund']['employee_contribution'])->toBe(6150.0);
});

it('counts cutlist punishment among the deductions it reconciles against', function (): void {
    rtPolicy();
    $sheet = rtSheet(2026, 10);
    // A slip with a punishment is not a slip with an unexplained gap.
    $id = rtSlip($sheet, 'E2', 45000.0, 3100.0, 3000.0, 0.0);

    Artisan::call('payroll:recalculate-tax', ['sheet' => $sheet->id, '--apply' => true, '--date' => '2026-12-31']);

    $slip = DB::table('payroll_slips')->find($id);
    // 45,000/month => 540,000/year: 100,000@5% + 90,000@10% = 14,000/yr => 1,166.67/month
    expect((float) json_decode($slip->breakdown_json, true)['tax']['monthly_tax'])->toBe(1166.67)
        ->and((float) $slip->total_deductions)->toBe(round(3100 + 3000 + 1166.67, 2));
});

it('refuses to touch a payslip that has already been paid', function (): void {
    rtPolicy();
    $sheet = rtSheet(2026, 10);
    $id = rtSlip($sheet, 'E3', 102500.0, 0.0, 0.0, 0.0, ['payment_status' => 'paid', 'payment_journal_id' => rtJournal()]);

    Artisan::call('payroll:recalculate-tax', ['sheet' => $sheet->id, '--apply' => true, '--date' => '2026-12-31']);

    expect((float) DB::table('payroll_slips')->find($id)->total_deductions)->toBe(0.0);
});

it('refuses a sheet that is already posted to the ledger', function (): void {
    rtPolicy();
    $sheet = rtSheet(2026, 10);
    $id = rtSlip($sheet, 'E4', 102500.0, 0.0, 0.0, 0.0);
    $sheet->update(['accrual_journal_id' => rtJournal()]);

    Artisan::call('payroll:recalculate-tax', ['sheet' => $sheet->id, '--apply' => true, '--date' => '2026-12-31']);

    // The accrual journal describes this payroll; changing a slip underneath it would break that.
    expect((float) DB::table('payroll_slips')->find($id)->total_deductions)->toBe(0.0);
});

it('applies an employee override only where the override is in force', function (): void {
    rtPolicy();
    $sheet = rtSheet(2026, 10);
    DB::table('payroll_employee_tax_profile_versions')->insert([
        'employee_id' => 'E5', 'override_mode' => 'force_fixed', 'fixed_tax_amount' => 400,
        'fixed_tax_frequency' => 'monthly', 'effective_from' => '2026-11-14', 'effective_to' => null,
        'change_reason' => 'test', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $id = rtSlip($sheet, 'E5', 102500.0, 0.0, 0.0, 0.0);

    Artisan::call('payroll:recalculate-tax', ['sheet' => $sheet->id, '--apply' => true, '--date' => '2026-12-31']);
    // Priced at 31 December the override applies: 400/month, not 9,250.
    expect((float) json_decode(DB::table('payroll_slips')->find($id)->breakdown_json, true)['tax']['monthly_tax'])->toBe(400.0);
});

it('writes nothing on a dry run', function (): void {
    rtPolicy();
    $sheet = rtSheet(2026, 10);
    $id = rtSlip($sheet, 'E6', 102500.0, 0.0, 0.0, 0.0);

    Artisan::call('payroll:recalculate-tax', ['sheet' => $sheet->id, '--date' => '2026-12-31']);

    expect((float) DB::table('payroll_slips')->find($id)->total_deductions)->toBe(0.0);
});
