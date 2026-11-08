<?php

namespace Modules\INVENTORY\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\INVENTORY\Interfaces\AdjustmentRepositoryInterface;
use Modules\INVENTORY\Interfaces\BrandRepositoryInterface;
use Modules\INVENTORY\Interfaces\CategoryRepositoryInterface;
use Modules\INVENTORY\Interfaces\InventoryStockProviderInterface;
use Modules\INVENTORY\Interfaces\InventoryValuationProviderInterface;
use Modules\INVENTORY\Interfaces\ProductRepositoryInterface;
use Modules\INVENTORY\Interfaces\StockRepositoryInterface;
use Modules\INVENTORY\Interfaces\TransferRepositoryInterface;
use Modules\INVENTORY\Interfaces\UomRepositoryInterface;
use Modules\INVENTORY\Interfaces\WarehouseRepositoryInterface;
use Modules\INVENTORY\Repositories\AdjustmentRepository;
use Modules\INVENTORY\Repositories\BrandRepository;
use Modules\INVENTORY\Repositories\CategoryRepository;
use Modules\INVENTORY\Repositories\ProductRepository;
use Modules\INVENTORY\Repositories\StockRepository;
use Modules\INVENTORY\Repositories\TransferRepository;
use Modules\INVENTORY\Repositories\UomRepository;
use Modules\INVENTORY\Repositories\WarehouseRepository;
use Modules\INVENTORY\Services\InventoryStockProvider;
use Modules\INVENTORY\Services\InventoryValuationProvider;

class INVENTORYServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/inventory.php', 'inventory');

        $this->app->bind(ProductRepositoryInterface::class, ProductRepository::class);
        $this->app->bind(CategoryRepositoryInterface::class, CategoryRepository::class);
        $this->app->bind(BrandRepositoryInterface::class, BrandRepository::class);
        $this->app->bind(UomRepositoryInterface::class, UomRepository::class);
        $this->app->bind(WarehouseRepositoryInterface::class, WarehouseRepository::class);
        $this->app->bind(StockRepositoryInterface::class, StockRepository::class);
        $this->app->bind(TransferRepositoryInterface::class, TransferRepository::class);
        $this->app->bind(AdjustmentRepositoryInterface::class, AdjustmentRepository::class);

        // Cross-module providers
        $this->app->bind(InventoryStockProviderInterface::class, InventoryStockProvider::class);
        $this->app->bind(InventoryValuationProviderInterface::class, InventoryValuationProvider::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'inventory');
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        if (file_exists(__DIR__.'/../Routes/api.php')) {
            $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
        }
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }
}
