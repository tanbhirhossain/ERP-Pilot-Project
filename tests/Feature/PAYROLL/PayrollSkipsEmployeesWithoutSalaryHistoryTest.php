<?php

/**
 * "still salary sheet not work — No HRM salary-history record covers M302012 on 2026-10-01; payroll was
 * stopped rather than silently underpaying the employee." (2026-11-10)
 *
 * The message was right and the sheet was still broken. `countEmployeesForPayroll()` only counts people
 * with a salary row overlapping the period, but `employeesForPayroll()` — the batch the queued sheet
 * actually walks — had no such filter, so the 14 active employees of 997 with no salary record at all were
 * swept in anyway and threw, marking the whole month `failed`. The two methods also disagreed in the other
 * direction for anyone whose rate can be continued from a neighbouring period (allowed for a non-punishable
 * HRM policy), which the batch paid and the count refused to promise — enough to break
 * `PayrollSalarySheetService::release()`'s slip-count check.
 *
 * One rule now feeds both, and the people left out are named on the sheet instead of being a mystery:
 * payroll builds a slip when it can derive a rate for every day, and says so when it cannot.
 *
 * Helpers are prefixed `psw`.
 */

use Illuminate\Validation\ValidationException;
use Modules\CORE\Models\Permission;
use Modules\CORE\Models\User;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Employee\EmployeePolicy;
use Modules\HRM\Models\Employee\EmployeeSalary;
use Modules\PAYROLL\Actions\ProcessMonthlyPayrollBatchAction;
use Modules\PAYROLL\Database\Seeders\PAYROLLPermissionSeeder;
use Modules\PAYROLL\Interfaces\HRM\EmployeePayrollDataProviderInterface;
use Modules\PAYROLL\Models\PayrollSalarySheet;
use Modules\PAYROLL\Models\PayrollSlip;

const PSW_START = '2026-10-01';
const PSW_END = '2026-10-31';

function pswEmployee(string $employeeId, ?string $salaryFrom, ?string $salaryTo = null, bool $punishable = false): Employee
{
    $employee = Employee::factory()->create([
        'employee_id' => $employeeId,
        'first_name' => 'Rate',
        'last_name' => $employeeId,
        'full_name' => 'Rate '.$employeeId,
        'joining_date' => '2020-01-01',
        'status' => 'Active',
        'is_active' => true,
    ]);

    if ($salaryFrom !== null) {
        EmployeeSalary::query()->create([
            'employee_id' => $employeeId,
            'basic_salary' => 20000,
            'gross_salary' => 30000,
            'house_rent' => 6000,
            'medical_allowance' => 1500,
            'other_allowance' => 2500,
            'net_salary' => 30000,
            'effective_from' => $salaryFrom,
            'effective_to' => $salaryTo,
            'is_current' => $salaryTo === null,
        ]);
    }

    EmployeePolicy::factory()->create([
        'employee_id' => $employeeId,
        'shift_type' => 'Fixed',
        'duty_hours' => 8,
        'is_punishable' => $punishable,
        'grace_time_in' => 10,
        'grace_time_out' => 10,
    ]);

    return $employee;
}

function pswBatch(): array
{
    $ids = [];

    foreach (app(EmployeePayrollDataProviderInterface::class)->employeesForPayroll(PSW_START, PSW_END) as $data) {
        $ids[] = $data->employeeId;
    }

    sort($ids);

    return $ids;
}

test('an employee with no salary history is left out of the batch instead of stopping the month', function (): void {
    pswEmployee('M302012', null);                                        // no salary record at all
    pswEmployee('M302013', '2020-01-01');                                // a normal, payable employee
    pswEmployee('M302014', '2026-11-05');                                // rows start after the period: rate continuation
    pswEmployee('M302015', '2026-11-05', null, punishable: true);        // same, but their policy demands exact records

    // Joins after the period: nobody can be paid for October, and the promise must not count them either.
    Employee::factory()->create(['employee_id' => 'M302017', 'joining_date' => '2026-12-01', 'status' => 'Active', 'is_active' => true]);
    EmployeeSalary::query()->create(['employee_id' => 'M302017', 'basic_salary' => 1000, 'gross_salary' => 1500, 'effective_from' => '2026-12-01', 'is_current' => true]);

    $provider = app(EmployeePayrollDataProviderInterface::class);

    expect(pswBatch())->toBe(['M302013', 'M302014'])
        // The promise and the batch are now the same number — which is also what release() checks.
        ->and($provider->countEmployeesForPayroll(PSW_START, PSW_END))->toBe(2)
        ->and($provider->unpayableForPeriod(PSW_START, PSW_END)->pluck('employee_id'))->not->toContain('M302017');
});

test('the salary sheet completes, and the employees left out are named with a reason', function (): void {
    pswEmployee('M302012', null);
    pswEmployee('M302013', '2020-01-01');
    pswEmployee('M302015', '2026-11-05', null, punishable: true);

    $sheet = PayrollSalarySheet::query()->create([
        'pay_year' => 2026,
        'pay_month' => 10,
        'status' => 'queued',
        'include_punishments' => false,
    ]);

    app(ProcessMonthlyPayrollBatchAction::class)->execute($sheet->id);
    $sheet->refresh();

    expect($sheet->status)->toBe('ready')
        ->and($sheet->failure_message)->toBeNull()
        ->and((int) $sheet->employee_count)->toBe(1)
        ->and(PayrollSlip::query()->where('salary_sheet_id', $sheet->id)->pluck('employee_id')->all())->toBe(['M302013'])
        // Continuation did not guess a rate for the employee who was never paid anything.
        ->and(PayrollSlip::query()->where('employee_id', 'M302012')->exists())->toBeFalse();

    $warnings = app(EmployeePayrollDataProviderInterface::class)->unpayableForPeriod(PSW_START, PSW_END);
    $byId = collect($warnings)->keyBy('employee_id');

    expect($warnings)->toHaveCount(2)
        ->and($byId->get('M302012')['reason'])->toContain('No HRM salary record at all')
        ->and($byId->get('M302012')['employee_name'])->toBe('Rate M302012')
        ->and($byId->get('M302015')['reason'])->toContain('does not reach this period');
});

test('the sheet screen shows who payroll skipped', function (): void {
    $this->seed(PAYROLLPermissionSeeder::class);
    $manager = User::factory()->create();
    // Granted directly: the permission seeder wires roles at seed time, so a role created afterwards
    // would hold nothing and the request would be a 403 about the middleware instead of the sheet page.
    $manager->givePermissionTo(Permission::firstOrCreate(['name' => 'payroll.sheets.manage', 'guard_name' => 'web']));

    pswEmployee('M302012', null);
    pswEmployee('M302013', '2020-01-01');

    $sheet = PayrollSalarySheet::query()->create([
        'pay_year' => 2026,
        'pay_month' => 10,
        'status' => 'ready',
        'include_punishments' => false,
        'employee_count' => 1,
        'processed_count' => 1,
    ]);

    $this->actingAs($manager)
        ->get(route('payroll.sheets.show', $sheet))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('PAYROLL::SalarySheets/Show')
            ->where('payrollWarnings.0.employee_id', 'M302012')
            ->has('payrollWarnings', 1));

    // And the run screen says it before the month is attempted, not after it fails.
    $this->actingAs($manager)
        ->get(route('payroll.sheets.create', ['pay_year' => 2026, 'pay_month' => 10]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('payrollWarnings', 1));
});

test('a punishable policy still refuses to guess a rate, and says what to fix', function (): void {
    // An employee with records that cover part of the month under a punishable policy is the case the
    // hard stop exists for: excluding them from the batch silently underpays, so it still throws — but the
    // message now carries the remedy, which is what the officer on the phone actually needs.
    $employee = pswEmployee('M302016', '2026-10-15', null, punishable: true);

    expect(fn (): array => app(EmployeePayrollDataProviderInterface::class)
        ->employeeForPayroll($employee->employee_id, PSW_START, PSW_END)
        ->toArray())
        ->toThrow(ValidationException::class, 'payroll was stopped rather than silently underpaying');
});
