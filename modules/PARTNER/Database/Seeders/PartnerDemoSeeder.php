<?php

namespace Modules\PARTNER\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\PARTNER\Models\Partner;
use Modules\PARTNER\Models\PartnerGroup;

class PartnerDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (Partner::count() > 20) {
            return;
        }

        $groups = PartnerGroup::all();

        // Named counterparties, not Faker people.
        //
        // A customer list reading "Crystal Hettinger I Enterprise" tells a Bangladeshi finance team
        // nothing about whether the software understands their business, and a vendor list of
        // American surnames on a garment importer is quietly wrong. These are the kind of names an
        // accounts department in Dhaka would recognise, and the TIN/BIN styles match the real ones so
        // a VAT report looks like a VAT report.
        $vendors = [
            ['Padma Textile Mills Ltd.', 'V-1001'],
            ['Karnafuli Fabrics Ltd.', 'V-1002'],
            ['Meghna Dyeing & Finishing', 'V-1003'],
            ['Jamuna Accessories Co.', 'V-1004'],
            ['Rupsha Garments Accessories', 'V-1005'],
            ['Bengal Yarn Trading', 'V-1006'],
            ['Sylhet Silk House', 'V-1007'],
            ['Tangail Weavers Cooperative', 'V-1008'],
        ];

        $customers = [
            ['Rahim Traders', 'C-2001'],
            ['Karim Enterprise', 'C-2002'],
            ['New Market Fashion House', 'C-2003'],
            ['Bashundhara Mega Mall — Outlet 4', 'C-2004'],
            ['Agrabad Retail Point', 'C-2005'],
            ['Uttara Collection', 'C-2006'],
            ['Dhanmondi Style Corner', 'C-2007'],
            ['Chittagong Garments Outlet', 'C-2008'],
        ];

        foreach ($vendors as [$name, $code]) {
            Partner::firstOrCreate(['partner_code' => $code], [
                'type' => 'vendor', 'name' => $name, 'is_active' => true, 'is_verified' => true,
            ]);
        }

        foreach ($customers as [$name, $code]) {
            Partner::firstOrCreate(['partner_code' => $code], [
                'type' => 'customer', 'name' => $name, 'is_active' => true, 'is_verified' => true,
            ]);
        }

        // Add a few with full relations
        $vendor = Partner::factory()->vendor()->create(['name' => 'Bangladesh Fabric Mills Ltd.', 'is_verified' => true]);
        $vendor->contacts()->create(['name' => 'Mr. Rahman', 'designation' => 'Sales Manager', 'phone' => '01711111111', 'email' => 'sales@bfm.example.com', 'is_primary' => true]);
        $vendor->addresses()->create(['type' => 'factory', 'address_line' => 'Plot 42, BSCIC Industrial Area', 'city' => 'Gazipur', 'district' => 'Gazipur', 'division' => 'Dhaka', 'is_default' => true]);
        $vendor->banks()->create(['bank_name' => 'Dutch-Bangla Bank Ltd.', 'branch' => 'Gazipur Branch', 'account_name' => 'Bangladesh Fabric Mills Ltd.', 'account_no' => '1234567890123', 'is_primary' => true]);

        $customer = Partner::factory()->customer()->create(['name' => 'Apex Retail Chain', 'is_verified' => true]);
        $customer->contacts()->create(['name' => 'Ms. Sultana', 'designation' => 'Procurement Lead', 'phone' => '01822222222', 'email' => 'procurement@apex.example.com', 'is_primary' => true]);
        $customer->addresses()->create(['type' => 'billing', 'address_line' => 'House 12, Road 5, Dhanmondi', 'city' => 'Dhaka', 'district' => 'Dhaka', 'division' => 'Dhaka', 'is_default' => true]);
    }
}
