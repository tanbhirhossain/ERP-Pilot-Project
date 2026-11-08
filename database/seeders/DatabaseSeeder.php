<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\ACCOUNTS\Database\Seeders\AccountsDemoSeeder;
use Modules\ACCOUNTS\Database\Seeders\AccountsPermissionSeeder;
use Modules\CORE\Database\Seeders\CoreSeeder;
use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\HRM\Database\Seeders\BiometricDeviceSeeder;
use Modules\HRM\Database\Seeders\HRMPermissionSeeder;
use Modules\HRM\Database\Seeders\RealEmployeeBasicSeeder;
use Modules\HRM\Database\Seeders\RosterFallbackShiftSeeder;
use Modules\INVENTORY\Database\Seeders\InventoryDemoSeeder;
use Modules\INVENTORY\Database\Seeders\InventoryPermissionSeeder;
use Modules\PARTNER\Database\Seeders\PartnerDemoSeeder;
use Modules\PARTNER\Database\Seeders\PartnerGroupSeeder;
use Modules\PARTNER\Database\Seeders\PartnerPermissionSeeder;
use Modules\PAYROLL\Database\Seeders\PAYROLLPermissionSeeder;
use Modules\PURCHASE\Database\Seeders\PurchaseDemoSeeder;
use Modules\PURCHASE\Database\Seeders\PurchasePermissionSeeder;
use Modules\SALES\Database\Seeders\SalesDemoSeeder;
use Modules\SALES\Database\Seeders\SalesPermissionSeeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // User::factory()->create([
        //     'name' => 'Test User',
        //     'email' => 'test@example.com',
        // ]);

        $this->call([
            CoreSeeder::class,
            SuperAdminSeeder::class,
            HRMPermissionSeeder::class,
            PAYROLLPermissionSeeder::class,
            // The fallback shift attendance classification depends on. Extracted from migration
            // 2026_10_30_110000, which used to create it in up() — report item 38.
            RosterFallbackShiftSeeder::class,
            BiometricDeviceSeeder::class,
            RealEmployeeBasicSeeder::class,
            // ERP Pilot — 4 new enterprise modules
            PartnerGroupSeeder::class,
            PartnerPermissionSeeder::class,
            PartnerDemoSeeder::class,
            InventoryPermissionSeeder::class,
            InventoryDemoSeeder::class,
            AccountsPermissionSeeder::class,
            AccountsDemoSeeder::class,
            SalesPermissionSeeder::class,
            SalesDemoSeeder::class,
            PurchasePermissionSeeder::class,
            PurchaseDemoSeeder::class,
        ]);
    }
}
