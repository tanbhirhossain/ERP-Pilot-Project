<?php

namespace Modules\ACCOUNTS\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AccountsPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $perms = [
            'accounts.coa.view', 'accounts.coa.manage',
            'accounts.journals.view', 'accounts.journals.create', 'accounts.journals.post', 'accounts.journals.void',
            'accounts.vouchers.view', 'accounts.vouchers.manage',
            'accounts.banks.manage',
            'accounts.fiscal.manage',
            'accounts.reports.view',
            'accounts.payables.view', 'accounts.receivables.view',
            'accounts.counts.view',
        ];

        foreach ($perms as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        if ($role = Role::where('name', 'Super Admin')->first()) {
            $role->givePermissionTo($perms);
        }
    }
}
