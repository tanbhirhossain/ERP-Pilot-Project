<?php

namespace Modules\PURCHASE\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PurchasePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $perms = config('purchase.permissions', [
            'purchase.orders.view', 'purchase.orders.manage', 'purchase.orders.confirm',
            'purchase.receipts.view', 'purchase.receipts.manage', 'purchase.receipts.post',
            'purchase.bills.view', 'purchase.bills.manage', 'purchase.bills.post',
            'purchase.payments.manage', 'purchase.reports.view',
            'purchase.requisitions.view', 'purchase.requisitions.manage', 'purchase.requisitions.approve',
            'purchase.rfq.view', 'purchase.rfq.manage', 'purchase.rfq.award',
        ]);
        foreach ($perms as $n) {
            Permission::firstOrCreate(['name' => $n, 'guard_name' => 'web']);
        }
        if ($role = Role::where('name', 'Super Admin')->first()) {
            $role->givePermissionTo($perms);
        }

        // F18's split only means anything if the demo roles land on opposite sides of it: a Manager
        // decides (approve / award), Staff ask (manage). Without this, the shipped roles all hold both
        // grants and the segregation the tables enforce is invisible in a fresh install.
        if ($role = Role::where('name', 'Manager')->first()) {
            $role->givePermissionTo(['purchase.requisitions.view', 'purchase.requisitions.approve', 'purchase.rfq.view', 'purchase.rfq.award']);
        }

        if ($role = Role::where('name', 'Staff')->first()) {
            $role->givePermissionTo(['purchase.requisitions.view', 'purchase.requisitions.manage', 'purchase.rfq.view', 'purchase.rfq.manage']);
        }
    }
}
