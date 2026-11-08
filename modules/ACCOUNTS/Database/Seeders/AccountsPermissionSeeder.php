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
            'accounts.banks.view', 'accounts.banks.manage',
            'accounts.fiscal.manage',
            'accounts.reports.view',
            'accounts.payables.view', 'accounts.receivables.view',
            // NOTE: 'accounts.counts.view' was seeded here in error — stock counts
            // belong to INVENTORY and its routes enforce 'inventory.counts.view'.
            // It is no longer created. Existing rows are left untouched; remove
            // them from the Permissions screen (or drop the row) once no role
            // depends on it.
        ];

        foreach ($perms as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        if ($role = Role::where('name', 'Super Admin')->first()) {
            $role->givePermissionTo($perms);
        }

        // Read-only accounting for the roles that run and audit the business.
        //
        // This is the fix for "I can not see any trial balance". The report existed, was routed, was
        // linked in the sidebar at `menu.ts` and rendered by `TreeSidebar.vue` — but
        // `accounts.reports.view` was granted to the Super Admin alone, so for every other role the
        // menu item was filtered out and the route answered 403. An Auditor who cannot open a trial
        // balance, a ledger or a balance sheet cannot audit.
        //
        // View only: posting, voiding and managing the chart of accounts stay with the Super Admin,
        // because those change the books and nothing here should.
        $readers = ['Administrator', 'Manager', 'Auditor'];
        $readOnly = [
            'accounts.reports.view', 'accounts.coa.view', 'accounts.journals.view',
            'accounts.vouchers.view', 'accounts.banks.view',
            'accounts.payables.view', 'accounts.receivables.view',
        ];

        foreach ($readers as $roleName) {
            if ($role = Role::where('name', $roleName)->first()) {
                $role->givePermissionTo($readOnly);
            }
        }
    }
}
