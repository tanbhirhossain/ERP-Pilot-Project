<?php

namespace Modules\PARTNER\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\PARTNER\Models\PartnerGroup;

class PartnerGroupSeeder extends Seeder
{
    public function run(): void
    {
        $groups = [
            ['name' => 'Raw Material Suppliers', 'code' => 'VRM', 'type' => 'vendor', 'sort_order' => 1],
            ['name' => 'Service Providers', 'code' => 'VSP', 'type' => 'vendor', 'sort_order' => 2],
            ['name' => 'Logistics Partners', 'code' => 'VLG', 'type' => 'vendor', 'sort_order' => 3],
            ['name' => 'Corporate Clients', 'code' => 'CCO', 'type' => 'customer', 'sort_order' => 1],
            ['name' => 'Retail Customers', 'code' => 'CRT', 'type' => 'customer', 'sort_order' => 2],
            ['name' => 'Wholesale Customers', 'code' => 'CWH', 'type' => 'customer', 'sort_order' => 3],
            ['name' => 'Internal / Inter-Company', 'code' => 'BTH', 'type' => 'both', 'sort_order' => 10],
        ];

        foreach ($groups as $g) {
            PartnerGroup::firstOrCreate(['code' => $g['code']], $g);
        }
    }
}
