<?php

namespace Modules\HRM\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Modules\HRM\Models\Department;
use Modules\HRM\Models\Designation;
use Modules\HRM\Models\Organization;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Employee\EmployeeProfile;
use Modules\HRM\Models\DeviceUser;
use PhpOffice\PhpSpreadsheet\IOFactory;

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
        if (!$file) {
            $this->command?->error("Excel not found at database/seeders/data/employees.xlsx");
            return;
        }

        $this->command?->info("Loading: $file");
        $spreadsheet = IOFactory::load($file);

        // Departments first (from sheet 2)
        $this->importDepartments($spreadsheet);

        // Employees minimal (from sheet 0)
        $this->importEmployeesMinimal($spreadsheet);

        $this->command?->info("Basic import completed.");
    }

    private function resolveExcelFile(): ?string
    {
        $candidates = [
            base_path('database/seeders/data/employees.xlsx'),
            storage_path('app/employees.xlsx'),
            '/tmp/employees.xlsx',
        ];
        foreach ($candidates as $p) {
            if ($p && file_exists($p)) return $p;
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
            if ($name === '' || isset($seen[strtolower($name)])) continue;
            $seen[strtolower($name)] = true;
            Department::firstOrCreate(
                ['name' => $name],
                ['code' => strtoupper(Str::slug($name, '_')), 'slug' => Str::slug($name), 'is_active' => true]
            );
        }
        $this->command?->info("Departments: ".count($seen)." unique");
    }

    private function importEmployeesMinimal($spreadsheet): void
    {
        $sheet = $spreadsheet->getSheetByName('Worksheet') ?? $spreadsheet->getSheet(0);
        $rows = $sheet->toArray(null, true, true, true);
        array_shift($rows); // header

        $this->command?->info("Importing ".count($rows)." employees (basic only)");

        $organization = Organization::first() ?? Organization::create([
            'name' => 'AMZ Hospital LTD',
            'address' => 'Dhaka, Bangladesh',
        ]);

        $deptCache = [];
        $desigCache = [];
        $bar = $this->command?->getOutput()->createProgressBar(count($rows));
        $bar?->start();

        $stats = ['created'=>0,'updated'=>0,'skipped'=>0];

        foreach ($rows as $row) {
            try {
                $empId = trim((string) ($row['A'] ?? ''));
                if ($empId === '') { $stats['skipped']++; $bar?->advance(); continue; }

                $biometricId = $row['B'] ?? null;
                $fullName    = trim((string) ($row['D'] ?? 'Unknown'));
                $desigName   = trim((string) ($row['E'] ?? 'Staff'));
                $deptName    = trim((string) ($row['G'] ?? 'General'));
                $nid         = $row['J'] ? trim((string)$row['J']) : null;
                $bloodRaw    = $row['K'] ?? null;
                $statusRaw   = strtolower(trim((string) ($row['L'] ?? 'active')));
                $emergency   = $row['M'] ? $this->cleanPhone($row['M']) : null;
                $phone       = $row['N'] ? $this->cleanPhone($row['N']) : null;
                $address     = $row['O'] ? trim((string)$row['O']) : null;
                $dob         = $this->parseDate($row['P'] ?? null);
                $joiningDate = $this->parseDate($row['Q'] ?? null);
                $empTypeRaw  = strtolower(trim((string) ($row['T'] ?? 'fulltime')));
                // Minimal salary: basic + monthly (optional)
                $basicPay    = $row['Z'] ? (float) $row['Z'] : 0;
                $monthly     = $row['AD'] ? (float) $row['AD'] : $basicPay;

                // Normalize
                $blood = $this->normalizeBloodGroup($bloodRaw);
                $statusMap = ['active'=>'Active','resigned'=>'Resigned'];
                $status = $statusMap[$statusRaw] ?? 'Active';
                $empTypeMap = ['fulltime'=>'Permanent','parttime'=>'Part-time','contract'=>'Contract'];
                $employmentType = $empTypeMap[$empTypeRaw] ?? 'Permanent';

                // Department (cache)
                if (!isset($deptCache[$deptName])) {
                    $dept = Department::firstOrCreate(
                        ['name' => $deptName],
                        ['code'=>strtoupper(Str::slug($deptName,'_')),'slug'=>Str::slug($deptName),'is_active'=>true]
                    );
                    $deptCache[$deptName] = $dept->id;
                }
                $deptId = $deptCache[$deptName];

                // Designation (cache)
                if (!isset($desigCache[$desigName])) {
                    $desig = Designation::firstOrCreate(
                        ['name' => $desigName],
                        ['code'=>strtoupper(Str::slug($desigName,'_')),'slug'=>Str::slug($desigName),'is_active'=>true]
                    );
                    $desigCache[$desigName] = $desig->id;
                }
                $desigId = $desigCache[$desigName];

                [$firstName, $lastName] = $this->splitName($fullName);

                // Employee - minimal fields only (extra migration columns stay null)
                // Professional: biometric_id is canonical ZK UserID stored directly on employees
                $bioStrForEmp = ($biometricId !== null && $biometricId !== '') ? trim((string)$biometricId) : null;
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
                        'nid' => $nid ? preg_replace('/[^0-9A-Za-z\-]/','',$nid) : null,
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

                // Minimal salary (only if has value) - keep previous migration columns null
                if ($basicPay > 0) {
                    \Modules\HRM\Models\Employee\EmployeeSalary::updateOrCreate(
                        ['employee_id' => $empId, 'is_current' => true],
                        [
                            'basic_salary' => $basicPay,
                            'gross_salary' => $monthly,
                            'net_salary' => $monthly,
                            'effective_from' => $joiningDate,
                            'is_current' => true,
                        ]
                    );
                }

                // Device mapping - the key requirement (global, no device binding)
                if ($biometricId !== null && $biometricId !== '') {
                    $bioStr = trim((string)$biometricId);
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
                Log::error("BasicSeeder row error EmpID={$row['A']}", ['error'=>$e->getMessage()]);
            }
            $bar?->advance();
        }

        $bar?->finish();
        $this->command?->newLine();
        $this->command?->info("Stats: ".json_encode($stats));
        $this->command?->info("Total employees: ".Employee::count()." | device_users: ".DeviceUser::count());
    }

    private function normalizeBloodGroup($v): ?string
    {
        if ($v===null||$v===''||$v===0||$v==='0') return null;
        $s = strtoupper(trim((string)$v));
        if (in_array($s, ['N/A','NA','N A','?','0'])) return null;
        if (in_array($s, ['A','B','AB','O'])) return $s.'+';
        return $s;
    }
    private function cleanPhone($v): ?string
    {
        if ($v===null||$v==='') return null;
        $s = trim((string)$v);
        if (str_contains($s,'E+')) $s = number_format((float)$v,0,'','');
        $s = preg_replace('/[^0-9]/','',$s);
        return ($s===''||$s==='0') ? null : $s;
    }
    private function parseDate($v): ?string
    {
        if (empty($v)) return null;
        try {
            if (is_numeric($v) && $v>30000 && $v<60000) {
                return Carbon::createFromFormat('Y-m-d', \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($v)->format('Y-m-d'))->toDateString();
            }
            return Carbon::parse($v)->toDateString();
        } catch (\Throwable $e) { return null; }
    }
    private function splitName(string $full): array
    {
        $parts = preg_split('/\s+/', trim($full));
        if (count($parts)===1) return [$parts[0], null];
        return [$parts[0], implode(' ', array_slice($parts,1))];
    }
}
