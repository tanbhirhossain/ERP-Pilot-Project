<?php

namespace Modules\SALES\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\SALES\Interfaces\DeliveryRepositoryInterface;
use Modules\SALES\Interfaces\InvoiceRepositoryInterface;
use Modules\SALES\Interfaces\OrderRepositoryInterface;
use Modules\SALES\Interfaces\PosRepositoryInterface;
use Modules\SALES\Interfaces\QuotationRepositoryInterface;
use Modules\SALES\Interfaces\SalesReturnRepositoryInterface;
use Modules\SALES\Repositories\DeliveryRepository;
use Modules\SALES\Repositories\InvoiceRepository;
use Modules\SALES\Repositories\OrderRepository;
use Modules\SALES\Repositories\PosRepository;
use Modules\SALES\Repositories\QuotationRepository;
use Modules\SALES\Repositories\SalesReturnRepository;

class SALESServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/sales.php', 'sales');

        $this->app->bind(QuotationRepositoryInterface::class, QuotationRepository::class);
        $this->app->bind(OrderRepositoryInterface::class, OrderRepository::class);
        $this->app->bind(InvoiceRepositoryInterface::class, InvoiceRepository::class);
        $this->app->bind(PosRepositoryInterface::class, PosRepository::class);
        $this->app->bind(DeliveryRepositoryInterface::class, DeliveryRepository::class);
        $this->app->bind(SalesReturnRepositoryInterface::class, SalesReturnRepository::class);

        // Cross-module bindings are auto-resolved via ModuleServiceProvider glob
        // SALES depends on InventoryStockProvider + AccountsPostingProvider interfaces
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'sales');
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        if (file_exists(__DIR__.'/../Routes/api.php')) {
            $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
        }
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }
}
