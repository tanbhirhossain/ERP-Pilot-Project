<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\CORE\Database\Seeders\CoreSeeder;
use Modules\CORE\Database\Seeders\SuperAdminSeeder;

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
        ]);
    }
}
