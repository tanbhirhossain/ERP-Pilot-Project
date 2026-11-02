<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\CORE\Database\Seeders\CoreSeeder;
use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\HRM\Database\Seeders\BiometricDeviceSeeder;
use Modules\HRM\Database\Seeders\RealEmployeeBasicSeeder;
use Modules\PAYROLL\Database\Seeders\PAYROLLPermissionSeeder;

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
            PAYROLLPermissionSeeder::class,
            BiometricDeviceSeeder::class,
            RealEmployeeBasicSeeder::class
        ]);
    }
}
