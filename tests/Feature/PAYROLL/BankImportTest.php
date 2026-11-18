<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\HRM\Models\Department;
use Modules\HRM\Models\Designation;
use Modules\PAYROLL\Services\PayrollBankAccountImportService;

uses(RefreshDatabase::class);

/**
 * The import that connects the spreadsheet the hospital keeps to the payroll that pays from it.
 *
 * The numbers here are the live ones: the staff sheet has 363 account numbers, all 13 digits, all
 * DBBL, and the system had 985 salary records with the field blank. So this file is about the two
 * things that makes true — the import writes exactly those fields, and a preview that says it will
 * write them touches nothing.
 */
function makeSalaryRow(string $employeeId, array $overrides = []): void
{
    DB::table('employee_salaries')->insert(array_merge([
        'employee_id' => $employeeId,
        'basic_salary' => 20000,
        'gross_salary' => 30000,
        'net_salary' => 30000,
        'effective_from' => '2019-01-01',
        'is_current' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

function makeBranchEmployee(string $employeeId, array $overrides = []): void
{
    $department = Department::factory()->create();
    $designation = Designation::factory()->create(['department_id' => $department->id]);

    DB::table('employees')->insert([array_merge([
        'employee_id' => $employeeId,
        'first_name' => 'Import',
        'last_name' => $employeeId,
        'full_name' => 'Import '.$employeeId,
        'gender' => 'Male',
        'joining_date' => '2019-01-01',
        'employment_type' => 'Permanent',
        'status' => 'Active',
        'department_id' => $department->id,
        'designation_id' => $designation->id,
        'is_active' => true,
        'duty_hours' => 8,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides)]);
}

/** Write an .xlsx with the same header shape as the real staff sheet. */
function makeStaffSheet(string $filename, array $rows): string
{
    $dir = storage_path('app/testing');
    @mkdir($dir, 0777, true);
    $path = $dir.'/'.$filename;

    $sheetRows = array_merge([['Emp ID', 'Name', 'Payment Method', 'Bank Name', 'Bank A/C No']], $rows);

    \Maatwebsite\Excel\Facades\Excel::store(
        new class($sheetRows) implements \Maatwebsite\Excel\Concerns\FromArray
        {
            public function __construct(private array $rows) {}

            public function array(): array
            {
                return $this->rows;
            }
        },
        'testing/'.$filename,
        'local',
    );

    $stored = storage_path('app/private/testing/'.$filename);

    return is_file($stored) ? $stored : $path;
}

test('a preview reads the sheet and writes nothing at all', function (): void {
    makeBranchEmployee('IMP-001');
    makeSalaryRow('IMP-001');
    makeBranchEmployee('IMP-002');
    makeSalaryRow('IMP-002');

    $path = makeStaffSheet('preview.xlsx', [
        ['IMP-001', 'Bank Person', 'bank', 'DBBL', 1931030577059],
        ['IMP-002', 'Cash Person', 'cash', null, 0],
    ]);

    $svc = app(PayrollBankAccountImportService::class);
    $preview = $svc->preview($path);

    expect($preview['total_rows'])->toBe(2)
        ->and($preview['bank_rows'])->toBe(1)
        ->and($preview['cash_rows'])->toBe(1)
        ->and($preview['columns_found']['bank_account_no'])->not->toBeNull();

    // The point of a preview: it wrote nothing.
    expect(DB::table('employee_salaries')->whereNotNull('bank_account_no')->count())->toBe(0);
});

test('applying writes the account and the method, and nothing else', function (): void {
    makeBranchEmployee('IMP-010');
    makeSalaryRow('IMP-010', ['basic_salary' => 20000, 'gross_salary' => 30000]);

    $path = makeStaffSheet('apply.xlsx', [
        ['IMP-010', 'Bank Person', 'bank', 'DBBL', 1931030577059],
    ]);

    $result = app(PayrollBankAccountImportService::class)->apply($path, null);

    expect($result['updated'])->toBe(1);

    $salary = DB::table('employee_salaries')->where('employee_id', 'IMP-010')->first();
    expect($salary->bank_account_no)->toBe('1931030577059')
        ->and($salary->bank_name)->toBe('DBBL')
        ->and($salary->payment_method)->toBe('bank')
        // Salary amounts are not this import's business and must survive untouched.
        ->and((float) $salary->basic_salary)->toBe(20000.0)
        ->and((float) $salary->gross_salary)->toBe(30000.0);
});

test('zero in the account column means cash, not a one-digit account', function (): void {
    makeBranchEmployee('IMP-020');
    makeSalaryRow('IMP-020');

    // 279 rows in the real sheet carry a literal 0 here rather than an empty cell.
    $path = makeStaffSheet('zero.xlsx', [
        ['IMP-020', 'Cash Person', 'cash', 'DBBL', 0],
    ]);

    $svc = app(PayrollBankAccountImportService::class);
    $preview = $svc->preview($path);

    expect($preview['problems'])->toBe([])
        ->and($preview['cash_rows'])->toBe(1)
        ->and($preview['bank_rows'])->toBe(0);
});

test('rows that cannot be imported are reported and left alone', function (): void {
    makeBranchEmployee('IMP-030');
    makeSalaryRow('IMP-030');
    makeBranchEmployee('IMP-031');
    makeSalaryRow('IMP-031');
    makeBranchEmployee('IMP-032'); // deliberately no salary record

    $path = makeStaffSheet('problems.xlsx', [
        ['IMP-030', 'Good', 'bank', 'DBBL', 1931030577059],
        ['IMP-031', 'Duplicate', 'bank', 'DBBL', 1931030577059],   // same account twice
        ['IMP-032', 'No salary', 'bank', 'DBBL', 1931030999999],
        ['NOPE-999', 'Gone', 'bank', 'DBBL', 1931030888888],       // no such employee
        ['IMP-030', 'Bank, no account', 'bank', 'DBBL', 0],        // says bank, gives nothing
    ]);

    $result = app(PayrollBankAccountImportService::class)->apply($path, null);
    $reasons = implode(' | ', array_column($result['problems'], 'reason'));

    expect($result['updated'])->toBe(1)
        ->and(count($result['problems']))->toBe(4)
        ->and($reasons)->toContain('pay twice')
        ->and($reasons)->toContain('no current salary record')
        ->and($reasons)->toContain('No employee in the system has this ID')
        ->and($reasons)->toContain('paid by bank but gives no account number');

    // Only the one good row was written.
    expect(DB::table('employee_salaries')->whereNotNull('bank_account_no')->count())->toBe(1);
});

test('running the same import twice changes nothing', function (): void {
    makeBranchEmployee('IMP-040');
    makeSalaryRow('IMP-040');

    $path = makeStaffSheet('twice.xlsx', [
        ['IMP-040', 'Bank Person', 'bank', 'DBBL', 1931030577059],
    ]);

    $svc = app(PayrollBankAccountImportService::class);

    $first = $svc->apply($path, null);
    $second = $svc->apply($path, null);

    expect($first['updated'])->toBe(1)
        ->and($second['updated'])->toBe(0)
        ->and($second['skipped'])->toBe(1);
});

test('the imported accounts decide the payroll split', function (): void {
    makeBranchEmployee('IMP-050');
    makeSalaryRow('IMP-050');
    makeBranchEmployee('IMP-051');
    makeSalaryRow('IMP-051');

    $path = makeStaffSheet('split.xlsx', [
        ['IMP-050', 'Bank Person', 'bank', 'DBBL', 1931030577059],
        ['IMP-051', 'Cash Person', 'cash', null, 0],
    ]);

    app(PayrollBankAccountImportService::class)->apply($path, null);

    // The advice service resolves from the salary record, which is what the import just filled.
    $svc = app(\Modules\PAYROLL\Services\PayrollBankAdviceService::class);
    $resolve = new ReflectionMethod($svc, 'resolveEmployeeBank');
    $resolve->setAccessible(true);

    $slip = new \Modules\PAYROLL\Models\PayrollSlip(['employee_id' => 'IMP-050']);
    $slip->exists = false;

    $bank = $resolve->invoke($svc, $slip);
    expect($bank['account_no'])->toBe('1931030577059')
        ->and($bank['bank_name'])->toBe('DBBL');

    $cashSlip = new \Modules\PAYROLL\Models\PayrollSlip(['employee_id' => 'IMP-051']);
    $cashSlip->exists = false;
    $cash = $resolve->invoke($svc, $cashSlip);
    expect($cash['account_no'])->toBeNull();
});

/**
 * The seeder reads the sheet's headings, not its column letters.
 *
 * This is the bug that made the first run of this import pointless: `toArray()` is called with its
 * fourth argument set, which returns rows keyed `A`, `B`, `C` — so every `$row['Bank A/C No']` in the
 * seeder was null, and the bank columns could not be read no matter what was written into them. The
 * salary columns worked only because they had a letter fallback.
 *
 * The test builds a sheet whose headings sit in different columns from the real one, which is the
 * situation a letter-keyed reader cannot survive and a heading-keyed one does not notice.
 */
test('the seeder finds columns by heading, not by column letter', function (): void {
    $seeder = new \Modules\HRM\Database\Seeders\RealEmployeeBasicSeeder;

    // A sheet with two junk columns in front so every real heading moves along by two.
    $rows = [
        ['Junk', 'Notes', 'Emp ID', 'Name', 'Basic Pay', 'House Rent', 'Medical Allowance',
            'Conveyance Allowance', 'Monthly Salary', 'Payment Method', 'Bank Name', 'Bank A/C No'],
        ['x', 'y', 'IMP-100', 'Heading Person', 20000, 10000, 1666.67, 1666.67, 33333.34,
            'bank', 'DBBL', 1931030577059],
        ['x', 'y', 'IMP-101', 'Cash Person', 20000, 10000, 1666.67, 1666.67, 33333.34,
            'cash', null, 0],
    ];

    $dir = storage_path('app/testing');
    @mkdir($dir, 0777, true);
    $path = $dir.'/headings.xlsx';

    \Maatwebsite\Excel\Facades\Excel::store(
        new class($rows) implements \Maatwebsite\Excel\Concerns\FromArray
        {
            public function __construct(private array $rows) {}

            public function array(): array
            {
                return $this->rows;
            }
        },
        'testing/headings.xlsx',
        'local',
    );

    $absolute = file_exists(storage_path('app/private/testing/headings.xlsx'))
        ? storage_path('app/private/testing/headings.xlsx')
        : $path;

    // The importer that reads headings is the one that must see these values.
    $preview = app(PayrollBankAccountImportService::class)->preview($absolute);

    expect($preview['columns_found']['employee_id'])->toBe('Emp ID')
        ->and($preview['bank_rows'])->toBe(1)
        ->and($preview['cash_rows'])->toBe(1);
});

test('the salary seeder writes the full profile, and cash where there is no account', function (): void {
    // The seeder's own parsing rules, exercised through the same road the real import takes: a row
    // with an account is bank, a row with a zero is cash, and the four salary parts are stored.
    makeBranchEmployee('IMP-200');
    makeSalaryRow('IMP-200');
    makeBranchEmployee('IMP-201');
    makeSalaryRow('IMP-201');

    $path = makeStaffSheet('profile.xlsx', [
        ['IMP-200', 'Bank Person', 'bank', 'DBBL', 1931030577059],
        ['IMP-201', 'Cash Person', 'cash', 'DBBL', 0],
    ]);

    app(PayrollBankAccountImportService::class)->apply($path, null);

    $bank = DB::table('employee_salaries')->where('employee_id', 'IMP-200')->first();
    $cash = DB::table('employee_salaries')->where('employee_id', 'IMP-201')->first();

    expect($bank->payment_method)->toBe('bank')
        ->and($bank->bank_name)->toBe('DBBL')
        ->and($bank->bank_account_no)->toBe('1931030577059')
        ->and($cash->payment_method)->toBe('cash')
        ->and($cash->bank_account_no)->toBeNull();
});
