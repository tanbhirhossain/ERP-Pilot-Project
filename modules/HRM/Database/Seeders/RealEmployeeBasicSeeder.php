<?php

namespace Modules\HRM\Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\HRM\Models\Department;
use Modules\HRM\Models\Designation;
use Modules\HRM\Models\DeviceUser;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Employee\EmployeeProfile;
use Modules\HRM\Models\Employee\EmployeeSalary;
use Modules\HRM\Models\Organization;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/**
 * RealEmployeeBasicSeeder
 * Minimal import: only core employee fields + biometric mapping.
 *
 * Uses same Excel: http://erp.amzhospitalbd.com/check/employees.xlsx
 * - Emp ID -> employees.employee_id
 * - Biometric ID -> device_users.device_user_id (global)
 *
 * Imports only: name, designation, department, nid, blood, status, phone, address, dob, joining_date, employment_type, basic salary
 * Skips: file_no, card_issue_date, job_type, joining_salary, overtime/bonus, payment_criteria, bank, conveyance details, etc.
 *
 * Run: php artisan db:seed --class="Modules\HRM\Database\Seeders\RealEmployeeBasicSeeder"
 */
class RealEmployeeBasicSeeder extends Seeder
{
    public function run(): void
    {
        $file = $this->resolveExcelFile();
        if (! $file) {
            $this->command?->error('Excel not found at database/seeders/data/employees.xlsx');

            return;
        }

        $this->command?->info("Loading: $file");
        $spreadsheet = IOFactory::load($file);

        // Departments first (from sheet 2)
        $this->importDepartments($spreadsheet);

        // Employees minimal (from sheet 0)
        $this->importEmployeesMinimal($spreadsheet);

        $this->command?->info('Basic import completed.');
    }

    private function resolveExcelFile(): ?string
    {
        $candidates = [
            base_path('database/seeders/data/employees.xlsx'),
            storage_path('app/employees.xlsx'),
            '/tmp/employees.xlsx',
        ];
        foreach ($candidates as $p) {
            if ($p && file_exists($p)) {
                return $p;
            }
        }

        return null;
    }

    private function importDepartments($spreadsheet): void
    {
        $sheet = $spreadsheet->getSheetByName('Worksheet 2') ?? $spreadsheet->getSheet(2);
        $rows = $sheet->toArray(null, true, true, true);
        array_shift($rows); // header
        $seen = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row['C'] ?? ''));
            if ($name === '' || isset($seen[strtolower($name)])) {
                continue;
            }
            $seen[strtolower($name)] = true;
            Department::firstOrCreate(
                ['name' => $name],
                ['code' => strtoupper(Str::slug($name, '_')), 'slug' => Str::slug($name), 'is_active' => true]
            );
        }
        $this->command?->info('Departments: '.count($seen).' unique');
    }

    private function importEmployeesMinimal($spreadsheet): void
    {
        $sheet = $spreadsheet->getSheetByName('Worksheet') ?? $spreadsheet->getSheet(0);
        $rows = $sheet->toArray(null, true, true, true);

        // Rows are keyed by column letter, not by heading: toArray()'s fourth argument selects
        // letter-keyed rows, so every `$row['Bank A/C No']` in this file was always null and the bank
        // columns could not have been read however they were written. The headings are mapped once,
        // here, and the import addresses columns by name from then on.
        $headerRow = array_shift($rows);
        $col = [];
        foreach ($headerRow as $letter => $heading) {
            $key = mb_strtolower(trim((string) $heading));
            if ($key !== '' && ! isset($col[$key])) {
                $col[$key] = $letter;
            }
        }

        // Reads a column by heading, falling back to the letter the old code used for that field.
        $cell = function (array $row, string $heading, string $fallbackLetter = '') use ($col) {
            $letter = $col[mb_strtolower($heading)] ?? ($fallbackLetter !== '' ? $fallbackLetter : null);

            return $letter === null ? null : ($row[$letter] ?? null);
        };

        $missing = array_values(array_filter(
            ['Basic Pay', 'House Rent', 'Medical Allowance', 'Conveyance Allowance', 'Monthly Salary', 'Payment Method', 'Bank Name', 'Bank A/C No'],
            fn (string $h) => ! isset($col[mb_strtolower($h)]),
        ));
        if ($missing !== []) {
            $this->command?->warn('These columns were not found in the sheet: '.implode(', ', $missing).' — those fields will be left empty.');
        }

        $this->command?->info('Importing '.count($rows).' employees (basic only)');

        $organization = Organization::first() ?? Organization::create([
            'name' => 'AMZ Hospital LTD',
            'address' => 'Dhaka, Bangladesh',
        ]);

        $deptCache = [];
        $desigCache = [];
        $bar = $this->command?->getOutput()->createProgressBar(count($rows));
        $bar?->start();

        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($rows as $row) {
            try {
                $empId = trim((string) ($row['A'] ?? ''));
                if ($empId === '') {
                    $stats['skipped']++;
                    $bar?->advance();

                    continue;
                }

                $biometricId = $row['B'] ?? null;
                $fullName = trim((string) ($row['D'] ?? 'Unknown'));
                $desigName = trim((string) ($row['E'] ?? 'Staff'));
                $deptName = trim((string) ($row['G'] ?? 'General'));
                $nid = $row['J'] ? trim((string) $row['J']) : null;
                $bloodRaw = $row['K'] ?? null;
                $statusRaw = strtolower(trim((string) ($row['L'] ?? 'active')));
                $emergency = $row['M'] ? $this->cleanPhone($row['M']) : null;
                $phone = $row['N'] ? $this->cleanPhone($row['N']) : null;
                $address = $row['O'] ? trim((string) $row['O']) : null;
                $dob = $this->parseDate($row['P'] ?? null);
                $joiningDate = $this->parseDate($row['Q'] ?? null);
                $empTypeRaw = strtolower(trim((string) ($row['T'] ?? 'fulltime')));
                // The whole salary profile, not just the two numbers this seeder used to take.
                //
                // The sheet has carried Basic Pay, House Rent, Medical Allowance, Conveyance Allowance
                // and Monthly Salary on every row since the beginning, and the seeder read two of the
                // five — so every salary record in the system had a basic and a gross and nothing in
                // between. The parts add up to the whole on all 991 paid rows, in exactly the ratio
                // 60 / 30 / 5 / 5, so nothing here is being inferred.
                $basicPay = (float) ($cell($row, 'Basic Pay', 'Z') ?? 0);
                $houseRent = (float) ($cell($row, 'House Rent', 'AA') ?? 0);
                $medicalAllowance = (float) ($cell($row, 'Medical Allowance', 'AB') ?? 0);
                $conveyanceAllowance = (float) ($cell($row, 'Conveyance Allowance', 'AC') ?? 0);
                $monthly = (float) ($cell($row, 'Monthly Salary', 'AD') ?? 0);
                if ($monthly <= 0) {
                    $monthly = $basicPay + $houseRent + $medicalAllowance + $conveyanceAllowance;
                }

                // How the money reaches them. An account number is what makes a BEFTN advice possible;
                // everyone else is paid in hand and belongs on the cash sheet.
                $bankAccountNo = $this->cleanAccount((string) ($cell($row, 'Bank A/C No', 'AG') ?? ''));
                $bankName = trim((string) ($cell($row, 'Bank Name', 'AF') ?? '')) ?: null;
                $sheetMethod = mb_strtolower(trim((string) ($cell($row, 'Payment Method', 'AE') ?? '')));
                // The sheet's own column decides when it says something definite; otherwise an account
                // number means the bank and its absence means the counter.
                $paymentMethod = in_array($sheetMethod, ['bank', 'cash'], true)
                    ? $sheetMethod
                    : ($bankAccountNo !== null ? 'bank' : 'cash');

                // A row that says bank but carries no account cannot be paid by BEFTN. Rather than
                // leaving it in a bank file with a blank beneficiary, it is paid in cash and the
                // payroll's own advice split will show it on the cash sheet.
                if ($bankAccountNo === null) {
                    $paymentMethod = 'cash';
                    $bankName = null;
                }

                // Normalize
                $blood = $this->normalizeBloodGroup($bloodRaw);
                $statusMap = ['active' => 'Active', 'resigned' => 'Resigned'];
                $status = $statusMap[$statusRaw] ?? 'Active';
                $empTypeMap = ['fulltime' => 'Permanent', 'parttime' => 'Part-time', 'contract' => 'Contract'];
                $employmentType = $empTypeMap[$empTypeRaw] ?? 'Permanent';

                // Department (cache)
                if (! isset($deptCache[$deptName])) {
                    $dept = Department::firstOrCreate(
                        ['name' => $deptName],
                        ['code' => strtoupper(Str::slug($deptName, '_')), 'slug' => Str::slug($deptName), 'is_active' => true]
                    );
                    $deptCache[$deptName] = $dept->id;
                }
                $deptId = $deptCache[$deptName];

                // Designation (cache)
                if (! isset($desigCache[$desigName])) {
                    $desig = Designation::firstOrCreate(
                        ['name' => $desigName],
                        ['code' => strtoupper(Str::slug($desigName, '_')), 'slug' => Str::slug($desigName), 'is_active' => true]
                    );
                    $desigCache[$desigName] = $desig->id;
                }
                $desigId = $desigCache[$desigName];

                [$firstName, $lastName] = $this->splitName($fullName);

                // Employee - minimal fields only (extra migration columns stay null)
                // Professional: biometric_id is canonical ZK UserID stored directly on employees
                $bioStrForEmp = ($biometricId !== null && $biometricId !== '') ? trim((string) $biometricId) : null;
                // Handle duplicate biometric_id (unique constraint) - if already taken by another employee, skip setting it
                if ($bioStrForEmp && Employee::where('biometric_id', $bioStrForEmp)->where('employee_id', '!=', $empId)->exists()) {
                    Log::warning("Duplicate biometric_id $bioStrForEmp for EmpID $empId - already assigned to another employee, skipping biometric_id for this employee. Manual fix required.");
                    $bioStrForEmp = null;
                }

                $employee = Employee::updateOrCreate(
                    ['employee_id' => $empId],
                    [
                        'biometric_id' => $bioStrForEmp,
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'full_name' => $fullName,
                        'phone' => $phone,
                        'nid' => $nid ? preg_replace('/[^0-9A-Za-z\-]/', '', $nid) : null,
                        'date_of_birth' => $dob,
                        'joining_date' => $joiningDate,
                        'organization_id' => $organization->id,
                        'department_id' => $deptId,
                        'designation_id' => $desigId,
                        'employment_type' => $employmentType,
                        'status' => $status,
                        'blood_group' => $blood,
                        'address' => $address,
                        'is_active' => $statusRaw === 'active',
                    ]
                );
                $employee->wasRecentlyCreated ? $stats['created']++ : $stats['updated']++;

                // Minimal profile: just emergency + address
                if ($emergency || $address) {
                    EmployeeProfile::updateOrCreate(
                        ['employee_id' => $empId],
                        [
                            'present_address' => $address,
                            'permanent_address' => $address,
                            'emergency_contact_phone' => $emergency,
                            'nationality' => 'Bangladeshi',
                        ]
                    );
                }

                // Full salary profile. Still only when there is money to record: 14 doctors have a row
                // in this sheet with every salary column at 0.0, and inventing a salary for them would
                // be worse than the gap it papers over. Those 14 stay without a salary record, which is
                // exactly what the payroll's release desk reports by name.
                // Only ever *create* the first salary row for an employee; never rewrite an existing one.
                //
                // This used to be updateOrCreate keyed on is_current, which quietly made the seeder the
                // last word on pay. Re-running it over live data rewrote a row that an approved salary
                // increment had created, resetting effective_from to the joining date and closing the
                // row on 2026-09-29 — so the employee had no salary covering the month being paid, and
                // his bank account vanished with it. Seed data must never overwrite payroll history:
                // if a row exists, increments and HRM edits own it, and the seeder leaves it alone.
                $hasSalary = EmployeeSalary::query()->where('employee_id', $empId)->exists();

                if ($basicPay > 0 && ! $hasSalary) {
                    EmployeeSalary::create([
                        'employee_id' => $empId,
                        'basic_salary' => $basicPay,
                        'house_rent' => $houseRent,
                        'medical_allowance' => $medicalAllowance,
                        'conveyance_allowance' => $conveyanceAllowance,
                        'gross_salary' => $monthly,
                        'net_salary' => $monthly,
                        'payment_method' => $paymentMethod,
                        'bank_name' => $bankName,
                        'bank_account_no' => $bankAccountNo,
                        'effective_from' => $joiningDate,
                        'is_current' => true,
                    ]);
                }

                // Device mapping - the key requirement (global, no device binding)
                if ($biometricId !== null && $biometricId !== '') {
                    $bioStr = trim((string) $biometricId);
                    try {
                        // FIX: device_user_id = ZK id (UserID from Excel B), uid = same for now but never used for mapping (only id)
                        DeviceUser::updateOrCreate(
                            ['device_user_id' => $bioStr],
                            [
                                'device_id' => null, // global
                                'uid' => $bioStr, // keep for reference, attendance uses device_user_id=id only
                                'employee_id' => $empId,
                                'name' => $fullName,
                            ]
                        );
                    } catch (\Throwable $e) {
                        Log::warning("DeviceUser duplicate $bioStr for $empId: ".$e->getMessage());
                    }
                }

            } catch (\Throwable $e) {
                Log::error("BasicSeeder row error EmpID={$row['A']}", ['error' => $e->getMessage()]);
            }
            $bar?->advance();
        }

        $bar?->finish();
        $this->command?->newLine();
        $this->command?->info('Stats: '.json_encode($stats));
        $this->command?->info('Total employees: '.Employee::count().' | device_users: '.DeviceUser::count());
    }

    private function normalizeBloodGroup($v): ?string
    {
        if ($v === null || $v === '' || $v === 0 || $v === '0') {
            return null;
        }
        $s = strtoupper(trim((string) $v));
        if (in_array($s, ['N/A', 'NA', 'N A', '?', '0'])) {
            return null;
        }
        if (in_array($s, ['A', 'B', 'AB', 'O'])) {
            return $s.'+';
        }

        return $s;
    }

    /**
     * A bank account number as digits, or null when there is none.
     *
     * Two things make this less trivial than it looks. PhpSpreadsheet hands a 13-digit account back as
     * a float, so a naive cast produces 1.93103E+12 and the bank file would carry a number no bank
     * recognises. And 279 rows in this sheet put a literal 0 in the column to mean "no account" — read
     * as a number it is a one-digit account, and those employees silently vanish from the payroll run
     * instead of being paid in cash.
     */
    private function cleanAccount(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $value = number_format((float) $value, 0, '', '');
        }

        $digits = preg_replace('/[^0-9]/', '', $value) ?? '';

        if ($digits === '' || trim($digits, '0') === '') {
            return null;
        }

        return strlen($digits) >= 10 && strlen($digits) <= 16 ? $digits : null;
    }

    private function cleanPhone($v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = trim((string) $v);
        if (str_contains($s, 'E+')) {
            $s = number_format((float) $v, 0, '', '');
        }
        $s = preg_replace('/[^0-9]/', '', $s);

        return ($s === '' || $s === '0') ? null : $s;
    }

    private function parseDate($v): ?string
    {
        if (empty($v)) {
            return null;
        }
        try {
            if (is_numeric($v) && $v > 30000 && $v < 60000) {
                return Carbon::createFromFormat('Y-m-d', Date::excelToDateTimeObject($v)->format('Y-m-d'))->toDateString();
            }

            return Carbon::parse($v)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function splitName(string $full): array
    {
        $parts = preg_split('/\s+/', trim($full));
        if (count($parts) === 1) {
            return [$parts[0], null];
        }

        return [$parts[0], implode(' ', array_slice($parts, 1))];
    }
}
