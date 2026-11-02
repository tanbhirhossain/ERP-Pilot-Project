<?php

use Modules\CORE\Models\Role;
use Modules\CORE\Models\User;
use Modules\HRM\Models\Employee\Employee;
use Modules\PAYROLL\Actions\ApprovePayrollLoanAction;
use Modules\PAYROLL\Models\PayrollLoan;
use Modules\PAYROLL\Models\PayrollLoanInstallment;

it('allows a Super Admin to approve a configured payroll loan approval stage', function (): void {
    $employee = Employee::factory()->create();
    $requester = User::factory()->create();
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole(Role::findOrCreate('Super Admin', 'web'));

    $loan = PayrollLoan::query()->create([
        'employee_id' => $employee->employee_id,
        'principal_amount' => 12000,
        'interest_rate' => 0,
        'installment_count' => 12,
        'installment_amount' => 1000,
        'first_due_date' => now()->toDateString(),
        'status' => 'pending',
        'approval_steps' => [['label' => 'Payroll', 'role' => 'Administrator']],
        'current_approval_step' => 0,
        'requested_by' => $requester->id,
        'purpose' => 'Test Super Admin approval stage.',
    ]);

    $approved = app(ApprovePayrollLoanAction::class)->approve($loan, $superAdmin->id);

    expect($approved->status)->toBe('active')
        ->and($approved->current_approval_step)->toBe(1)
        ->and(PayrollLoanInstallment::query()->where('payroll_loan_id', $loan->id)->count())->toBe(12);
});
