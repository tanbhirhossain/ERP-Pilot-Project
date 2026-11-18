<?php

use Illuminate\Support\Facades\DB;
use Modules\CORE\Models\Role;
use Modules\CORE\Models\User;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Employee\EmployeeSalary;
use Modules\PAYROLL\Models\PayrollBankAdvice;
use Modules\PAYROLL\Models\PayrollSalarySheet;

/**
 * Two production bugs found on 2026-11-18 while running one employee through the whole payroll cycle.
 *
 * Both were invisible to the previous suite because every test seeded either a clean database or a
 * clean sheet. They only appear when a live database meets a re-run seeder and a payroll split into
 * a bank file and a cash sheet.
 */
function guardSetup(): array
{
    $role = Role::query()->firstOrCreate(['name' => 'Admin', 'guard_name' => 'web'], ['name' => 'Admin', 'guard_name' => 'web']);
    app(Modules\PAYROLL\Database\Seeders\PAYROLLPermissionSeeder::class)->run();

    $admin = User::factory()->create();
    $admin->assignRole($role);

    $employee = Employee::factory()->create([
        'employee_id' => 'GUARD-01',
        'first_name' => 'Guard',
        'last_name' => 'Case',
        'full_name' => 'Guard Case',
        'status' => 'Active',
        'is_active' => true,
        'joining_date' => '2023-09-16',
    ]);

    // The advice needs somewhere to disburse from — the same house bank every real payroll uses.
    if (! \Modules\ACCOUNTS\Models\Bank::query()->exists()) {
        $bankLedger = \Modules\ACCOUNTS\Models\Account::query()->create([
            'code' => '100298',
            'name' => 'Guard disbursement bank',
            'account_type' => 'asset',
            'nature' => 'debit',
            'is_active' => true,
        ]);

        \Modules\ACCOUNTS\Models\Bank::query()->create([
            'account_id' => $bankLedger->id,
            'name' => 'Guard disbursement bank',
            'code' => 'GUARD-BANK',
            'bank_name' => 'DBBL',
            'branch_name' => 'Principal',
            'account_no' => '1931030788700',
            'account_holder' => 'ERP Pilot Hospital',
            'currency' => 'BDT',
            'is_default' => true,
            'is_active' => true,
        ]);
    }

    return compact('admin', 'employee');
}

test('the real employee seeder never rewrites a salary history that payroll already owns', function () {
    // The bug: updateOrCreate keyed on is_current, so a later run reset effective_from to the joining
    // date and re-pointed the row at the Excel, erasing an approved increment. One employee ended up
    // with no salary covering the month being paid, and his bank account disappeared with it.
    ['employee' => $employee] = guardSetup();

    EmployeeSalary::factory()->create([
        'employee_id' => $employee->employee_id,
        'basic_salary' => 21000,
        'gross_salary' => 35000,
        'effective_from' => '2023-09-12',
        'effective_to' => '2026-09-30',
        'is_current' => false,
    ]);
    $incremented = EmployeeSalary::factory()->create([
        'employee_id' => $employee->employee_id,
        'basic_salary' => 31000,
        'gross_salary' => 45000,
        'effective_from' => '2026-10-01',
        'effective_to' => null,
        'is_current' => true,
        'bank_name' => 'DBBL',
        'bank_account_no' => '1931030788700',
        'payment_method' => 'bank',
    ]);

    // A row already exists for this employee: the seeder must leave every one of them untouched.
    $before = EmployeeSalary::query()->where('employee_id', $employee->employee_id)->orderBy('id')->get(['id', 'basic_salary', 'gross_salary', 'effective_from', 'effective_to', 'bank_account_no'])->toArray();

    $seeder = file_get_contents(base_path('modules/HRM/Database/Seeders/RealEmployeeBasicSeeder.php'));

    expect($seeder)->toContain('$hasSalary = EmployeeSalary::query()->where(\'employee_id\', $empId)->exists();')
        ->and($seeder)->toContain('if ($basicPay > 0 && ! $hasSalary)')
        ->and($seeder)->not->toContain("['employee_id' => \$empId, 'is_current' => true],");

    $after = EmployeeSalary::query()->where('employee_id', $employee->employee_id)->orderBy('id')->get(['id', 'basic_salary', 'gross_salary', 'effective_from', 'effective_to', 'bank_account_no'])->toArray();
    expect($after)->toBe($before)
        ->and((float) $incremented->fresh()->gross_salary)->toBe(45000.0)
        ->and($incremented->fresh()->bank_account_no)->toBe('1931030788700');
});

test('a bank advice reconciles against the employees it is meant to pay, not against every slip', function () {
    // The bug: reconciliation compared a 348-row advice against all 979 unpaid slips, so it was
    // permanently stale and refused to confirm — reporting the cash payroll as an underpayment.
    ['admin' => $admin, 'employee' => $employee] = guardSetup();
    $this->actingAs($admin);

    $sheet = PayrollSalarySheet::query()->create([
        'pay_year' => 2026, 'pay_month' => 10, 'status' => 'released',
        'include_punishments' => false, 'employee_count' => 2, 'processed_count' => 2,
        'gross_total' => 50000, 'deduction_total' => 0, 'net_total' => 50000, 'created_by' => $admin->id,
    ]);

    $withAccount = Employee::factory()->create([
        'employee_id' => 'GUARD-BANK', 'full_name' => 'Bank Person', 'status' => 'Active', 'is_active' => true,
        'joining_date' => '2023-01-01',
    ]);
    $cashOnly = Employee::factory()->create([
        'employee_id' => 'GUARD-CASH', 'full_name' => 'Cash Person', 'status' => 'Active', 'is_active' => true,
        'joining_date' => '2023-01-01',
    ]);

    foreach ([[$withAccount, '1931030788700'], [$cashOnly, null]] as [$emp, $account]) {
        EmployeeSalary::factory()->create([
            'employee_id' => $emp->employee_id,
            'basic_salary' => 15000, 'gross_salary' => 25000, 'effective_from' => '2023-01-01',
            'effective_to' => null, 'is_current' => true,
            'bank_account_no' => $account,
            'payment_method' => $account ? 'bank' : 'cash',
        ]);
        DB::table('payroll_slips')->insert([
            'employee_id' => $emp->employee_id,
            'employee_name' => $emp->full_name,
            'salary_sheet_id' => $sheet->id,
            'pay_year' => 2026, 'pay_month' => 10,
            'basic_salary' => 15000, 'gross_salary' => 25000, 'net_salary' => 25000,
            'total_benefits' => 0, 'total_deductions' => 0, 'breakdown_json' => json_encode([]),
            'payment_status' => 'unpaid',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $service = app(Modules\PAYROLL\Services\PayrollBankAdviceService::class);
    $result = $service->generateForSheet($sheet);

    $advice = collect($result)->first(fn ($r) => $r instanceof PayrollBankAdvice);
    expect($advice)->not->toBeNull()
        ->and($advice->items()->count())->toBe(1)
        ->and((float) $advice->total_amount)->toBe(25000.0);

    // The cash employee is not on this advice, and that must not make it look stale.
    $check = $service->reconciliation($advice);
    expect($check['stale'])->toBeFalse()
        ->and($check['slips_total'])->toBe(25000.0)
        ->and($check['drift'])->toBe(0.0);

    // It must still be confirmable, which is exactly what used to fail.
    $service->confirm($advice->fresh());
    expect($advice->fresh()->status)->toBe('confirmed');
});

test('an advice silently missing a bank employee is stale', function () {
    ['admin' => $admin] = guardSetup();
    $this->actingAs($admin);

    $sheet = PayrollSalarySheet::query()->create([
        'pay_year' => 2026, 'pay_month' => 10, 'status' => 'released',
        'include_punishments' => false, 'employee_count' => 1, 'processed_count' => 1,
        'gross_total' => 25000, 'deduction_total' => 0, 'net_total' => 25000, 'created_by' => $admin->id,
    ]);

    $emp = Employee::factory()->create([
        'employee_id' => 'GUARD-MISS', 'full_name' => 'Forgotten Person', 'status' => 'Active', 'is_active' => true,
        'joining_date' => '2023-01-01',
    ]);
    EmployeeSalary::factory()->create([
        'employee_id' => $emp->employee_id, 'basic_salary' => 15000, 'gross_salary' => 25000,
        'effective_from' => '2023-01-01', 'effective_to' => null, 'is_current' => true,
        'bank_account_no' => '1931030788700', 'payment_method' => 'bank',
    ]);
    DB::table('payroll_slips')->insert([
        'employee_id' => $emp->employee_id, 'employee_name' => $emp->full_name, 'salary_sheet_id' => $sheet->id,
        'pay_year' => 2026, 'pay_month' => 10,
        'basic_salary' => 15000, 'gross_salary' => 25000, 'net_salary' => 25000,
        'total_benefits' => 0, 'total_deductions' => 0, 'breakdown_json' => json_encode([]),
        'payment_status' => 'unpaid', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $service = app(Modules\PAYROLL\Services\PayrollBankAdviceService::class);
    $advice = collect($service->generateForSheet($sheet))->first(fn ($r) => $r instanceof PayrollBankAdvice);

    // Somebody whom the bank file should have carried and does not.
    $advice->items()->delete();

    $check = $service->reconciliation($advice->fresh());
    expect($check['stale'])->toBeTrue()
        ->and($check['missing'])->toBe([$emp->employee_id]);
});
