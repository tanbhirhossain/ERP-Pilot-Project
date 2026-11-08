<?php

namespace Modules\SALES\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class SalesPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $perms = [
            'sales.quotations.view', 'sales.quotations.manage',
            'sales.orders.view', 'sales.orders.manage', 'sales.orders.confirm',
            'sales.invoices.view', 'sales.invoices.manage', 'sales.invoices.post',
            'sales.deliveries.view', 'sales.deliveries.manage',
            'sales.returns.view', 'sales.returns.manage', 'sales.returns.approve',
            'pos.terminal.sell', 'pos.sessions.manage',
            // The day-end / user-wise collection report (item 39). Deliberately separate from
            // `pos.sessions.manage`: closing a drawer is a cashier's job, reading every cashier's
            // takings and variances side by side is a manager's.
            'pos.reports.view',
        ];
        foreach ($perms as $n) {
            Permission::firstOrCreate(['name' => $n, 'guard_name' => 'web']);
        }
        if ($role = Role::where('name', 'Super Admin')->first()) {
            $role->givePermissionTo($perms);
        }

        // Who else can read the tills. The people who run and audit the business need sales and cash
        // figures; they do not need to ring up a sale or close somebody's drawer, so these are view
        // permissions only. Without them the day-end report — like the trial balance — would be
        // reachable by the Super Admin alone, which is why neither was ever seen in use.
        $readers = ['Administrator', 'Manager', 'Auditor'];
        $readOnly = [
            'sales.quotations.view', 'sales.orders.view', 'sales.invoices.view',
            'sales.deliveries.view', 'sales.returns.view', 'pos.reports.view',
        ];

        foreach ($readers as $roleName) {
            if ($role = Role::where('name', $roleName)->first()) {
                $role->givePermissionTo($readOnly);
            }
        }
    }
}
