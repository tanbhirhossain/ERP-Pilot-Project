<?php

/**
 * Two rules this file pins, both reported from the live payroll desk:
 *
 *  1. **A non-punishable HRM attendance policy can never stop payroll or carry a penalty.**
 *     "Punishable" is the flag that says HRM owns this employee's discipline; while it is off, late /
 *     early-out / missing-punch figures must arrive on the cutlist with zero deductions, and a missing
 *     salary-history row must not abort the whole month — the employee is paid at the nearest known
 *     rate with the gap flagged on the slip, because silently *stopping* pay is also "punishing".
 *     A punishable policy keeps the hard stop: there, the records must be exact and a guessed rate
 *     would be a real under/over-payment.
 *
 *  2. **The cutlist is the salary sheet's review twin — exactly the rows the calculation consumes,
 *     but not final until a reviewer approves it.** Regenerate from HRM, adjust per row (every
 *     adjustment stores who/when/why and is what the engine then pays), approve to lock. When
 *     Payroll settings require review, queueing the month's salary sheet is blocked until the cutlist
 *     for that month is approved; the approved, adjusted figures flow into the slips unchanged.
 */

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\CORE\Models\User;
use Modules\HRM\Models\Attendance\AttendanceDaily;
use Modules\HRM\Models\Department;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Employee\EmployeePolicy;
use Modules\HRM\Models\Employee\EmployeeSalary;
use Modules\PAYROLL\Actions\ProcessMonthlyPayrollBatchAction;
use Modules\PAYROLL\DTO\EmployeePayrollData;
use Modules\PAYROLL\Interfaces\HRM\EmployeePayrollDataProviderInterface;
use Modules\PAYROLL\Models\PayrollCutlist;
use Modules\PAYROLL\Models\PayrollCutlistItem;
use Modules\PAYROLL\Models\PayrollSalarySheet;
use Modules\PAYROLL\Models\PayrollSetting;
use Modules\PAYROLL\Models\PayrollSlip;
use Modules\PAYROLL\Services\PayrollCalculationEngineService;
use Modules\PAYROLL\Services\PayrollSettingsService;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    config(['hashing.bcrypt.rounds' => 4, 'queue.default' => 'sync']);
});

function crrEmployee(bool $punishable, array $overrides = []): Employee
{
    $department = Department::factory()->create();
    $employee = Employee::factory()->create(array_merge([
        'department_id' => $department->id,
        'joining_date' => '2020-01-01',
        'status' => 'Active',
        'is_active' => true,
        'duty_hours' => 8,
    ], $overrides));
    EmployeePolicy::factory()->create([
        'employee_id' => $employee->employee_id,
        'shift_type' => 'Fixed',
        'duty_hours' => 8,
        'is_punishable' => $punishable,
    ]);

    return $employee;
}

function crrSalary(Employee $employee, array $attributes = []): void
{
    EmployeeSalary::query()->create(array_merge([
        'employee_id' => $employee->employee_id,
        'basic_salary' => 20000,
        'gross_salary' => 30000,
        'house_rent' => 6000,
        'medical_allowance' => 1500,
        'other_allowance' => 2500,
        'net_salary' => 30000,
        'effective_from' => '2020-01-01',
        'is_current' => true,
    ], $attributes));
}

function crrSolo(array $keep): void
{
    // This suite pins OUR employees' arithmetic; demo-seeded roster members (AMZ) would otherwise join
    // every payroll run through the real routes and change the counts.
    Employee::query()->whereNotIn('employee_id', $keep ?: ['__none__'])->update(['is_active' => false, 'status' => 'Resigned']);
}

function crrAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole('Super Admin');

    return $user;
}

// ---------------------------------------------------------------- rule 1

test('a non-punishable policy turns a salary-history gap into a flagged fallback, not a stopped payroll', function (): void {
    $employee = crrEmployee(punishable: false);
    // The only salary record starts 2026-10-10: days 1-9 of the month are uncovered.
    crrSalary($employee, ['effective_from' => '2026-10-10']);

    crrSolo([$employee->employee_id]);
    $rows = collect(app(EmployeePayrollDataProviderInterface::class)->employeesForPayroll('2026-10-01', '2026-10-31')->all());
    $data = $rows->firstWhere('employeeId', $employee->employee_id);

    expect($data)->toBeInstanceOf(EmployeePayrollData::class)
        // Every day is paid; the uncovered ones use the nearest known rate, so the month-weighted
        // gross equals that rate with no proration loss.
        ->and($data->payableDays)->toBe(31)
        ->and($data->prorationFactor)->toBe(1.0)
        ->and((float) $data->grossSalary)->toBe(30000.0)
        ->and($data->notes['salary_rate_fallback_days'])->toBe(9)
        // The uncovered stretch is visible per segment for the officer to fix.
        ->and(collect($data->salarySegments)->firstWhere('rate_from_other_period', true)['days'])->toBe(9);
});

test('a punishable policy still stops the run rather than guessing a rate', function (): void {
    $employee = crrEmployee(punishable: true);
    crrSalary($employee, ['effective_from' => '2026-10-10']);
    crrSolo([$employee->employee_id]);

    expect(fn () => app(EmployeePayrollDataProviderInterface::class)->employeesForPayroll('2026-10-01', '2026-10-31')->all())
        ->toThrow(ValidationException::class, 'No HRM salary-history record covers');
});

test('an employee with no salary history is skipped by the sheet, and named; asking for them alone is still fatal', function (): void {
    // Updated 2026-11-10. This threw, and that throw is how one imported row with a blank salary field
    // failed an entire month: "No HRM salary-history record covers M302012 on 2026-10-01; payroll was
    // stopped rather than silently underpaying the employee." There is genuinely no rate to fall back to
    // — so the answer is not to guess one, and it is not to punish the other 983 people either. The
    // employee is left out of the batch and reported on the sheet instead.
    $employee = crrEmployee(punishable: false);
    $provider = app(EmployeePayrollDataProviderInterface::class);

    $batch = collect($provider->employeesForPayroll('2026-10-01', '2026-10-31')->all())->pluck('employeeId')->all();

    // (The file's other fixtures seed demo employees, so the assertion is about *this* employee, not
    // about the batch being empty.)
    expect($batch)->not->toContain($employee->employee_id)
        ->and($provider->unpayableForPeriod('2026-10-01', '2026-10-31')->pluck('employee_id')->all())
        ->toContain($employee->employee_id);

    // The single-employee path (an adjustment, a settlement) has no batch to protect, so it still refuses
    // to invent a rate rather than pay a guess.
    expect(fn () => $provider->employeeForPayroll($employee->employee_id, '2026-10-01', '2026-10-31'))
        ->toThrow(ValidationException::class, 'No HRM salary-history record covers');
});

test('the slip breakdown carries the fallback as a payroll note', function (): void {
    $employee = crrEmployee(punishable: false);
    crrSalary($employee, ['effective_from' => '2026-10-10']);
    crrSolo([$employee->employee_id]);
    $data = collect(app(EmployeePayrollDataProviderInterface::class)->employeesForPayroll('2026-10-01', '2026-10-31')->all())
        ->firstWhere('employeeId', $employee->employee_id);

    $result = app(PayrollCalculationEngineService::class)->calculate($data, 2026, 10, null, false);

    expect($result['breakdown_json']['payroll_notes'])
        ->toHaveCount(1)
        ->and($result['breakdown_json']['payroll_notes'][0])->toContain('9 day(s)')
        ->and($result['breakdown_json']['payroll_notes'][0])->toContain('Backfill the salary history in HRM');
});

test('attendance discipline deductions are zero for a non-punishable employee and charged for a punishable one', function (): void {
    $settings = app(PayrollSettingsService::class)->current();
    $settings->update(['missing_punch_penalty_amount' => 100, 'punishment_days_per_salary_day' => 1]);

    $free = crrEmployee(punishable: false);
    crrSalary($free);
    $strict = crrEmployee(punishable: true);
    crrSalary($strict);
    foreach ([$free, $strict] as $employee) {
        foreach (['2026-10-05' => 'Missing In', '2026-10-06' => 'Late', '2026-10-07' => 'Early Out'] as $date => $status) {
            AttendanceDaily::query()->create(['employee_id' => $employee->employee_id, 'duty_date' => $date, 'status' => $status]);
        }
    }

    crrSolo([$free->employee_id, $strict->employee_id]);
    $this->actingAs(crrAdmin())->post(route('payroll.cutlists.generate'), ['pay_year' => 2026, 'pay_month' => 10])->assertRedirect();

    $freeItem = PayrollCutlistItem::query()->where('employee_id', $free->employee_id)->sole();
    $strictItem = PayrollCutlistItem::query()->where('employee_id', $strict->employee_id)->sole();

    expect((float) $freeItem->missing_punch_deduction)->toBe(0.0)
        ->and((float) $freeItem->late_penalty_deduction)->toBe(0.0)
        ->and($freeItem->punishable)->toBeFalse()
        // Attendance is still *counted* — the review shows the facts, only the penalty is switched off.
        ->and($freeItem->missing_punch_count)->toBe(1)
        // punishment_days counts Late/Early-Out rows only — a missing punch is tracked separately.
        ->and($freeItem->punishment_days)->toBe(2)
        ->and((float) $strictItem->missing_punch_deduction)->toBe(100.0)
        ->and((float) $strictItem->late_penalty_deduction)->toBeGreaterThan(0);
});

test('the engine ignores a stale missing-punch deduction on a non-punishable row even if one is in the table', function (): void {
    $employee = crrEmployee(punishable: false);
    crrSalary($employee);
    crrSolo([$employee->employee_id]);
    $data = collect(app(EmployeePayrollDataProviderInterface::class)->employeesForPayroll('2026-10-01', '2026-10-31')->all())
        ->firstWhere('employeeId', $employee->employee_id);
    $item = new PayrollCutlistItem(['employee_id' => $employee->employee_id, 'punishable' => false, 'missing_punch_count' => 2, 'missing_punch_deduction' => 200]);
    $item->exists = false;

    $result = app(PayrollCalculationEngineService::class)->calculate($data, 2026, 10, $item, true);

    $clean = $result['total_deductions'];
    $item->setAttribute('punishable', true);
    $result2 = app(PayrollCalculationEngineService::class)->calculate($data, 2026, 10, $item, true);

    expect($clean)->toBe(0.0)
        ->and((float) $result2['total_deductions'])->toBe(200.0);
});

// ---------------------------------------------------------------- rule 2

test('the cutlist lifecycle is review → approve → reopen, with per-row adjustments and reasons', function (): void {
    $employee = crrEmployee(punishable: true);
    crrSalary($employee);
    AttendanceDaily::query()->create(['employee_id' => $employee->employee_id, 'duty_date' => '2026-11-03', 'status' => 'Absent']);
    crrSolo([$employee->employee_id]);
    $admin = crrAdmin();
    $this->actingAs($admin);

    // Generate from HRM.
    $this->post(route('payroll.cutlists.generate'), ['pay_year' => 2026, 'pay_month' => 11])->assertRedirect();
    $cutlist = PayrollCutlist::query()->where('pay_year', 2026)->where('pay_month', 11)->sole();
    expect($cutlist->status)->toBe('generated');

    // The list and review pages render.
    $indexResponse = $this->get(route('payroll.cutlists.index'))->assertOk();
    $page = $indexResponse->viewData('page');
    expect($page['props']['reviewRequired'])->toBeFalse()
        ->and($page['props']['cutlists']['data'])->toHaveCount(1)
        ->and($page['props']['cutlists']['data'][0]['status'])->toBe('generated');
    $this->get(route('payroll.cutlists.show', $cutlist))->assertOk();

    // Approving needs a reason.
    $this->post(route('payroll.cutlists.approve', $cutlist), [])->assertSessionHasErrors('note');
    $this->post(route('payroll.cutlists.approve', $cutlist), ['note' => 'verified against attendance register'])
        ->assertSessionHasNoErrors();
    $cutlist->refresh();
    expect($cutlist->status)->toBe('approved')
        ->and($cutlist->approved_by)->toBe($admin->id)
        ->and($cutlist->approval_note)->toBe('verified against attendance register');

    // While approved, row edits are refused; reopening is allowed with a reason.
    $item = $cutlist->items()->sole();
    $this->patch(route('payroll.cutlists.items.update', [$cutlist, $item]), ['unpaid_leave_deduction' => 500, 'reason' => 'not while signed off'])
        ->assertSessionHasErrors('reason');
    $this->post(route('payroll.cutlists.reopen', $cutlist), ['note' => 'payroll lead flagged row 14'])
        ->assertSessionHasNoErrors();
    expect($cutlist->refresh()->status)->toBe('generated');

    // Adjust with a reason: the amount changes and the audit trail records who/what/why.
    $this->patch(route('payroll.cutlists.items.update', [$cutlist, $item]), [
        'unpaid_leave_deduction' => '500.00',
        'overtime_pay' => (float) $item->overtime_pay,
        'reason' => 'Unpaid day cleared: regularization APP-77 approved after cutlist ran',
    ])->assertSessionHasNoErrors();
    $item->refresh();
    $review = $item->source_breakdown['review'][0] ?? null;
    expect((float) $item->unpaid_leave_deduction)->toBe(500.0)
        ->and($review)->not->toBeNull()
        ->and($review['by'])->toBe($admin->id)
        ->and($review['reason'])->toContain('APP-77')
        // json_decode may return 0/500 as ints on some drivers — compare numerically.
        ->and(array_map('floatval', $review['changes']['unpaid_leave_deduction']))->toBe(['from' => 0.0, 'to' => 500.0]);

    // A no-op edit records nothing.
    $this->patch(route('payroll.cutlists.items.update', [$cutlist, $item]), ['unpaid_leave_deduction' => 500, 'reason' => 'same as now'])
        ->assertSessionHas('info', 'No amounts changed — nothing recorded.');

    // Regenerating over reviewer adjustments needs the explicit overwrite confirmation…
    $this->post(route('payroll.cutlists.generate'), ['pay_year' => 2026, 'pay_month' => 11])->assertSessionHasErrors('overwrite_adjustments');
    // …and an approved cutlist refuses regeneration entirely until reopened.
    $this->post(route('payroll.cutlists.approve', $cutlist), ['note' => 'second sign-off'])->assertSessionHasNoErrors();
    $this->post(route('payroll.cutlists.generate'), ['pay_year' => 2026, 'pay_month' => 11, 'overwrite_adjustments' => true])
        ->assertSessionHasErrors('pay_month');
});

test('with review required, payroll cannot be queued until the month is approved — then it pays the reviewed figures', function (): void {
    $employee = crrEmployee(punishable: false);
    crrSalary($employee);
    crrSolo([$employee->employee_id]);
    $admin = crrAdmin();
    $this->actingAs($admin);
    $settings = app(PayrollSettingsService::class)->current();
    $settings->update(['cutlist_review_required' => true]);

    // No cutlist at all: the run refuses and tells the officer exactly what to do.
    $this->post(route('payroll.sheets.store'), ['pay_year' => 2026, 'pay_month' => 12])
        ->assertSessionHasErrors('payroll_cutlist_id');
    expect(PayrollSalarySheet::query()->where('pay_month', 12)->exists())->toBeFalse();

    // Generated but unapproved: still blocked, with the status in the message.
    $this->post(route('payroll.cutlists.generate'), ['pay_year' => 2026, 'pay_month' => 12])->assertRedirect();
    $cutlist = PayrollCutlist::query()->where('pay_month', 12)->sole();
    $this->post(route('payroll.sheets.store'), ['pay_year' => 2026, 'pay_month' => 12])->assertSessionHasErrors('payroll_cutlist_id');

    // An adjustment made during review must be what payroll pays — that is the whole point of the stage.
    $item = $cutlist->items()->sole();
    $this->patch(route('payroll.cutlists.items.update', [$cutlist, $item]), ['unpaid_leave_deduction' => 500, 'reason' => 'approved NP day 2026-12-07'])
        ->assertSessionHasNoErrors();
    $this->post(route('payroll.cutlists.approve', $cutlist), ['note' => 'all rows verified'])->assertSessionHasNoErrors();

    // Approving an empty or double sign-off is refused, then queue the sheet.
    $this->post(route('payroll.cutlists.approve', $cutlist), ['note' => 'again'])->assertSessionHasErrors('note');
    $this->post(route('payroll.sheets.store'), ['pay_year' => 2026, 'pay_month' => 12])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $sheet = PayrollSalarySheet::query()->where('pay_month', 12)->sole();
    expect($sheet->payroll_cutlist_id)->toBe($cutlist->id);
    if ($sheet->refresh()->status === 'queued') {
        app(ProcessMonthlyPayrollBatchAction::class)->execute($sheet->id);
    }
    $sheet->refresh();
    expect($sheet->status)->toBe('ready');

    $slip = PayrollSlip::query()->where('salary_sheet_id', $sheet->id)->sole();
    expect((float) $slip->total_deductions)->toBe(500.0)
        ->and((float) $slip->breakdown_json['cutlist']['unpaid_leave'])->toBe(500.0)
        ->and((float) $slip->net_salary)->toBe(30000.0 - 500.0);
});

test('without the setting, approval stays advisory and payroll behaves exactly as before', function (): void {
    $employee = crrEmployee(punishable: false);
    crrSalary($employee);
    crrSolo([$employee->employee_id]);
    $this->actingAs(crrAdmin());
    expect((bool) (PayrollSetting::query()->first()?->cutlist_review_required ?? false))->toBeFalse();

    $this->post(route('payroll.sheets.store'), ['pay_year' => 2026, 'pay_month' => 12])->assertSessionHasNoErrors()->assertRedirect();
    $sheet = PayrollSalarySheet::query()->where('pay_month', 12)->sole();
    if ($sheet->refresh()->status === 'queued') {
        app(ProcessMonthlyPayrollBatchAction::class)->execute($sheet->id);
    }
    // The gate did not fire: no approved cutlist existed, and the run went ahead anyway. The batch
    // links its OWN internal cutlist afterwards — legacy behaviour, unchanged by this slice.
    expect($sheet->refresh()->status)->toBe('ready')
        ->and(PayrollSlip::query()->where('salary_sheet_id', $sheet->id)->count())->toBe(1)
        ->and((float) PayrollSlip::query()->where('salary_sheet_id', $sheet->id)->sole()->net_salary)->toBe(30000.0);
});

test('a reviewer permission opens the review desk but not the generation trigger', function (): void {
    $user = User::factory()->create();
    $role = Role::create(['name' => 'Cutlist Reviewer', 'guard_name' => 'web']);
    $role->givePermissionTo('payroll.view');
    $role->givePermissionTo('payroll.cutlists.review');
    $user->assignRole($role);
    $this->actingAs($user);

    $this->get(route('payroll.cutlists.index'))->assertOk();
    $this->post(route('payroll.cutlists.generate'), ['pay_year' => 2026, 'pay_month' => 11])->assertForbidden();
});
