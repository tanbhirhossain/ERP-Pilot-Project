<?php

namespace Modules\ACCOUNTS\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\ACCOUNTS\Interfaces\AccountRepositoryInterface;
use Modules\ACCOUNTS\Interfaces\AccountsPostingProviderInterface;
use Modules\ACCOUNTS\Interfaces\BankRepositoryInterface;
use Modules\ACCOUNTS\Interfaces\FiscalYearRepositoryInterface;
use Modules\ACCOUNTS\Interfaces\JournalRepositoryInterface;
use Modules\ACCOUNTS\Repositories\AccountRepository;
use Modules\ACCOUNTS\Repositories\BankRepository;
use Modules\ACCOUNTS\Repositories\FiscalYearRepository;
use Modules\ACCOUNTS\Repositories\JournalRepository;
use Modules\ACCOUNTS\Services\AccountsPostingProvider;

class ACCOUNTSServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../Config/accounts.php', 'accounts');

        $this->app->bind(AccountRepositoryInterface::class, AccountRepository::class);
        $this->app->bind(JournalRepositoryInterface::class, JournalRepository::class);
        $this->app->bind(BankRepositoryInterface::class, BankRepository::class);
        $this->app->bind(FiscalYearRepositoryInterface::class, FiscalYearRepository::class);

        // DIP: other modules (SALES, PAYROLL, INVENTORY) depend on interface, not JournalService
        $this->app->bind(AccountsPostingProviderInterface::class, AccountsPostingProvider::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../Resources/views', 'accounts');
        $this->loadRoutesFrom(__DIR__ . '/../Routes/web.php');
        if (file_exists(__DIR__ . '/../Routes/api.php')) {
            $this->loadRoutesFrom(__DIR__ . '/../Routes/api.php');
        }
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
    }
}
