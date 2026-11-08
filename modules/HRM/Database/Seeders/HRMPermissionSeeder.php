<?php

namespace Modules\HRM\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\CORE\Models\Permission;
use Modules\CORE\Models\Role;

class HRMPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $perms = [
            'hrm.view', 'hrm.organization.view', 'hrm.organization.manage',
            'hrm.departments.view', 'hrm.departments.manage',
            'hrm.designations.view', 'hrm.designations.manage',
            'hrm.devices.view', 'hrm.devices.manage',
            'hrm.employees.view', 'hrm.employees.manage',
            'hrm.shifts.view', 'hrm.shifts.manage',
            'hrm.duties.view', 'hrm.duties.manage',
            'hrm.swaps.view', 'hrm.swaps.manage',
            'hrm.overtimes.view', 'hrm.overtimes.manage', 'hrm.overtimes.approve',
            'hrm.holidays.view', 'hrm.holidays.manage',
            'hrm.leave-types.view', 'hrm.leave-types.manage',
            'hrm.leaves.view', 'hrm.leaves.manage', 'hrm.leaves.approve', 'hrm.leaves.balance.view',
            'hrm.documents.view', 'hrm.documents.manage', 'hrm.documents.verify',
            'hrm.assets.view', 'hrm.assets.manage',
            'hrm.probations.view', 'hrm.probations.manage',
            'hrm.movements.view', 'hrm.movements.manage', 'hrm.movements.approve',
            'hrm.regularizations.view', 'hrm.regularizations.manage', 'hrm.regularizations.approve', 'hrm.leave-year-end.view', 'hrm.leave-year-end.manage', 'hrm.reports.view',
            'hrm.shift-allowances.view', 'hrm.shift-allowances.manage',
            'hrm.ot-policies.view', 'hrm.ot-policies.manage',
            'hrm.checklists.view', 'hrm.checklists.manage',
            'hrm.clearance.view', 'hrm.clearance.manage',
            'hrm.informed.view', 'hrm.informed.manage',
            'hrm.attendance.view', 'hrm.attendance.manage', 'hrm.attendance.sync',
        ];
        foreach ($perms as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        // CORE provisions the Administrator role (not only an "Admin" alias).
        // Grant HRM queue permissions to both supported administrator role names.
        Role::query()->whereIn('name', ['Super Admin', 'Admin', 'Administrator'])
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($perms));

        // HRM routes are permission-gated, so a role with no HRM grant sees
        // nothing at all. Give the operational roles a deliberate default
        // instead of locking them out: managers run the department day to day,
        // auditors read everything and change nothing.
        $manager = array_values(array_filter($perms, fn (string $p): bool => (bool) preg_match(
            '/^hrm\.(view|organization\.view|departments\.|designations\.|employees\.|shifts\.|duties\.|swaps\.'
            .'|overtimes\.(view|manage|approve)|holidays\.|leave-types\.|leaves\.|documents\.(view|manage)'
            .'|assets\.|movements\.(view|manage)|regularizations\.(view|manage)|leave-year-end\.view'
            .'|reports\.view|shift-allowances\.|ot-policies\.|checklists\.|clearance\.|informed\.'
            .'|attendance\.(view|manage|sync)|probations\.(view|manage))/',
            $p
        )));

        $auditor = array_values(array_filter($perms, fn (string $p): bool => str_ends_with($p, '.view')
            || $p === 'hrm.view'
            || $p === 'hrm.leaves.balance.view'));

        foreach (['Manager' => $manager, 'Auditor' => $auditor] as $roleName => $grant) {
            if ($role = Role::query()->where('name', $roleName)->first()) {
                $role->givePermissionTo($grant);
            }
        }
    }
}
