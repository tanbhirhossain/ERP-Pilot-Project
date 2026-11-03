<?php

use Illuminate\Support\Facades\Route;
use Modules\PURCHASE\Http\Controllers\PurchaseOrderController;
use Modules\PURCHASE\Http\Controllers\GoodsReceiptController;
use Modules\PURCHASE\Http\Controllers\VendorBillController;
use Modules\PURCHASE\Http\Controllers\PurchaseReturnController;
use Spatie\Permission\Middleware\PermissionMiddleware;

Route::middleware(['web','auth'])->prefix('purchase')->name('purchase.')->group(function (): void {
    // Orders
    Route::get('orders', [PurchaseOrderController::class,'index'])->middleware(PermissionMiddleware::class.':purchase.orders.view')->name('orders.index');
    Route::get('orders/create', [PurchaseOrderController::class,'create'])->middleware(PermissionMiddleware::class.':purchase.orders.manage')->name('orders.create');
    Route::post('orders', [PurchaseOrderController::class,'store'])->middleware(PermissionMiddleware::class.':purchase.orders.manage')->name('orders.store');
    Route::get('orders/{order}', [PurchaseOrderController::class,'show'])->middleware(PermissionMiddleware::class.':purchase.orders.view')->name('orders.show');
    Route::get('orders/{order}/edit', [PurchaseOrderController::class,'edit'])->middleware(PermissionMiddleware::class.':purchase.orders.manage')->name('orders.edit');
    Route::put('orders/{order}', [PurchaseOrderController::class,'update'])->middleware(PermissionMiddleware::class.':purchase.orders.manage')->name('orders.update');
    Route::delete('orders/{order}', [PurchaseOrderController::class,'destroy'])->middleware(PermissionMiddleware::class.':purchase.orders.manage')->name('orders.destroy');
    Route::post('orders/{order}/confirm', [PurchaseOrderController::class,'confirm'])->middleware(PermissionMiddleware::class.':purchase.orders.confirm')->name('orders.confirm');
    Route::post('orders/{order}/approve', [PurchaseOrderController::class,'approve'])->middleware(PermissionMiddleware::class.':purchase.orders.approve')->name('orders.approve');
    Route::post('orders/{order}/send', [PurchaseOrderController::class,'send'])->middleware(PermissionMiddleware::class.':purchase.orders.manage')->name('orders.send');
    Route::post('orders/{order}/cancel', [PurchaseOrderController::class,'cancel'])->middleware(PermissionMiddleware::class.':purchase.orders.manage')->name('orders.cancel');

    // GRN
    Route::get('receipts', [GoodsReceiptController::class,'index'])->middleware(PermissionMiddleware::class.':purchase.receipts.view')->name('receipts.index');
    Route::get('receipts/create', [GoodsReceiptController::class,'create'])->middleware(PermissionMiddleware::class.':purchase.receipts.manage')->name('receipts.create');
    Route::post('receipts', [GoodsReceiptController::class,'store'])->middleware(PermissionMiddleware::class.':purchase.receipts.manage')->name('receipts.store');
    Route::get('receipts/{receipt}', [GoodsReceiptController::class,'show'])->middleware(PermissionMiddleware::class.':purchase.receipts.view')->name('receipts.show');
    Route::get('receipts/{receipt}/edit', [GoodsReceiptController::class,'edit'])->middleware(PermissionMiddleware::class.':purchase.receipts.manage')->name('receipts.edit');
    Route::put('receipts/{receipt}', [GoodsReceiptController::class,'update'])->middleware(PermissionMiddleware::class.':purchase.receipts.manage')->name('receipts.update');
    Route::delete('receipts/{receipt}', [GoodsReceiptController::class,'destroy'])->middleware(PermissionMiddleware::class.':purchase.receipts.manage')->name('receipts.destroy');
    Route::post('receipts/{receipt}/post', [GoodsReceiptController::class,'post'])->middleware(PermissionMiddleware::class.':purchase.receipts.post')->name('receipts.post');
    Route::post('receipts/{receipt}/void', [GoodsReceiptController::class,'void'])->middleware(PermissionMiddleware::class.':purchase.receipts.void')->name('receipts.void');

    // Bills
    Route::get('bills', [VendorBillController::class,'index'])->middleware(PermissionMiddleware::class.':purchase.bills.view')->name('bills.index');
    Route::get('bills/create', [VendorBillController::class,'create'])->middleware(PermissionMiddleware::class.':purchase.bills.manage')->name('bills.create');
    Route::post('bills', [VendorBillController::class,'store'])->middleware(PermissionMiddleware::class.':purchase.bills.manage')->name('bills.store');
    Route::get('bills/{bill}', [VendorBillController::class,'show'])->middleware(PermissionMiddleware::class.':purchase.bills.view')->name('bills.show');
    Route::get('bills/{bill}/edit', [VendorBillController::class,'edit'])->middleware(PermissionMiddleware::class.':purchase.bills.manage')->name('bills.edit');
    Route::put('bills/{bill}', [VendorBillController::class,'update'])->middleware(PermissionMiddleware::class.':purchase.bills.manage')->name('bills.update');
    Route::delete('bills/{bill}', [VendorBillController::class,'destroy'])->middleware(PermissionMiddleware::class.':purchase.bills.manage')->name('bills.destroy');
    Route::post('bills/{bill}/post', [VendorBillController::class,'post'])->middleware(PermissionMiddleware::class.':purchase.bills.post')->name('bills.post');
    Route::post('bills/{bill}/void', [VendorBillController::class,'void'])->middleware(PermissionMiddleware::class.':purchase.bills.void')->name('bills.void');
    Route::post('bills/{bill}/pay', [VendorBillController::class,'pay'])->middleware(PermissionMiddleware::class.':purchase.payments.manage')->name('bills.pay');

    // Returns (Purchase Return / Debit Note)
    Route::get('returns', [PurchaseReturnController::class,'index'])->middleware(PermissionMiddleware::class.':purchase.returns.view')->name('returns.index');
    Route::get('returns/create', [PurchaseReturnController::class,'create'])->middleware(PermissionMiddleware::class.':purchase.returns.manage')->name('returns.create');
    Route::post('returns', [PurchaseReturnController::class,'store'])->middleware(PermissionMiddleware::class.':purchase.returns.manage')->name('returns.store');
    Route::get('returns/{return}', [PurchaseReturnController::class,'show'])->middleware(PermissionMiddleware::class.':purchase.returns.view')->name('returns.show');
    Route::get('returns/{return}/edit', [PurchaseReturnController::class,'edit'])->middleware(PermissionMiddleware::class.':purchase.returns.manage')->name('returns.edit');
    Route::put('returns/{return}', [PurchaseReturnController::class,'update'])->middleware(PermissionMiddleware::class.':purchase.returns.manage')->name('returns.update');
    Route::delete('returns/{return}', [PurchaseReturnController::class,'destroy'])->middleware(PermissionMiddleware::class.':purchase.returns.manage')->name('returns.destroy');
    Route::post('returns/{return}/post', [PurchaseReturnController::class,'post'])->middleware(PermissionMiddleware::class.':purchase.returns.post')->name('returns.post');

    // Landed Costs (Freight/Customs allocation)
    Route::get('landed-costs', [\Modules\PURCHASE\Http\Controllers\LandedCostController::class,'index'])->middleware(PermissionMiddleware::class.':purchase.landed.view')->name('landed-costs.index');
    Route::get('landed-costs/create', [\Modules\PURCHASE\Http\Controllers\LandedCostController::class,'create'])->middleware(PermissionMiddleware::class.':purchase.landed.manage')->name('landed-costs.create');
    Route::post('landed-costs', [\Modules\PURCHASE\Http\Controllers\LandedCostController::class,'store'])->middleware(PermissionMiddleware::class.':purchase.landed.manage')->name('landed-costs.store');
    Route::get('landed-costs/{landedCost}', [\Modules\PURCHASE\Http\Controllers\LandedCostController::class,'show'])->middleware(PermissionMiddleware::class.':purchase.landed.view')->name('landed-costs.show');
    Route::post('landed-costs/{landedCost}/allocate', [\Modules\PURCHASE\Http\Controllers\LandedCostController::class,'post'])->middleware(PermissionMiddleware::class.':purchase.landed.manage')->name('landed-costs.allocate');
    Route::post('landed-costs/{landedCost}/cancel', [\Modules\PURCHASE\Http\Controllers\LandedCostController::class,'cancel'])->middleware(PermissionMiddleware::class.':purchase.landed.manage')->name('landed-costs.cancel');
});
