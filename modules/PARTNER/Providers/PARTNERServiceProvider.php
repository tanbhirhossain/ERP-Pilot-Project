<?php

namespace Modules\PARTNER\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\CORE\Services\ImportService;
use Modules\PARTNER\Imports\PartnerImport;
use Modules\PARTNER\Interfaces\PartnerDataProviderInterface;
use Modules\PARTNER\Interfaces\PartnerGroupRepositoryInterface;
use Modules\PARTNER\Interfaces\PartnerRepositoryInterface;
use Modules\PARTNER\Interfaces\PriceListRepositoryInterface;
use Modules\PARTNER\Interfaces\PricingProviderInterface;
use Modules\PARTNER\Repositories\PartnerGroupRepository;
use Modules\PARTNER\Repositories\PartnerRepository;
use Modules\PARTNER\Repositories\PriceListRepository;
use Modules\PARTNER\Services\PartnerDataProvider;
use Modules\PARTNER\Services\PricingService;

class PARTNERServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/partner.php', 'partner');

        $this->app->bind(PartnerRepositoryInterface::class, PartnerRepository::class);
        $this->app->bind(PartnerGroupRepositoryInterface::class, PartnerGroupRepository::class);
        $this->app->bind(PriceListRepositoryInterface::class, PriceListRepository::class);

        // Cross-module provider — DIP: other modules depend on interface, not implementation
        $this->app->bind(PartnerDataProviderInterface::class, PartnerDataProvider::class);

        // Pricing engine (Phase 1): SALES/POS resolve contract/tier prices through
        // this interface, never through the PriceList models directly.
        $this->app->bind(PricingProviderInterface::class, PricingService::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'partner');
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');

        if (file_exists(__DIR__.'/../Routes/api.php')) {
            $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
        }

        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        // Register this module's importer into the shared orchestrator (Phase 1).
        $this->app->make(ImportService::class)
            ->register('partners', PartnerImport::class);
    }
}
