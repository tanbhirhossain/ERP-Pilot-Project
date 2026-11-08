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

        Partner::factory()->count(10)->vendor()->create();
        Partner::factory()->count(10)->customer()->create();

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
