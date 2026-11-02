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
 *  - Device: F18 @ 192.168.10.93
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
            $sd = Designation::updateOrCreate(
                ['code' => 'SD'],
                [
                    'name' => 'Software Developer',
                    'slug' => 'software-developer',
                    'description' => 'Develops and maintains hospital software systems',
                    'department_id' => $department->id,
                    'grade' => 'G-7',
                    'level' => 7,
                    'is_active' => true,
                ]
            );

            $srOp = Designation::updateOrCreate(
                ['code' => 'SO'],
                [
                    'name' => 'Sr Operator',
                    'slug' => 'sr-operator',
                    'description' => 'Senior Operator — Front-desk & ward operations',
                    'department_id' => $department->id,
                    'grade' => 'G-5',
                    'level' => 5,
                    'is_active' => true,
                ]
            );

            $this->command?->info("Designations: {$sd->name} ({$sd->code}), {$srOp->name} ({$srOp->code})");

            // -------------------------------------------------
            // 4) Employees — TANBHIR HOSSAIN (L1009001) & Rimon Mia (L402001)
            // -------------------------------------------------
            $tanbhir = Employee::updateOrCreate(
                ['employee_id' => 'L1009001'],
                [
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
                ]
            );

            $rimon = Employee::updateOrCreate(
                ['employee_id' => 'L402001'],
                [
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
                ]
            );

            $this->command?->info("Employees: {$tanbhir->full_name} ({$tanbhir->employee_id}), {$rimon->full_name} ({$rimon->employee_id})");

            // Employee policies — required for attendance engine (Fixed, grace 10)
            foreach ([$tanbhir, $rimon] as $emp) {
                EmployeePolicy::updateOrCreate(
                    ['employee_id' => $emp->employee_id],
                    [
                        'grace_time_in' => 10,
                        'grace_time_out' => 10,
                        'shift_type' => 'Fixed',
                        'is_punishable' => false,
                    ]
                );
            }

            // -------------------------------------------------
            // 5) Device — F18 @ 192.168.10.93
            // -------------------------------------------------
            $device = BiometricDevice::updateOrCreate(
                ['ip_address' => '192.168.10.93'],
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
                    'remarks' => 'Seeded by AMZDemoSeeder — 192.168.10.93 F18',
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
                DeviceUser::updateOrCreate(
                    ['device_user_id' => $m['device_user_id']],
                    [
                        'uid' => $m['uid'],
                        'name' => $m['name'],
                        'employee_id' => $m['employee_id'],
                        'device_id' => null, // global — not bound to 192.168.10.93, works on any device
                    ]
                );
            }

            $this->command?->info("DeviceUser mappings: 101 → L1009001, 102 → L402001 (global, cross-device)");

            $this->command?->info('AMZ Demo seeded successfully — Organization, Department, Designations, Employees, Device & global mappings ready.');
            $this->command?->info('Tip: On ZK device, enroll fingers for UID 101 (TANBHIR) & 102 (Rimon). Then sync: php artisan hrm:sync-attendance --device='.$device->id.' or via UI Attendance Sync → Sync Now.');
        });
    }
}
