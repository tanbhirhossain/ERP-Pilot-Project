<?php
namespace Modules\HRM\Database\Seeders;
use Illuminate\Database\Seeder;
use Modules\CORE\Models\Permission;
use Modules\CORE\Models\Role;
class HRMPermissionSeeder extends Seeder {
    public function run(): void {
        $perms=[
            'hrm.view','hrm.organization.view','hrm.organization.manage',
            'hrm.departments.view','hrm.departments.manage',
            'hrm.designations.view','hrm.designations.manage',
            'hrm.devices.view','hrm.devices.manage',
            'hrm.employees.view','hrm.employees.manage',
            'hrm.shifts.view','hrm.shifts.manage',
            'hrm.duties.view','hrm.duties.manage',
            'hrm.swaps.view','hrm.swaps.manage',
            'hrm.overtimes.view','hrm.overtimes.manage','hrm.overtimes.approve',
            'hrm.holidays.view','hrm.holidays.manage',
            'hrm.leave-types.view','hrm.leave-types.manage',
            'hrm.leaves.view','hrm.leaves.manage','hrm.leaves.approve','hrm.leaves.balance.view',
            'hrm.informed.view','hrm.informed.manage',
            'hrm.attendance.view','hrm.attendance.manage','hrm.attendance.sync',
        ];
        foreach($perms as $name){
            Permission::firstOrCreate(['name'=>$name,'guard_name'=>'web'],['group'=>'HRM','guard_name'=>'web']);
        }
        // CORE provisions the Administrator role (not only an "Admin" alias).
        // Grant HRM queue permissions to both supported administrator role names.
        Role::query()->whereIn('name', ['Super Admin', 'Admin', 'Administrator'])
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($perms));
    }
}
