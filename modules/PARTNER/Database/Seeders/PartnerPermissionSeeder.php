<?php

namespace Modules\PARTNER\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\CORE\Models\Permission;
use Modules\CORE\Models\Role;

class PartnerPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Vendors
            'partner.vendors.view',
            'partner.vendors.create',
            'partner.vendors.update',
            'partner.vendors.delete',
            // Customers
            'partner.customers.view',
            'partner.customers.create',
            'partner.customers.update',
            'partner.customers.delete',
            // Groups & Price Lists
            'partner.groups.manage',
            'partner.pricelists.manage',
            // Verifications
            'partner.verifications.manage',
            // Exports
            'partner.exports',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // Assign to Super Admin
        if ($superAdmin = Role::where('name', 'Super Admin')->first()) {
            $superAdmin->givePermissionTo($permissions);
        }
    }
}
