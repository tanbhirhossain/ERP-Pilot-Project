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
        ];
        foreach ($perms as $n) Permission::firstOrCreate(['name' => $n, 'guard_name' => 'web']);
        if ($role = Role::where('name', 'Super Admin')->first()) $role->givePermissionTo($perms);
    }
}
