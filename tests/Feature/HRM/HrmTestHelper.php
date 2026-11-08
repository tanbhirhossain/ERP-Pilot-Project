<?php

namespace Tests\Feature\HRM;

use Modules\CORE\Models\User;
use Modules\HRM\Models\BiometricDevice;
use Modules\HRM\Models\Department;
use Modules\HRM\Models\Designation;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Organization;
use Modules\HRM\Models\Roster\RosterShift;

trait HrmTestHelper
{
    protected function adminUser(): User
    {
        // Try to get seeded super admin, fallback to factory
        $admin = User::where('email', config('core.super_admin.email', 'admin@example.com'))->first();
        if ($admin) {
            return $admin;
        }

        return User::factory()->create(['email_verified_at' => now()]);
    }

    protected function createOrganization(array $overrides = []): Organization
    {
        return Organization::factory()->create($overrides);
    }

    protected function createDepartment(array $overrides = []): Department
    {
        return Department::factory()->create($overrides);
    }

    protected function createDesignation(?Department $dept = null, array $overrides = []): Designation
    {
        if ($dept) {
            $overrides['department_id'] = $dept->id;
        } elseif (! isset($overrides['department_id'])) {
            $overrides['department_id'] = $this->createDepartment()->id;
        }

        return Designation::factory()->create($overrides);
    }

    protected function createDevice(array $overrides = []): BiometricDevice
    {
        return BiometricDevice::factory()->create($overrides);
    }

    protected function createEmployee(array $overrides = []): Employee
    {
        // Ensure required FKs exist
        if (! isset($overrides['department_id'])) {
            $overrides['department_id'] = $this->createDepartment()->id;
        }
        if (! isset($overrides['designation_id'])) {
            $overrides['designation_id'] = $this->createDesignation()->id;
        }

        return Employee::factory()->create($overrides);
    }

    protected function createShift(array $overrides = []): RosterShift
    {
        return RosterShift::factory()->create($overrides);
    }

    protected function createNightShift(): RosterShift
    {
        return RosterShift::factory()->night()->create();
    }
}
