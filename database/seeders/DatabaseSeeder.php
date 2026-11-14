<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\ACCOUNTS\Database\Seeders\AccountsDemoSeeder;
use Modules\ACCOUNTS\Database\Seeders\AccountsPermissionSeeder;
use Modules\CORE\Database\Seeders\CoreSeeder;
use Modules\CORE\Database\Seeders\Phase1Seeder;
use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\HRM\Database\Seeders\AMZOctober2026CalendarSeeder;
use Modules\HRM\Database\Seeders\BiometricDeviceSeeder;
use Modules\HRM\Database\Seeders\HRMPermissionSeeder;
use Modules\HRM\Database\Seeders\LeaveTypeSeeder;
use Modules\HRM\Database\Seeders\RealEmployeeBasicSeeder;
use Modules\HRM\Database\Seeders\RosterFallbackShiftSeeder;
use Modules\HRM\Database\Seeders\RosterShiftSeeder;
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
            // Reference data the attendance + leave desks cannot work without: the standard shift
            // catalogue (includes 'Evening 03PM-11PM') and the statutory leave types (incl. Sick Leave).
            RosterShiftSeeder::class,
            LeaveTypeSeeder::class,
            BiometricDeviceSeeder::class,
            RealEmployeeBasicSeeder::class,
            // The requested October 2026 demo calendar (L402003 roster w/ Fridays off, two approved sick
            // leaves, Durga-puja public holidays) — registered here so plain `php artisan db:seed` lays it;
            // AMZDemoSeeder calls the same class, so running either entry point converges.
            AMZOctober2026CalendarSeeder::class,
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
            // Phase 1 — Enterprise Core (permissions + inactive approval matrix).
            Phase1Seeder::class,
        ]);
    }
}
