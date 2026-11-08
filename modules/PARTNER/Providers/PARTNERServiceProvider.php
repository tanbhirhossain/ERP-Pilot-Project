<?php

namespace Modules\PARTNER\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\PARTNER\Interfaces\PartnerDataProviderInterface;
use Modules\PARTNER\Interfaces\PartnerGroupRepositoryInterface;
use Modules\PARTNER\Interfaces\PartnerRepositoryInterface;
use Modules\PARTNER\Interfaces\PriceListRepositoryInterface;
use Modules\PARTNER\Repositories\PartnerGroupRepository;
use Modules\PARTNER\Repositories\PartnerRepository;
use Modules\PARTNER\Repositories\PriceListRepository;
use Modules\PARTNER\Services\PartnerDataProvider;

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
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'partner');
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');

        if (file_exists(__DIR__.'/../Routes/api.php')) {
            $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
        }

        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }
}
