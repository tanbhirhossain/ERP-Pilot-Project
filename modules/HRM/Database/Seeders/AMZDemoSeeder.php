<?php

namespace Modules\HRM\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\HRM\Models\BiometricDevice;
use Modules\HRM\Models\Department;
use Modules\HRM\Models\Designation;
use Modules\HRM\Models\DeviceUser;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Employee\EmployeePolicy;
use Modules\HRM\Models\Organization;

/**
 * AMZ Hospital Ltd — Demo Seeder
 *
 * Seeds exactly what was requested:
 *  - Organization: AMZ Hospital Ltd
 *  - Department: IT
 *  - Designations: Software Developer, Sr Operator
 *  - Employees: TANBHIR HOSSAIN (L1009001, Software Developer), Rimon Mia (L402001, Sr Operator)
 *  - Device: F18 @ 192.0.2.93
 *
 * Idempotent: safe to run multiple times (updateOrCreate).
 * Run: php artisan db:seed --class=Modules\\HRM\\Database\\Seeders\\AMZDemoSeeder
 */
class AMZDemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            // -------------------------------------------------
            // 1) Organization — singleton (first row is canonical)
            // -------------------------------------------------
            $organization = Organization::updateOrCreate(
                ['name' => 'AMZ Hospital Ltd'],
                [
                    'email' => 'info@amzhospital.com',
                    'phone' => '+8809617888999',
                    'address' => 'AMZ Hospital Ltd, Chandra, Gazipur, Dhaka, Bangladesh',
                    'website' => 'https://amzhospital.com',
                ]
            );
            $this->command?->info("Organization: {$organization->name} (ID: {$organization->id})");

            // -------------------------------------------------
            // 2) Department — IT
            // -------------------------------------------------
            $department = Department::updateOrCreate(
                ['code' => 'IT'],
                [
                    'name' => 'IT',
                    'slug' => 'it',
                    'description' => 'Information Technology — Software, Networking & Support',
                    'is_active' => true,
                    'sort_order' => 1,
                ]
            );
            $this->command?->info("Department: {$department->name} (ID: {$department->id}, code: {$department->code})");

            // -------------------------------------------------
            // 3) Designations — linked to IT
            // -------------------------------------------------
            // Reuse a designation that already exists under either key, untouched: a demo seeder must not
            // rename HRM's real job titles, and the unique slug index makes a naive insert collide when an
            // imported designation row holds the slug under a different code. Create only when truly absent.
            $sd = Designation::query()->where('slug', 'software-developer')->orWhere('code', 'SD')->orderBy('id')->first()
                ?: Designation::create([
                    'code' => 'SD',
                    'name' => 'Software Developer',
                    'slug' => 'software-developer',
                    'description' => 'Develops and maintains hospital software systems',
                    'department_id' => $department->id,
                    'grade' => 'G-7',
                    'level' => 7,
                    'is_active' => true,
                ]);

            $srOp = Designation::query()->where('slug', 'sr-operator')->orWhere('code', 'SO')->orderBy('id')->first()
                ?: Designation::create([
                    'code' => 'SO',
                    'name' => 'Sr Operator',
                    'slug' => 'sr-operator',
                    'description' => 'Senior Operator — Front-desk & ward operations',
                    'department_id' => $department->id,
                    'grade' => 'G-5',
                    'level' => 5,
                    'is_active' => true,
                ]);

            $this->command?->info("Designations: {$sd->name} ({$sd->code}), {$srOp->name} ({$srOp->code})");

            // -------------------------------------------------
            // 4) Employees — TANBHIR HOSSAIN (L1009001) & Rimon Mia (L402001)
            // -------------------------------------------------
            // Create-if-absent for REAL people and their policies: on a demo database this seeds; on a live
            // one (which this seeder has already been run against) L1009001/L402001 are real employees whose
            // attributes — and whose biometric IDs — must not be rewritten by a demo. updateOrCreate used to
            // do exactly that and died on `employees.biometric_id` being unique when another person holds it.
            $tanbhir = Employee::query()->firstOrNew(['employee_id' => 'L1009001']);
            if (! $tanbhir->exists) {
                $tanbhir->fill([
                    'biometric_id' => '101',
                    'first_name' => 'TANBHIR',
                    'last_name' => 'HOSSAIN',
                    'full_name' => 'TANBHIR HOSSAIN',
                    'email' => 'tanbhir@amzhospital.com',
                    'phone' => '01700000001',
                    'organization_id' => $organization->id,
                    'department_id' => $department->id,
                    'designation_id' => $sd->id,
                    'employment_type' => 'Permanent',
                    'status' => 'Active',
                    'joining_date' => '2024-01-15',
                    'gender' => 'Male',
                    'is_active' => true,
                ])->save();
            } else {
                $this->command?->info('L1009001 already exists — attributes untouched');
            }

            $rimon = Employee::query()->firstOrNew(['employee_id' => 'L402001']);
            if (! $rimon->exists) {
                $rimon->fill([
                    'biometric_id' => '102',
                    'first_name' => 'Rimon',
                    'last_name' => 'Mia',
                    'full_name' => 'Rimon Mia',
                    'email' => 'rimon.mia@amzhospital.com',
                    'phone' => '01700000002',
                    'organization_id' => $organization->id,
                    'department_id' => $department->id,
                    'designation_id' => $srOp->id,
                    'employment_type' => 'Permanent',
                    'status' => 'Active',
                    'joining_date' => '2024-03-01',
                    'gender' => 'Male',
                    'is_active' => true,
                ])->save();
            } else {
                $this->command?->info('L402001 already exists — attributes untouched');
            }

            $this->command?->info("Employees: {$tanbhir->full_name} ({$tanbhir->employee_id}), {$rimon->full_name} ({$rimon->employee_id})");

            // Employee policies — required for attendance engine (Fixed, grace 10). Create only when missing:
            // a real policy (shift, grace, punishability) is HRM's decision, not a demo's.
            foreach ([$tanbhir, $rimon] as $emp) {
                if (! EmployeePolicy::query()->where('employee_id', $emp->employee_id)->exists()) {
                    EmployeePolicy::query()->create([
                        'employee_id' => $emp->employee_id,
                        'grace_time_in' => 10,
                        'grace_time_out' => 10,
                        'shift_type' => 'Fixed',
                        'is_punishable' => false,
                    ]);
                }
            }

            // -------------------------------------------------
            // 5) Device — F18 @ 192.0.2.93
            // -------------------------------------------------
            $device = BiometricDevice::updateOrCreate(
                ['ip_address' => '192.0.2.93'],
                [
                    'name' => 'AMZ F18 — Main Gate',
                    'device_name' => 'F18',
                    'port' => 4370,
                    'device_model' => 'F18',
                    'location' => 'Main Gate / MB',
                    'connection_type' => 'TCP',
                    'status' => 'Active',
                    'is_enabled' => true,
                    'auto_sync_enabled' => true,
                    'sync_interval_minutes' => 15,
                    'serial_number' => null,
                    'remarks' => 'Seeded by AMZDemoSeeder — 192.0.2.93 F18',
                ]
            );

            $this->command?->info("Device: {$device->name} ({$device->device_model} @ {$device->ip_address}:{$device->port}) ID: {$device->id}");

            // -------------------------------------------------
            // 6) Global DeviceUser mapping (no device binding)
            //    One ZK id (UserID) → one EMP, works cross-device (uid separate) (A-in, B-out)
            // -------------------------------------------------
            // FIX: device_user_id = ZK id (UserID, e.g. 101), uid = internal ZK uid (separate, never used for mapping)
            $mappings = [
                ['device_user_id' => '101', 'employee_id' => $tanbhir->employee_id, 'name' => $tanbhir->full_name, 'uid' => '101'],
                ['device_user_id' => '102', 'employee_id' => $rimon->employee_id, 'name' => $rimon->full_name, 'uid' => '102'],
            ];

            foreach ($mappings as $m) {
                // Live mappings win: device_users.employee_id is unique, and on a real database the person
                // may already be enrolled under another ZK id. Never hijack or duplicate that for a demo.
                $existing = DeviceUser::query()->where('device_user_id', $m['device_user_id'])->first();
                if ($existing !== null && $existing->employee_id !== null && $existing->employee_id !== $m['employee_id']) {
                    $this->command?->info("DeviceUser {$m['device_user_id']} is already mapped to {$existing->employee_id} — left alone");

                    continue;
                }
                if ($existing === null && DeviceUser::query()->where('employee_id', $m['employee_id'])->exists()) {
                    $this->command?->info("Employee {$m['employee_id']} already holds a device mapping — DeviceUser {$m['device_user_id']} not created");

                    continue;
                }
                DeviceUser::updateOrCreate(
                    ['device_user_id' => $m['device_user_id']],
                    [
                        'uid' => $m['uid'],
                        'name' => $m['name'],
                        'employee_id' => $m['employee_id'],
                        'device_id' => null, // global — not bound to 192.0.2.93, works on any device
                    ]
                );
            }

            $this->command?->info('DeviceUser mappings: 101 → L1009001, 102 → L402001 (global, cross-device)');

            // -------------------------------------------------
            // 7) October 2026 demo attendance calendar (requested 2026-11-10): roster duty for L402003
            //    2026-10-01..31 on 'Evening 03PM-11PM' with Fridays Off, approved sick leaves (L402003
            //    2026-10-22, L1009001 2026-10-17), Hindu Durga puja public holidays 2026-10-20/21.
            //    Lives in its own seeder, also registered in the main DatabaseSeeder — one source of truth.
            // -------------------------------------------------
            $this->call([AMZOctober2026CalendarSeeder::class]);

            $this->command?->info('AMZ Demo seeded successfully — Organization, Department, Designations, Employees, Device & global mappings ready.');
            $this->command?->info('Tip: On ZK device, enroll fingers for UID 101 (TANBHIR) & 102 (Rimon). Then sync: php artisan hrm:sync-attendance --device='.$device->id.' or via UI Attendance Sync → Sync Now.');
        });
    }
}
