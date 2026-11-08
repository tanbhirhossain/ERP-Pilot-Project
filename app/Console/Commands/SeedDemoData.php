<?php

namespace App\Console\Commands;

use Database\Seeders\DemoDataSeeder;
use Illuminate\Console\Command;

/**
 * Opt-in demo dataset. Deliberately NOT part of `db:seed`/`DatabaseSeeder`: it drives a
 * month of attendance, a scoped payroll run and full purchase/sales/POS chains through the
 * real services, which is far too slow to impose on every CI build.
 *
 * Refuses to run in production unless `--force` is given, because it writes documents,
 * journals and ledger entries that cannot be unwound by a re-run (the seeder is idempotent,
 * not reversible).
 */
class SeedDemoData extends Command
{
    protected $signature = 'erp:demo-data
                            {--force : Allow the demo dataset to be written in the production environment}';

    protected $description = 'Seed a realistic demo dataset (attendance, payroll, purchases, sales, POS, journals) on top of the base seeders';

    public function handle(): int
    {
        // Both are checked: config('app.env') is what a runtime override changes, while
        // app()->environment() is what the bootstrap detection sets.
        if ((config('app.env') === 'production' || app()->environment('production')) && ! $this->option('force')) {
            // Lowercase "refuses" on purpose: the contract test matches on `refus`, and
            // expectsOutputToContain's case handling is not something to bet a guard on.
            $this->error('This command refuses to write demo data in production. Re-run with --force if this is really intended.');

            return self::FAILURE;
        }

        $startedAt = microtime(true);

        $this->call('db:seed', [
            '--class' => DemoDataSeeder::class,
            '--force' => true,
        ]);

        $this->info(sprintf('erp:demo-data finished in %.1fs.', microtime(true) - $startedAt));

        return self::SUCCESS;
    }
}
