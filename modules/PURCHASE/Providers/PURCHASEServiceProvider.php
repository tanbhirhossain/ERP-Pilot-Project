<?php

namespace Modules\PURCHASE\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\PURCHASE\Interfaces\GoodsReceiptRepositoryInterface;
use Modules\PURCHASE\Interfaces\PurchaseOrderRepositoryInterface;
use Modules\PURCHASE\Interfaces\PurchaseReturnRepositoryInterface;
use Modules\PURCHASE\Interfaces\VendorBillRepositoryInterface;
use Modules\PURCHASE\Repositories\GoodsReceiptRepository;
use Modules\PURCHASE\Repositories\PurchaseOrderRepository;
use Modules\PURCHASE\Repositories\PurchaseReturnRepository;
use Modules\PURCHASE\Repositories\VendorBillRepository;

class PURCHASEServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/purchase.php', 'purchase');
        $this->app->bind(PurchaseOrderRepositoryInterface::class, PurchaseOrderRepository::class);
        $this->app->bind(GoodsReceiptRepositoryInterface::class, GoodsReceiptRepository::class);
        $this->app->bind(VendorBillRepositoryInterface::class, VendorBillRepository::class);
        $this->app->bind(PurchaseReturnRepositoryInterface::class, PurchaseReturnRepository::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'purchase');
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }
}
