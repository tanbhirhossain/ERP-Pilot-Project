<?php

namespace Modules\INVENTORY\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\CORE\Models\Permission;
use Modules\CORE\Models\Role;

class InventoryPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'inventory.products.view', 'inventory.products.create', 'inventory.products.update', 'inventory.products.delete',
            'inventory.categories.manage', 'inventory.brands.manage', 'inventory.uoms.manage',
            'inventory.warehouses.manage', 'inventory.locations.manage',
            'inventory.stocks.view', 'inventory.movements.view',
            'inventory.transfers.manage', 'inventory.transfers.approve',
            'inventory.adjustments.manage', 'inventory.adjustments.approve',
            'inventory.counts.view', 'inventory.counts.manage',
            'inventory.kits.view', 'inventory.kits.manage',
            'inventory.reports.view', 'inventory.exports',
        ];
        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        if ($superAdmin = Role::where('name', 'Super Admin')->first()) {
            $superAdmin->givePermissionTo($permissions);
        }
    }
}
