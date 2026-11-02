<?php

namespace Modules\HRM\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Modules\HRM\Models\Department;
use Modules\HRM\Models\Designation;
use Modules\HRM\Models\Organization;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Employee\EmployeeProfile;
use Modules\HRM\Models\Employee\EmployeePolicy;
use Modules\HRM\Models\Employee\EmployeeSalary;
use Modules\HRM\Models\DeviceUser;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * RealEmployeeImportSeederFull
 * Imports real data from http://erp.amzhospitalbd.com/check/employees.xlsx
 *
 * Maps:
 *  Emp ID       -> employees.employee_id (PK string, unique)
 *  biometric_id -> device_users.device_user_id (global mapping, device_id nullable)
 *
 * Three sheets:
 *  Sheet 0: Worksheet          = Employee Master (35 cols, 1005 rows)
 *  Sheet 1: Worksheet 1        = Salary Info (11 cols - redundant, verified identical to master)
 *  Sheet 2: Worksheet 2        = Department list (4 cols, 57 rows)
 *
 * Run:
 *  php artisan db:seed --class="Modules\HRM\Database\Seeders\RealEmployeeImportSeederFull"
 * Or via DatabaseSeeder call
 *
 * Excel file expected at:
 *  - database/seeders/data/employees.xlsx (committed fallback)
 *  - storage/app/employees.xlsx
 *  - /tmp/employees.xlsx
 *  You can also pass --file=/path/to/file.xlsx or set EMPLOYEE_EXCEL env
 */
class RealEmployeeImportSeederFull extends Seeder
{
    public function run(): void
    {
        $file = $this->resolveExcelFile();
        if (!$file) {
            $this->command?->error("Excel file not found. Tried: database/seeders/data/employees.xlsx, storage/app/employees.xlsx, /tmp/employees.xlsx");
            $this->command?->error("Download: http://erp.amzhospitalbd.com/check/employees.xlsx -> save as database/seeders/data/employees.xlsx");
            return;
        }

        $this->command?->info("Loading Excel: $file");
        $spreadsheet = IOFactory::load($file);

        // Sheet 2 - Departments first (so FK exists)
        $this->importDepartments($spreadsheet);

        // Sheet 0 - Employees master
        $this->importEmployees($spreadsheet);

        $this->command?->info("RealEmployeeImportSeeder completed.");
    }

    private function resolveExcelFile(): ?string
    {
        $candidates = [
            base_path('database/seeders/data/employees.xlsx'),
            storage_path('app/employees.xlsx'),
            '/tmp/employees.xlsx',
            env('EMPLOYEE_EXCEL'),
        ];
        // CLI option --file=
        $cliFile = null;
        foreach ($_SERVER['argv'] ?? [] as $arg) {
            if (str_starts_with($arg, '--file=')) {
                $cliFile = substr($arg, 7);
            }
        }
        if ($cliFile) array_unshift($candidates, $cliFile);

        foreach ($candidates as $p) {
            if ($p && file_exists($p)) return $p;
        }
        return null;
    }

    private function importDepartments($spreadsheet): void
    {
        $sheet = $spreadsheet->getSheetByName('Worksheet 2') ?? $spreadsheet->getSheet(2);
        $rows = $sheet->toArray(null, true, true, true);
        // First row is header id, code, name, description
        $header = array_shift($rows);
        $this->command?->info("Importing departments from Worksheet 2: ".count($rows)." rows");

        $seen = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row['C'] ?? ''));
            if ($name === '' || isset($seen[strtolower($name)])) continue;
            $seen[strtolower($name)] = true;
            $code = $row['B'] ?? null;
            if (empty($code)) {
                $code = strtoupper(Str::slug($name, '_'));
            }
            Department::firstOrCreate(
                ['name' => $name],
                [
                    'code' => $code,
                    'slug' => Str::slug($name),
                    'description' => $row['D'] ?? null,
                    'is_active' => true,
                    'sort_order' => 0,
                ]
            );
        }
        $this->command?->info("Departments seeded: ".count($seen)." unique");
    }

    private function importEmployees($spreadsheet): void
    {
        $sheet = $spreadsheet->getSheetByName('Worksheet') ?? $spreadsheet->getSheet(0);
        $rows = $sheet->toArray(null, true, true, true);
        $header = array_shift($rows); // A..AI
        $this->command?->info("Importing employees from Worksheet: ".count($rows)." rows");

        $organization = Organization::first();
        if (!$organization) {
            $organization = Organization::create([
                'name' => 'AMZ Hospital LTD',
                'email' => 'info@amzhospitalbd.com',
                'phone' => '',
                'address' => 'Dhaka, Bangladesh',
            ]);
            $this->command?->info("Created default organization: {$organization->name}");
        }

        $bar = $this->command?->getOutput()->createProgressBar(count($rows));
        $bar?->start();

        $stats = ['created'=>0,'updated'=>0,'skipped'=>0,'errors'=>0];
        $deptCache = [];
        $desigCache = [];

        foreach ($rows as $idx => $row) {
            try {
                // Excel columns mapping (A=1..)
                // A Emp ID, B Biometric ID, C File No, D Name, E Designation, F Dept ID, G Dept Name,
                // H Unit ID, I Unit Name, J NID, K Blood Group, L Status, M Emergency, N Phone, O Address,
                // P DOB, Q Joining Date, R Card Issue, S Photo, T Employee Type, U Job Type, V Joining Salary,
                // W Allow Overtime, X Allow Bonus, Y Payment Criteria, Z Basic Pay, AA House Rent, AB Medical,
                // AC Conveyance, AD Monthly, AE Payment Method, AF Bank Name, AG Bank A/C No, AH Created At, AI Service Year

                $empId = trim((string) ($row['A'] ?? ''));
                if ($empId === '') { $stats['skipped']++; $bar?->advance(); continue; }

                $biometricId = $row['B'] ?? null;
                $fileNo       = $row['C'] ? trim((string)$row['C']) : null;
                $fullName     = trim((string) ($row['D'] ?? 'Unknown'));
                $designationName = trim((string) ($row['E'] ?? 'Staff'));
                $deptIdExcel  = $row['F'] ?? null; // not needed, use name
                $deptName     = trim((string) ($row['G'] ?? 'General'));
                $nid          = $row['J'] ? trim((string)$row['J']) : null;
                $bloodGroup   = $this->normalizeBloodGroup($row['K'] ?? null);
                $statusExcel  = strtolower(trim((string) ($row['L'] ?? 'active')));
                $emergency    = $row['M'] ? $this->normalizePhone($row['M']) : null;
                $phone        = $row['N'] ? $this->normalizePhone($row['N']) : null;
                $address      = $row['O'] ? trim((string)$row['O']) : null;
                $dob          = $this->parseDate($row['P'] ?? null);
                $joiningDate  = $this->parseDate($row['Q'] ?? null);
                $cardIssueDate= $this->parseDate($row['R'] ?? null);
                $photo        = $row['S'] ? trim((string)$row['S']) : null;
                $empType      = strtolower(trim((string) ($row['T'] ?? 'fulltime')));
                $jobType      = $row['U'] ? trim((string)$row['U']) : null;
                $joiningSalary= $row['V'] ? (float) $row['V'] : null;
                $allowOvertime= !empty($row['W']) && (int)$row['W'] === 1;
                $allowBonus   = !empty($row['X']) && (int)$row['X'] === 1;
                $paymentCriteria = $row['Y'] ? trim((string)$row['Y']) : 'general';
                $basicPay     = $row['Z'] ? (float) $row['Z'] : 0;
                $houseRent    = $row['AA'] ? (float) $row['AA'] : 0;
                $medicalAllow = $row['AB'] ? (float) $row['AB'] : 0;
                $conveyance   = $row['AC'] ? (float) $row['AC'] : 0;
                $monthlySalary= $row['AD'] ? (float) $row['AD'] : ($basicPay+$houseRent+$medicalAllow+$conveyance);
                $paymentMethod= $row['AE'] ? strtolower(trim((string)$row['AE'])) : null; // cash/bank
                $bankName     = $row['AF'] ? trim((string)$row['AF']) : null;
                $bankAccNo    = $row['AG'] ? trim((string)$row['AG']) : null;
                $createdAt    = $this->parseDate($row['AH'] ?? null) ?? now();
                $serviceYear  = $row['AI'] ? (int) $row['AI'] : null;

                // Normalize phone: keep as string, ensure no scientific notation
                if ($phone === '0' || $phone === 0) $phone = null;
                if ($emergency === '0' || $emergency === 0) $emergency = null;

                // Map status: active->Active, resigned->Resigned
                $statusMap = ['active'=>'Active','resigned'=>'Resigned','inactive'=>'Inactive'];
                $status = $statusMap[$statusExcel] ?? 'Active';
                $isActive = $statusExcel === 'active';

                // employment_type: fulltime -> Permanent (existing enum)
                $empTypeMap = ['fulltime'=>'Permanent','parttime'=>'Part-time','contract'=>'Contract','intern'=>'Intern'];
                $employmentType = $empTypeMap[$empType] ?? 'Permanent';

                // Department: ensure exists
                if (!isset($deptCache[$deptName])) {
                    $dept = Department::firstOrCreate(
                        ['name' => $deptName],
                        ['code'=> strtoupper(Str::slug($deptName,'_')), 'slug'=>Str::slug($deptName), 'is_active'=>true]
                    );
                    $deptCache[$deptName] = $dept->id;
                }
                $departmentId = $deptCache[$deptName];

                // Designation: ensure exists
                if (!isset($desigCache[$designationName])) {
                    $desig = Designation::firstOrCreate(
                        ['name' => $designationName],
                        ['code'=> strtoupper(Str::slug($designationName,'_')), 'slug'=>Str::slug($designationName), 'is_active'=>true]
                    );
                    $desigCache[$designationName] = $desig->id;
                }
                $designationId = $desigCache[$designationName];

                // Split full_name into first/last for existing schema
                [$firstName, $lastName] = $this->splitName($fullName);

                // Clean NID: if contains dashes, keep raw but ensure string
                $nidClean = $nid ? preg_replace('/[^0-9A-Za-z\-]/', '', $nid) : null;
                // Blood group already normalized

                $bioStrForEmpFull = ($biometricId !== null && $biometricId !== '') ? trim((string)$biometricId) : null;
                // Upsert employee
                $employee = Employee::updateOrCreate(
                    ['employee_id' => $empId],
                    [
                        'biometric_id' => $bioStrForEmpFull,
                        'file_no' => $fileNo,
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'full_name' => $fullName,
                        'email' => null, // not in Excel, keep existing if any
                        'phone' => $phone,
                        'nid' => $nidClean,
                        'gender' => null, // not in Excel
                        'date_of_birth' => $dob,
                        'joining_date' => $joiningDate,
                        'card_issue_date' => $cardIssueDate,
                        'organization_id' => $organization->id,
                        'department_id' => $departmentId,
                        'designation_id' => $designationId,
                        'employment_type' => $employmentType,
                        'job_type' => $jobType,
                        'joining_salary' => $joiningSalary,
                        'allow_overtime' => $allowOvertime,
                        'allow_bonus' => $allowBonus,
                        'payment_criteria' => $paymentCriteria,
                        'service_year' => $serviceYear,
                        'status' => $status,
                        'blood_group' => $bloodGroup,
                        'marital_status' => null,
                        'photo' => $photo, // store filename as is; actual file can be fetched from old server if needed
                        'address' => $address,
                        'is_active' => $isActive,
                        'created_at' => $createdAt,
                        'updated_at' => now(),
                    ]
                );
                $wasRecentlyCreated = $employee->wasRecentlyCreated;
                if ($wasRecentlyCreated) $stats['created']++; else $stats['updated']++;

                // EmployeeProfile: emergency, address duplication
                EmployeeProfile::updateOrCreate(
                    ['employee_id' => $empId],
                    [
                        'father_name' => null,
                        'mother_name' => null,
                        'present_address' => $address,
                        'permanent_address' => $address,
                        'emergency_contact_name' => null, // Excel only has phone, not name
                        'emergency_contact_phone' => $emergency,
                        'emergency_contact_relation' => null,
                        'nationality' => 'Bangladeshi',
                        'religion' => null,
                        'bio' => null,
                    ]
                );

                // EmployeePolicy: default policy (can be customized per employee later)
                EmployeePolicy::firstOrCreate(
                    ['employee_id' => $empId],
                    [
                        'shift_type' => 'Fixed',
                        'grace_time_in' => 15,
                        'grace_time_out' => 15,
                        'is_punishable' => true,
                    ]
                );

                // EmployeeSalary: full breakdown
                // Use effective_from = joining_date, is_current = active
                // Map: basic_pay -> basic_salary, house_rent -> house_rent, medical -> medical_allowance
                // conveyance -> conveyance_allowance, monthly -> gross+net
                EmployeeSalary::updateOrCreate(
                    ['employee_id' => $empId, 'is_current' => true],
                    [
                        'basic_salary' => $basicPay,
                        'gross_salary' => $monthlySalary,
                        'house_rent' => $houseRent,
                        'medical_allowance' => $medicalAllow,
                        'conveyance_allowance' => $conveyance,
                        'other_allowance' => 0,
                        'net_salary' => $monthlySalary,
                        'pay_grade' => null,
                        'effective_from' => $joiningDate,
                        'effective_to' => null,
                        'is_current' => true,
                        'payment_method' => $paymentMethod,
                        'bank_name' => $bankName && $bankName !== '0' ? $bankName : null,
                        'bank_account_no' => $bankAccNo && $bankAccNo !== '0' ? (string)$bankAccNo : null,
                        'remarks' => "Imported from Excel. Service Year: ".($serviceYear ?? 'N/A')." | Payment Criteria: $paymentCriteria",
                    ]
                );

                // DeviceUser mapping: biometric_id -> device_user_id, linked to employee_id
                // Keep device_id null = global mapping (attendance pairing uses device_user_id only)
                if ($biometricId !== null && $biometricId !== '') {
                    $biometricStr = trim((string) $biometricId);
                    try {
                        // FIX: device_user_id = ZK id (UserID), uid separate (attendance pairing uses id only)
                        DeviceUser::updateOrCreate(
                            ['device_user_id' => $biometricStr],
                            [
                                'device_id' => null, // global
                                'uid' => $biometricStr, // reference only, never used for mapping
                                'employee_id' => $empId,
                                'name' => $fullName,
                            ]
                        );
                    } catch (\Throwable $e) {
                        // Handle duplicate biometric_id: if already taken, reassign
                        if (str_contains($e->getMessage(), 'biometric_id') || str_contains($e->getMessage(), 'UNIQUE') || str_contains($e->getMessage(), 'Duplicate')) {
                            // Clear conflicting employee's biometric_id then retry
                            Employee::where('biometric_id', $biometricStr)->where('employee_id', '!=', $empId)->update(['biometric_id' => null]);
                            DeviceUser::updateOrCreate(
                                ['device_user_id' => $biometricStr],
                                ['device_id' => null, 'uid' => $biometricStr, 'employee_id' => $empId, 'name' => $fullName] // id only
                            );
                            \Illuminate\Support\Facades\Log::warning("Duplicate biometric_id $biometricStr reassigned to $empId");
                        } else {
                            throw $e;
                        }
                    }
                }

            } catch (\Throwable $e) {
                $stats['errors']++;
                Log::error("RealEmployeeImportSeeder error at row $idx EmpID={$row['A']}", ['error'=>$e->getMessage(), 'trace'=>$e->getTraceAsString()]);
                $this->command?->warn("Row $idx EmpID {$row['A']} error: ".$e->getMessage());
            }
            $bar?->advance();
        }

        $bar?->finish();
        $this->command?->newLine();
        $this->command?->info("Employees import stats: ".json_encode($stats));
        $this->command?->info("Departments cached: ".count($deptCache)." | Designations cached: ".count($desigCache));
        $this->command?->info("Total employees in DB: ".Employee::count());
        $this->command?->info("Total device_users in DB: ".DeviceUser::count());
    }

    private function normalizeBloodGroup($value): ?string
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') return null;
        $v = trim((string)$value);
        $bad = ['0','N/A','NA','N A','?','null'];
        if (in_array(strtoupper($v), $bad)) return null;
        // Normalize o+ -> O+, normalize b -> B etc
        $v = strtoupper($v);
        // Fix single letter B, A -> keep but ensure mapping? Excel has 'B' => treat as B+
        if (in_array($v, ['A','B','AB','O'])) return $v.'+';
        return $v;
    }

    private function normalizePhone($value): ?string
    {
        if ($value === null || $value === '') return null;
        $s = trim((string)$value);
        // Remove scientific notation if excel exported as float
        if (str_contains($s, 'E+')) {
            $s = number_format((float)$value, 0, '', '');
        }
        // Remove spaces/dashes
        $s = preg_replace('/[^0-9]/', '', $s);
        if ($s === '' || $s === '0') return null;
        return $s;
    }

    private function parseDate($value): ?string
    {
        if (empty($value)) return null;
        try {
            // Excel may give Excel serial date or string
            if (is_numeric($value) && $value > 30000 && $value < 60000) {
                // Excel serial
                return Carbon::createFromFormat('Y-m-d', \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value)->format('Y-m-d'))->toDateString();
            }
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName));
        if (count($parts) === 1) return [$parts[0], null];
        $first = $parts[0];
        $last = implode(' ', array_slice($parts, 1));
        return [$first, $last];
    }
}
