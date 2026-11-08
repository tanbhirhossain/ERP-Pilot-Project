<?php

use Illuminate\Support\Facades\Bus;
use Modules\CORE\Models\Role;
use Modules\CORE\Models\User;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Employee\EmployeeSalary;
use Modules\PAYROLL\Actions\ProcessMonthlyPayrollBatchAction;
use Modules\PAYROLL\Database\Seeders\PAYROLLPermissionSeeder;
use Modules\PAYROLL\Jobs\ProcessSalarySheetJob;
use Modules\PAYROLL\Models\PayrollCalculationSnapshot;
use Modules\PAYROLL\Models\PayrollPeriodExclusion;
use Modules\PAYROLL\Models\PayrollSalarySheet;
use Modules\PAYROLL\Models\PayrollSlip;

function createSalaryGenerationEmployee(string $employeeId, string $salaryStart): Employee
{
    $employee = Employee::factory()->create([
        'employee_id' => $employeeId,
        'first_name' => 'Payroll',
        'last_name' => $employeeId,
        'full_name' => 'Payroll '.$employeeId,
        'joining_date' => '2020-01-01',
        'status' => 'Active',
        'is_active' => true,
    ]);

    EmployeeSalary::query()->create([
        'employee_id' => $employeeId,
        'basic_salary' => 20000,
        'gross_salary' => 30000,
        'house_rent' => 6000,
        'medical_allowance' => 1500,
        'other_allowance' => 2500,
        'net_salary' => 30000,
        'effective_from' => $salaryStart,
        'effective_to' => null,
        'is_current' => true,
    ]);

    return $employee;
}

test('retrying a failed October sheet can exclude an approved no-pay employee without hiding the exception', function () {
    $role = Role::query()->create(['name' => 'Admin', 'guard_name' => 'web']);
    $this->seed(PAYROLLPermissionSeeder::class);
    $manager = User::factory()->create();
    $manager->assignRole($role);

    $excluded = createSalaryGenerationEmployee('M302012', '2026-10-02');
    $included = createSalaryGenerationEmployee('M302013', '2020-01-01');
    $sheet = PayrollSalarySheet::query()->create([
        'pay_year' => 2026,
        'pay_month' => 10,
        'status' => 'failed',
        'include_punishments' => false,
        'created_by' => $manager->id,
        'failure_message' => 'No HRM salary-history record covers M302012 on 2026-10-01.',
    ]);

    Bus::fake();
    $this->actingAs($manager)
        ->from(route('payroll.sheets.create', ['pay_year' => 2026, 'pay_month' => 10]))
        ->post(route('payroll.sheets.store'), [
            'pay_year' => 2026,
            'pay_month' => 10,
            'include_punishments' => false,
            'excluded_employee_ids' => [$excluded->employee_id],
            'exclusion_reason' => 'Approved unpaid/no-pay period',
        ])
        ->assertRedirect(route('payroll.sheets.show', $sheet));

    Bus::assertDispatched(ProcessSalarySheetJob::class, fn (ProcessSalarySheetJob $job): bool => $job->salarySheetId === $sheet->id);
    $exclusion = PayrollPeriodExclusion::query()->where('employee_id', $excluded->employee_id)
        ->where('pay_year', 2026)->where('pay_month', 10)->firstOrFail();
    expect($exclusion->reason)->toBe('Approved unpaid/no-pay period')
        ->and((int) $exclusion->created_by)->toBe((int) $manager->id);

    app(ProcessMonthlyPayrollBatchAction::class)->execute($sheet->id);

    $sheet->refresh();
    expect($sheet->status)->toBe('ready')
        ->and((int) $sheet->employee_count)->toBe(1)
        ->and((int) $sheet->processed_count)->toBe(1)
        ->and(PayrollSlip::query()->where('salary_sheet_id', $sheet->id)->where('employee_id', $excluded->employee_id)->exists())->toBeFalse()
        ->and(PayrollSlip::query()->where('salary_sheet_id', $sheet->id)->where('employee_id', $included->employee_id)->exists())->toBeTrue();

    $snapshot = PayrollCalculationSnapshot::query()->where('salary_sheet_id', $sheet->id)->firstOrFail();
    expect($snapshot->snapshot_json['period_exclusions'][0]['employee_id'])->toBe($excluded->employee_id)
        ->and($snapshot->snapshot_json['period_exclusions'][0]['reason'])->toBe('Approved unpaid/no-pay period');

    $this->get(route('payroll.sheets.show', $sheet))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('PAYROLL::SalarySheets/Show')
            ->where('periodExclusions.0.employee_id', $excluded->employee_id)
            ->where('periodExclusions.0.reason', 'Approved unpaid/no-pay period'));
});
