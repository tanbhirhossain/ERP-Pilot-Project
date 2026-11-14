<?php

/**
 * Phase 1 — F9 employee self-service.
 *
 * The whole point of ESS is row-level scoping: a signed-in user sees only their
 * own employee's payslips, leave and attendance. The employee id comes from the
 * session (employees.user_id), never the request, so a tampered payslip id must
 * 404 rather than leak a colleague's slip. A user with no linked employee gets an
 * actionable "ask HR" page, not a 403.
 *
 * Helper names are prefixed `p1ess*`.
 */

use Modules\CORE\Models\User;
use Modules\HRM\Models\Employee\Employee;
use Modules\PAYROLL\Models\PayrollSalarySheet;
use Modules\PAYROLL\Models\PayrollSlip;

function p1essUserWithEmployee(string $employeeCode): array
{
    $user = User::create([
        'name' => 'ESS '.$employeeCode,
        'email' => 'ess-'.strtolower($employeeCode).'@example.test',
        'password' => bcrypt('secret-password'),
        'email_verified_at' => now(),
    ]);

    $employee = Employee::create([
        'employee_id' => $employeeCode,
        'user_id' => $user->id,
        'first_name' => 'Ess',
        'last_name' => $employeeCode,
        'full_name' => 'Ess '.$employeeCode,
        'email' => $user->email,
        'gender' => 'Male',
        'date_of_birth' => '1990-01-01',
        'joining_date' => '2020-01-01',
        'employment_type' => 'Permanent',
        'status' => 'Active',
        'is_active' => true,
    ]);

    return [$user, $employee];
}

function p1essSlip(Employee $employee, int $net): PayrollSlip
{
    $sheet = PayrollSalarySheet::create([
        'pay_year' => (int) now()->year,
        'pay_month' => random_int(1, 12),
        'status' => 'released',
    ]);

    return PayrollSlip::create([
        'salary_sheet_id' => $sheet->id,
        'employee_id' => $employee->employee_id,
        'employee_name' => $employee->full_name,
        'pay_year' => $sheet->pay_year,
        'pay_month' => $sheet->pay_month,
        'basic_salary' => $net,
        'gross_salary' => $net,
        'total_benefits' => 0,
        'total_deductions' => 0,
        'net_salary' => $net,
        'payment_status' => 'paid',
        'breakdown_json' => ['version' => 1, 'benefits' => [], 'recurring_deductions' => []],
    ]);
}

test('a linked employee sees their own dashboard', function () {
    [$user, $employee] = p1essUserWithEmployee('P1ESS-A');
    p1essSlip($employee, 50000);

    $this->actingAs($user)
        ->get(route('hrm.my.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('HRM::My/Dashboard')
            ->where('employee.employee_id', 'P1ESS-A')
            ->has('slips', 1));
});

test('a user with no linked employee gets the actionable not-linked page', function () {
    $user = User::create([
        'name' => 'Unlinked',
        'email' => 'unlinked@example.test',
        'password' => bcrypt('secret-password'),
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('hrm.my.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('HRM::My/NotLinked'));
});

test('the payslip list only contains the signed-in employee\'s slips', function () {
    [$userA, $employeeA] = p1essUserWithEmployee('P1ESS-LA');
    [, $employeeB] = p1essUserWithEmployee('P1ESS-LB');
    p1essSlip($employeeA, 40000);
    p1essSlip($employeeB, 90000);

    $this->actingAs($userA)
        ->get(route('hrm.my.payslips'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('HRM::My/Payslips')
            ->has('slips.data', 1)
            ->where('slips.data.0.employee_id', 'P1ESS-LA'));
});

test('a tampered payslip id returns 404, never a colleague\'s slip', function () {
    [$userA] = p1essUserWithEmployee('P1ESS-TA');
    [, $employeeB] = p1essUserWithEmployee('P1ESS-TB');
    $slipB = p1essSlip($employeeB, 90000);

    $this->actingAs($userA)
        ->get(route('hrm.my.payslip', ['id' => $slipB->id]))
        ->assertNotFound();
});

test('an employee can open their own payslip', function () {
    [$userA, $employeeA] = p1essUserWithEmployee('P1ESS-Own');
    $slip = p1essSlip($employeeA, 55000);

    $this->actingAs($userA)
        ->get(route('hrm.my.payslip', ['id' => $slip->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('HRM::My/Payslip')
            ->where('slip.id', $slip->id));
});

test('ESS requires authentication', function () {
    $this->get(route('hrm.my.dashboard'))->assertRedirect();
});
