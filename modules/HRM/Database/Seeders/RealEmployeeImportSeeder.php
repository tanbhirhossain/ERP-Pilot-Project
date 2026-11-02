<?php

namespace Modules\HRM\Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * RealEmployeeImportSeeder
 * Minimal import wrapper - delegates to RealEmployeeBasicSeeder
 * Keeps original class name so existing `php artisan db:seed --class=RealEmployeeImportSeeder` still works
 * with simplified logic (basic details + biometric mapping only).
 *
 * For full detailed import see: RealEmployeeBasicSeeder.php (minimal) is now the default.
 * Original full import backed up as RealEmployeeImportSeederFull.php if needed.
 */
class RealEmployeeImportSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RealEmployeeBasicSeeder::class);
    }
}
