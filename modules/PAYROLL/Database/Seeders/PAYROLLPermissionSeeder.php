<?php

namespace Modules\PAYROLL\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\CORE\Models\Permission;
use Modules\CORE\Models\Role;

class PAYROLLPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'payroll.view', 'payroll.settings.manage', 'payroll.sheets.manage', 'payroll.sheets.release', 'payroll.slips.view', 'payroll.slips.pay',
            'payroll.increments.manage', 'payroll.increments.approve', 'payroll.loans.manage', 'payroll.loans.approve',
            'payroll.pf.view', 'payroll.pf.manage', 'payroll.tax.manage', 'payroll.adjustments.manage',
            'payroll.bonus.manage', 'payroll.settlements.manage', 'payroll.settlements.approve',
            'payroll.finance.view', 'payroll.finance.post', 'payroll.finance.void', 'payroll.reopens.view', 'payroll.reopens.manage', 'payroll.bank-advices.view', 'payroll.bank-advices.manage',
        ];
        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        foreach (Role::query()->whereIn('name', ['Super Admin', 'Administrator', 'Admin'])->get() as $role) {
            $role->givePermissionTo($permissions);
        }
    }
}
