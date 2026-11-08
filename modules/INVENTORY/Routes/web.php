<?php

use Illuminate\Support\Facades\Route;
use Modules\INVENTORY\Http\Controllers\AdjustmentController;
use Modules\INVENTORY\Http\Controllers\BarcodeController;
use Modules\INVENTORY\Http\Controllers\BrandController;
use Modules\INVENTORY\Http\Controllers\CategoryController;
use Modules\INVENTORY\Http\Controllers\CountController;
use Modules\INVENTORY\Http\Controllers\KitController;
use Modules\INVENTORY\Http\Controllers\MovementController;
use Modules\INVENTORY\Http\Controllers\OptionsController;
use Modules\INVENTORY\Http\Controllers\PosLookupController;
use Modules\INVENTORY\Http\Controllers\ProductController;
use Modules\INVENTORY\Http\Controllers\ReportController;
use Modules\INVENTORY\Http\Controllers\StockController;
use Modules\INVENTORY\Http\Controllers\TransferController;
use Modules\INVENTORY\Http\Controllers\UomController;
use Modules\INVENTORY\Http\Controllers\WarehouseController;
use Spatie\Permission\Middleware\PermissionMiddleware;

Route::middleware(['web', 'auth'])->prefix('inventory')->name('inventory.')->group(function (): void {

    Route::get('api/options/{type}', OptionsController::class)->where('type', '[a-z\\-]+')->name('api.options');
    Route::get('api/pos-product/{key}', PosLookupController::class)->name('api.pos-product');

    // ── Products ─────────────────────────────────────────────────────────
    Route::get('products/barcode-print', [BarcodeController::class, 'index'])->middleware(PermissionMiddleware::class.':inventory.products.view')->name('products.barcode-print');
    Route::post('products/barcode-pdf', [BarcodeController::class, 'pdf'])->middleware(PermissionMiddleware::class.':inventory.products.view')->name('products.barcode-pdf');
    Route::post('products/barcode-zpl', [BarcodeController::class, 'zpl'])->middleware(PermissionMiddleware::class.':inventory.products.view')->name('products.barcode-zpl');
    Route::get('products', [ProductController::class, 'index'])->middleware(PermissionMiddleware::class.':inventory.products.view')->name('products.index');
    Route::get('products/create', [ProductController::class, 'create'])->middleware(PermissionMiddleware::class.':inventory.products.create')->name('products.create');
    Route::post('products', [ProductController::class, 'store'])->middleware(PermissionMiddleware::class.':inventory.products.create')->name('products.store');
    Route::get('products/{product}', [ProductController::class, 'show'])->middleware(PermissionMiddleware::class.':inventory.products.view')->name('products.show');
    Route::get('products/{product}/edit', [ProductController::class, 'edit'])->middleware(PermissionMiddleware::class.':inventory.products.update')->name('products.edit');
    Route::put('products/{product}', [ProductController::class, 'update'])->middleware(PermissionMiddleware::class.':inventory.products.update')->name('products.update');
    Route::delete('products/{product}', [ProductController::class, 'destroy'])->middleware(PermissionMiddleware::class.':inventory.products.delete')->name('products.destroy');

    // ── Categories ───────────────────────────────────────────────────────
    Route::get('categories', [CategoryController::class, 'index'])->middleware(PermissionMiddleware::class.':inventory.categories.manage')->name('categories.index');
    Route::get('categories/create', [CategoryController::class, 'create'])->middleware(PermissionMiddleware::class.':inventory.categories.manage')->name('categories.create');
    Route::post('categories', [CategoryController::class, 'store'])->middleware(PermissionMiddleware::class.':inventory.categories.manage')->name('categories.store');
    Route::get('categories/{category}/edit', [CategoryController::class, 'edit'])->middleware(PermissionMiddleware::class.':inventory.categories.manage')->name('categories.edit');
    Route::put('categories/{category}', [CategoryController::class, 'update'])->middleware(PermissionMiddleware::class.':inventory.categories.manage')->name('categories.update');
    Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->middleware(PermissionMiddleware::class.':inventory.categories.manage')->name('categories.destroy');

    // ── Brands (simple) ──────────────────────────────────────────────────
    Route::get('brands', [BrandController::class, 'index'])->middleware(PermissionMiddleware::class.':inventory.brands.manage')->name('brands.index');
    Route::get('brands/create', [BrandController::class, 'create'])->middleware(PermissionMiddleware::class.':inventory.brands.manage')->name('brands.create');
    Route::post('brands', [BrandController::class, 'store'])->middleware(PermissionMiddleware::class.':inventory.brands.manage')->name('brands.store');
    Route::get('brands/{brand}/edit', [BrandController::class, 'edit'])->middleware(PermissionMiddleware::class.':inventory.brands.manage')->name('brands.edit');
    Route::put('brands/{brand}', [BrandController::class, 'update'])->middleware(PermissionMiddleware::class.':inventory.brands.manage')->name('brands.update');
    Route::delete('brands/{brand}', [BrandController::class, 'destroy'])->middleware(PermissionMiddleware::class.':inventory.brands.manage')->name('brands.destroy');

    // ── UOMs ─────────────────────────────────────────────────────────────
    Route::get('uoms', [UomController::class, 'index'])->middleware(PermissionMiddleware::class.':inventory.uoms.manage')->name('uoms.index');
    Route::get('uoms/create', [UomController::class, 'create'])->middleware(PermissionMiddleware::class.':inventory.uoms.manage')->name('uoms.create');
    Route::post('uoms', [UomController::class, 'store'])->middleware(PermissionMiddleware::class.':inventory.uoms.manage')->name('uoms.store');
    Route::get('uoms/{uom}/edit', [UomController::class, 'edit'])->middleware(PermissionMiddleware::class.':inventory.uoms.manage')->name('uoms.edit');
    Route::put('uoms/{uom}', [UomController::class, 'update'])->middleware(PermissionMiddleware::class.':inventory.uoms.manage')->name('uoms.update');
    Route::delete('uoms/{uom}', [UomController::class, 'destroy'])->middleware(PermissionMiddleware::class.':inventory.uoms.manage')->name('uoms.destroy');

    // ── Warehouses ───────────────────────────────────────────────────────
    Route::get('warehouses', [WarehouseController::class, 'index'])->middleware(PermissionMiddleware::class.':inventory.warehouses.manage')->name('warehouses.index');
    Route::get('warehouses/create', [WarehouseController::class, 'create'])->middleware(PermissionMiddleware::class.':inventory.warehouses.manage')->name('warehouses.create');
    Route::post('warehouses', [WarehouseController::class, 'store'])->middleware(PermissionMiddleware::class.':inventory.warehouses.manage')->name('warehouses.store');
    Route::get('warehouses/{warehouse}', [WarehouseController::class, 'show'])->middleware(PermissionMiddleware::class.':inventory.warehouses.manage')->name('warehouses.show');
    Route::get('warehouses/{warehouse}/edit', [WarehouseController::class, 'edit'])->middleware(PermissionMiddleware::class.':inventory.warehouses.manage')->name('warehouses.edit');
    Route::put('warehouses/{warehouse}', [WarehouseController::class, 'update'])->middleware(PermissionMiddleware::class.':inventory.warehouses.manage')->name('warehouses.update');
    Route::delete('warehouses/{warehouse}', [WarehouseController::class, 'destroy'])->middleware(PermissionMiddleware::class.':inventory.warehouses.manage')->name('warehouses.destroy');

    // ── Stocks ───────────────────────────────────────────────────────────
    Route::get('stocks', [StockController::class, 'index'])->middleware(PermissionMiddleware::class.':inventory.stocks.view')->name('stocks.index');

    // ── Stock Ledger (Movements) ─────────────────────────────────────────
    Route::get('movements', [MovementController::class, 'index'])->middleware(PermissionMiddleware::class.':inventory.movements.view')->name('movements.index');

    // ── Transfers ────────────────────────────────────────────────────────
    Route::get('transfers', [TransferController::class, 'index'])->middleware(PermissionMiddleware::class.':inventory.transfers.manage')->name('transfers.index');
    Route::get('transfers/create', [TransferController::class, 'create'])->middleware(PermissionMiddleware::class.':inventory.transfers.manage')->name('transfers.create');
    Route::post('transfers', [TransferController::class, 'store'])->middleware(PermissionMiddleware::class.':inventory.transfers.manage')->name('transfers.store');
    Route::get('transfers/{transfer}', [TransferController::class, 'show'])->middleware(PermissionMiddleware::class.':inventory.transfers.manage')->name('transfers.show');
    Route::get('transfers/{transfer}/edit', [TransferController::class, 'edit'])->middleware(PermissionMiddleware::class.':inventory.transfers.manage')->name('transfers.edit');
    Route::put('transfers/{transfer}', [TransferController::class, 'update'])->middleware(PermissionMiddleware::class.':inventory.transfers.manage')->name('transfers.update');
    Route::delete('transfers/{transfer}', [TransferController::class, 'destroy'])->middleware(PermissionMiddleware::class.':inventory.transfers.manage')->name('transfers.destroy');
    Route::post('transfers/{transfer}/ship', [TransferController::class, 'ship'])->middleware(PermissionMiddleware::class.':inventory.transfers.manage')->name('transfers.ship');
    Route::post('transfers/{transfer}/receive', [TransferController::class, 'receive'])->middleware(PermissionMiddleware::class.':inventory.transfers.manage')->name('transfers.receive');
    Route::post('transfers/{transfer}/cancel', [TransferController::class, 'cancel'])->middleware(PermissionMiddleware::class.':inventory.transfers.manage')->name('transfers.cancel');

    // ── Adjustments ──────────────────────────────────────────────────────
    Route::get('adjustments', [AdjustmentController::class, 'index'])->middleware(PermissionMiddleware::class.':inventory.adjustments.manage')->name('adjustments.index');
    Route::get('adjustments/create', [AdjustmentController::class, 'create'])->middleware(PermissionMiddleware::class.':inventory.adjustments.manage')->name('adjustments.create');
    Route::post('adjustments', [AdjustmentController::class, 'store'])->middleware(PermissionMiddleware::class.':inventory.adjustments.manage')->name('adjustments.store');
    Route::get('adjustments/{adjustment}', [AdjustmentController::class, 'show'])->middleware(PermissionMiddleware::class.':inventory.adjustments.manage')->name('adjustments.show');
    Route::get('adjustments/{adjustment}/edit', [AdjustmentController::class, 'edit'])->middleware(PermissionMiddleware::class.':inventory.adjustments.manage')->name('adjustments.edit');
    Route::put('adjustments/{adjustment}', [AdjustmentController::class, 'update'])->middleware(PermissionMiddleware::class.':inventory.adjustments.manage')->name('adjustments.update');
    Route::delete('adjustments/{adjustment}', [AdjustmentController::class, 'destroy'])->middleware(PermissionMiddleware::class.':inventory.adjustments.manage')->name('adjustments.destroy');
    Route::post('adjustments/{adjustment}/approve', [AdjustmentController::class, 'approve'])->middleware(PermissionMiddleware::class.':inventory.adjustments.approve')->name('adjustments.approve');
    Route::post('adjustments/{adjustment}/post', [AdjustmentController::class, 'post'])->middleware(PermissionMiddleware::class.':inventory.adjustments.approve')->name('adjustments.post');

    // ── Physical Counts ──────────────────────────────────────────────────
    Route::get('counts', [CountController::class, 'index'])->middleware(PermissionMiddleware::class.':inventory.counts.view')->name('counts.index');
    Route::get('counts/create', [CountController::class, 'create'])->middleware(PermissionMiddleware::class.':inventory.counts.manage')->name('counts.create');
    Route::post('counts', [CountController::class, 'store'])->middleware(PermissionMiddleware::class.':inventory.counts.manage')->name('counts.store');
    Route::get('counts/{count}', [CountController::class, 'show'])->middleware(PermissionMiddleware::class.':inventory.counts.view')->name('counts.show');

    // ── Kits / BOM Kitting ───────────────────────────────────────────────
    Route::get('kits', [KitController::class, 'index'])->middleware(PermissionMiddleware::class.':inventory.kits.view')->name('kits.index');
    Route::get('kits/create', [KitController::class, 'create'])->middleware(PermissionMiddleware::class.':inventory.kits.manage')->name('kits.create');
    Route::post('kits', [KitController::class, 'store'])->middleware(PermissionMiddleware::class.':inventory.kits.manage')->name('kits.store');
    Route::get('kits/{kit}', [KitController::class, 'show'])->middleware(PermissionMiddleware::class.':inventory.kits.view')->name('kits.show');
    Route::post('kits/{kit}/complete', [KitController::class, 'complete'])->middleware(PermissionMiddleware::class.':inventory.kits.manage')->name('kits.complete');
    Route::post('kits/{kit}/cancel', [KitController::class, 'cancel'])->middleware(PermissionMiddleware::class.':inventory.kits.manage')->name('kits.cancel');

    // ── Reports (FIFO, Reorder/ABC, Lots) ──────────────────────────────────
    Route::get('reports/fifo', [ReportController::class, 'fifo'])->middleware(PermissionMiddleware::class.':inventory.stocks.view')->name('reports.fifo');
    Route::get('reports/reorder', [ReportController::class, 'reorder'])->middleware(PermissionMiddleware::class.':inventory.stocks.view')->name('reports.reorder');
    Route::get('reports/lots', [ReportController::class, 'lots'])->middleware(PermissionMiddleware::class.':inventory.stocks.view')->name('reports.lots');
});
