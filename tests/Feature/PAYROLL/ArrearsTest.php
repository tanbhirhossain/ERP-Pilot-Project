<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\PAYROLL\Actions\CapturePayrollCalculationSnapshotAction;
use Modules\PAYROLL\Models\EmployeeRecurringAdjustment;
use Modules\PAYROLL\Services\PayrollCalculationEngineService;

afterEach(function (): void {
    $this->travelBack();
    Carbon::setTestNow(null);
    \Illuminate\Support\Carbon::setTestNow(null);
});

/**
 * A month that was already released cannot pay somebody it missed.
 *
 * Fourteen employees were hired (or had their salary entered) after October's sheet had been
 * captured and released, so October simply does not contain them. Reopening a released sheet voids
 * its accrual journal and recalculates 981 slips — many of them already paid — to deliver a month
 * that is otherwise correct. The cheaper and more honest route is to pay what October owed them in
 * November, as an arrears line that names the month it belongs to.
 *
 * This is that route, and these are the two things that can go wrong with it: it does not pay, or it
 * pays twice.
 */
test('an arrears adjustment pays an amount the released month could not, and only in the month it names', function (): void {
    @set_time_limit(0);
    $this->travelTo(Carbon::parse('2026-11-01 09:00:00'));

    $admin = \Modules\CORE\Models\User::factory()->create();
    $admin->assignRole(\Modules\CORE\Models\Role::findOrCreate('Administrator', 'web'));
    $this->actingAs($admin);
    $now = now()->toDateTimeString();

    DB::table('payroll_settings')->updateOrInsert(['id' => 1], [
        'currency' => 'BDT', 'unpaid_leave_day_divisor' => 30, 'punishment_days_per_salary_day' => 3,
        'overtime_multiplier' => 1, 'extra_duty_multiplier' => 1, 'missing_punch_penalty_amount' => 0,
        'cutlist_review_required' => false, 'missing_attendance_policy' => 'waive',
        'created_at' => $now, 'updated_at' => $now,
    ]);

    \Modules\HRM\Models\Employee\Employee::factory()->create([
        'employee_id' => 'ARR-ONE', 'full_name' => 'Arrears One', 'status' => 'Active',
        'is_active' => true, 'joining_date' => '2026-10-01', 'gender' => 'Male',
    ]);
    \Modules\HRM\Models\Employee\EmployeeSalary::factory()->create([
        'employee_id' => 'ARR-ONE', 'basic_salary' => 20000, 'gross_salary' => 30000,
        'effective_from' => '2026-10-01', 'effective_to' => null, 'is_current' => true,
    ]);
    DB::table('employee_policies')->insert([
        'employee_id' => 'ARR-ONE', 'grace_time_in' => 15, 'grace_time_out' => 15, 'shift_type' => 'Fixed',
        'is_punishable' => true, 'duty_hours' => 8, 'auto_shift' => false,
        'created_at' => $now, 'updated_at' => $now,
    ]);

    EmployeeRecurringAdjustment::query()->create([
        'employee_id' => 'ARR-ONE',
        'employee_name' => 'Arrears One',
        'adjustment_type' => 'benefit',
        'name' => 'October 2026 Arrears',
        'calculation_type' => 'fixed',
        'amount' => 32800,
        'percentage_base' => 'basic',
        'effective_from' => '2026-11-01',
        'effective_to' => '2026-11-30',   // November only
        'is_active' => true,
    ]);

    // --- November: the arrears is part of his pay. ---
    $november = \Modules\PAYROLL\Models\PayrollSalarySheet::query()->create([
        'pay_year' => 2026, 'pay_month' => 11, 'status' => 'queued',
        'include_punishments' => true, 'employee_count' => 1, 'processed_count' => 0,
        'gross_total' => 0, 'deduction_total' => 0, 'net_total' => 0, 'created_by' => $admin->id,
    ]);
    app(CapturePayrollCalculationSnapshotAction::class)->execute($november);
    $novemberSnapshot = DB::table('payroll_calculation_snapshots')->where('salary_sheet_id', $november->id)->value('snapshot_json');
    $novemberAdjustments = json_decode($novemberSnapshot, true)['employee_adjustments']['e:ARR-ONE'] ?? [];
    expect($novemberAdjustments)->toHaveCount(1)
        ->and((float) $novemberAdjustments[0]['amount'])->toBe(32800.0);

    // --- December: it is not. A one-month line must not become a permanent allowance. ---
    $december = \Modules\PAYROLL\Models\PayrollSalarySheet::query()->create([
        'pay_year' => 2026, 'pay_month' => 12, 'status' => 'queued',
        'include_punishments' => true, 'employee_count' => 1, 'processed_count' => 0,
        'gross_total' => 0, 'deduction_total' => 0, 'net_total' => 0, 'created_by' => $admin->id,
    ]);
    app(CapturePayrollCalculationSnapshotAction::class)->execute($december);
    $decemberSnapshot = json_decode(DB::table('payroll_calculation_snapshots')->where('salary_sheet_id', $december->id)->value('snapshot_json'), true);
    expect($decemberSnapshot['employee_adjustments']['e:ARR-ONE'] ?? [])->toBe([]);
});

test('an arrears line reaches the payslip as a named benefit, not as a silent bump to gross', function (): void {
    @set_time_limit(0);
    $this->travelTo(Carbon::parse('2026-11-02 10:00:00'));

    $engine = app(PayrollCalculationEngineService::class);
    $data = new \Modules\PAYROLL\DTO\EmployeePayrollData(
        employeeId: 'ARR-TWO',
        name: 'Arrears Two',
        department: 'OPD',
        designation: 'Officer',
        joiningDate: '2026-01-01',
        basicSalary: 20000.0,
        grossSalary: 30000.0,
        houseRent: 9000.0,
        medicalAllowance: 1500.0,
        otherAllowance: 3500.0,
        payGrade: null,
    );

    $snapshot = [
        'benefit_templates' => [],
        'deduction_templates' => [],
        'employee_adjustments' => ['e:ARR-TWO' => [[
            'adjustment_type' => 'benefit',
            'name' => 'October 2026 Arrears',
            'calculation_type' => 'fixed',
            'amount' => 32800,
            'percentage_base' => 'basic',
            'benefit_template' => ['code' => 'ARREAR-OCT26', 'taxable' => true, 'pf_eligible' => false],
        ]]],
        'pf_accounts' => [], 'loan_installments' => [], 'tax_configuration' => null,
    ];

    $result = $engine->calculate($data, 2026, 11, null, false, $snapshot);

    $named = collect($result['breakdown_json']['benefits'] ?? [])->firstWhere('name', 'October 2026 Arrears');

    expect($named)->not->toBeNull()
        ->and((float) $named['amount'])->toBe(32800.0)
        ->and($named['code'])->toBe('ARREAR-OCT26')
        // And the money is in the gross, not merely listed beside it.
        ->and((float) $result['gross_salary'])->toBe(62800.0)
        ->and((float) $result['total_benefits'])->toBe(32800.0);
});
