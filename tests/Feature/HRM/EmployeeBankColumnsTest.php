<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\CORE\Models\User;
use Modules\HRM\Models\Department;
use Modules\HRM\Models\Designation;

uses(RefreshDatabase::class);

/**
 * How each employee is paid must be visible where somebody looks for it.
 *
 * The directory listed 998 names and said nothing about payment, so "who is still not on the bank
 * list?" could only be answered by opening employee pages one at a time. The answer lived in the
 * salary record the whole time — this is about carrying it to the list, filtering on it, and keeping
 * the three states distinct: bank, cash, and no salary record at all.
 */
function ebcEmployee(string $id, array $salary = [], bool $active = true): void
{
    $department = Department::factory()->create();
    $designation = Designation::factory()->create(['department_id' => $department->id]);

    DB::table('employees')->insert([[
        'employee_id' => $id,
        'first_name' => 'Ebc',
        'last_name' => $id,
        'full_name' => 'Ebc '.$id,
        'gender' => 'Male',
        'joining_date' => '2019-01-01',
        'employment_type' => 'Permanent',
        'status' => 'Active',
        'department_id' => $department->id,
        'designation_id' => $designation->id,
        'is_active' => $active,
        'duty_hours' => 8,
        'created_at' => now(),
        'updated_at' => now(),
    ]]);

    if ($salary !== []) {
        DB::table('employee_salaries')->insert(array_merge([
            'employee_id' => $id,
            'basic_salary' => 20000,
            'gross_salary' => 30000,
            'net_salary' => 30000,
            'effective_from' => '2019-01-01',
            'is_current' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $salary));
    }
}

beforeEach(function (): void {
    // The directory is gated on `hrm.employees.view`; the real super admin carries it, a bare factory
    // user does not, and a 403 is not the thing these tests are about.
    $this->seed(SuperAdminSeeder::class);
    $this->actingAs(User::where('email', config('core.super_admin.email', 'admin@example.com'))->firstOrFail());
});

test('the directory carries the payment method and the account, straight from the salary record', function (): void {
    ebcEmployee('EBC-001', ['payment_method' => 'bank', 'bank_name' => 'DBBL', 'bank_account_no' => '1931030577059']);
    ebcEmployee('EBC-002', ['payment_method' => 'cash']);

    $page = $this->get('/hrm/employees')->assertOk();

    $rows = collect($page->viewData('page')['props']['employees']['data'] ?? []);
    expect($rows)->not->toBeEmpty();

    $bank = $rows->firstWhere('employee_id', 'EBC-001');
    $cash = $rows->firstWhere('employee_id', 'EBC-002');

    expect($bank['payment_method'])->toBe('bank')
        ->and($bank['bank_name'])->toBe('DBBL')
        ->and($bank['bank_account_no'])->toBe('1931030577059')
        ->and($cash['payment_method'])->toBe('cash')
        ->and($cash['bank_account_no'])->toBeNull();
});

test('an employee with no salary record says so instead of pretending to be cash', function (): void {
    // 14 doctors are in exactly this state: an employee row, no salary row. Calling them "cash" would
    // hide the thing that actually needs fixing — payroll has no figure to pay them from.
    ebcEmployee('EBC-003');

    $page = $this->get('/hrm/employees')->assertOk();
    $row = collect($page->viewData('page')['props']['employees']['data'] ?? [])->firstWhere('employee_id', 'EBC-003');

    expect($row['payment_method'])->toBeNull()
        ->and($row['bank_account_no'])->toBeNull();
});

test('the directory filters by how people are paid, including the ones with no salary', function (): void {
    ebcEmployee('EBC-010', ['payment_method' => 'bank', 'bank_account_no' => '1931030577010']);
    ebcEmployee('EBC-011', ['payment_method' => 'cash']);
    ebcEmployee('EBC-012');

    $bank = $this->get('/hrm/employees?filters[payment_method]=bank')->assertOk();
    $cash = $this->get('/hrm/employees?filters[payment_method]=cash')->assertOk();
    $none = $this->get('/hrm/employees?filters[payment_method]=none')->assertOk();

    $ids = fn ($r) => collect($r->viewData('page')['props']['employees']['data'] ?? [])->pluck('employee_id')->all();

    expect($ids($bank))->toBe(['EBC-010'])
        ->and($ids($cash))->toBe(['EBC-011'])
        ->and($ids($none))->toBe(['EBC-012']);
});

test('the account is not looked up once per row', function (): void {
    foreach (range(1, 12) as $i) {
        ebcEmployee('EBC-1'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), [
            'payment_method' => 'bank',
            'bank_name' => 'DBBL',
            'bank_account_no' => '19310305770'.$i,
        ]);
    }

    DB::enableQueryLog();
    $this->get('/hrm/employees')->assertOk();
    $queries = collect(DB::getQueryLog())->pluck('query');

    // The list eager-loads the salary rows. Without that, this page costs one extra query per row
    // for a column that is mostly "—".
    $salaryQueries = $queries->filter(fn (string $q) => str_contains($q, 'employee_salaries'));
    expect($salaryQueries->count())->toBeLessThanOrEqual(2, 'the salary rows are being fetched per row rather than once');
});
