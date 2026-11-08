<?php

use Modules\CORE\Models\Role;
use Modules\CORE\Models\User;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Employee\EmployeeSalary;
use Modules\PAYROLL\Database\Seeders\PAYROLLPermissionSeeder;
use Modules\PAYROLL\Models\SalaryIncrement;

function payrollApprovalSetup(): array
{
    $role = Role::query()->create(['name' => 'Admin', 'guard_name' => 'web']);
    app(PAYROLLPermissionSeeder::class)->run();

    $approver = User::factory()->create();
    $approver->assignRole($role);
    $requester = User::factory()->create();

    $employee = Employee::factory()->create([
        'employee_id' => 'INC-APPROVAL-01',
        'first_name' => 'Increment',
        'last_name' => 'Approval',
        'full_name' => 'Increment Approval',
        'status' => 'Active',
        'is_active' => true,
    ]);
    EmployeeSalary::factory()->create([
        'employee_id' => $employee->employee_id,
        'basic_salary' => 20000,
        'gross_salary' => 30000,
        'house_rent' => 6000,
        'medical_allowance' => 1500,
        'other_allowance' => 2500,
        'net_salary' => 30000,
        'effective_from' => '2020-01-01',
        'effective_to' => null,
        'is_current' => true,
    ]);

    $increment = SalaryIncrement::query()->create([
        'employee_id' => $employee->employee_id,
        'employee_name' => $employee->full_name,
        'increment_type' => 'percentage',
        'increment_value' => 10,
        'previous_basic' => 20000,
        'new_basic' => 22000,
        'previous_gross' => 30000,
        'new_gross' => 33000,
        'effective_date' => '2026-10-01',
        'status' => 'pending',
        'requested_by' => $requester->id,
        'reason' => 'Approved performance and annual salary review.',
    ]);

    return compact('approver', 'requester', 'employee', 'increment');
}

test('Admin role can approve a salary increment and the effective salary history is updated', function () {
    ['approver' => $approver, 'employee' => $employee, 'increment' => $increment] = payrollApprovalSetup();

    $this->actingAs($approver)
        ->from(route('payroll.increments.index'))
        ->post(route('payroll.increments.approve', $increment), ['comments' => 'Approved after review.'])
        ->assertRedirect(route('payroll.increments.index'))
        ->assertSessionHasNoErrors();

    $increment->refresh();
    expect($increment->status)->toBe('applied')
        ->and((int) $increment->approved_by)->toBe((int) $approver->id)
        ->and($increment->approved_at)->not->toBeNull()
        ->and($increment->decision_comments)->toBe('Approved after review.');

    $newSalary = EmployeeSalary::query()->where('employee_id', $employee->employee_id)
        ->whereDate('effective_from', '2026-10-01')->firstOrFail();
    expect((float) $newSalary->basic_salary)->toBe(22000.0)
        ->and((float) $newSalary->gross_salary)->toBe(33000.0)
        ->and($newSalary->is_current)->toBeTrue();
    expect(EmployeeSalary::query()->where('employee_id', $employee->employee_id)
        ->whereDate('effective_to', '2026-09-30')->exists())->toBeTrue();
});

test('salary increment requester cannot approve their own request', function () {
    ['approver' => $approver, 'increment' => $increment] = payrollApprovalSetup();
    $increment->update(['requested_by' => $approver->id]);

    $this->actingAs($approver)
        ->from(route('payroll.increments.index'))
        ->post(route('payroll.increments.approve', $increment))
        ->assertRedirect(route('payroll.increments.index'))
        ->assertSessionHasErrors('increment');

    expect($increment->fresh()->status)->toBe('pending');
});
