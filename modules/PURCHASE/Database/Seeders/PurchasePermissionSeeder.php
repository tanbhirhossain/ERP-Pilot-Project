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
            'purchase.orders.view','purchase.orders.manage','purchase.orders.confirm',
            'purchase.receipts.view','purchase.receipts.manage','purchase.receipts.post',
            'purchase.bills.view','purchase.bills.manage','purchase.bills.post',
            'purchase.payments.manage','purchase.reports.view',
        ]);
        foreach ($perms as $n) Permission::firstOrCreate(['name'=>$n,'guard_name'=>'web']);
        if ($role = Role::where('name','Super Admin')->first()) $role->givePermissionTo($perms);
    }
}
