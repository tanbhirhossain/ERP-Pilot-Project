<?php

use Illuminate\Support\Facades\Route;
use Modules\SALES\Http\Controllers\InvoiceController;
use Modules\SALES\Http\Controllers\OptionsController;
use Modules\SALES\Http\Controllers\OrderController;
use Modules\SALES\Http\Controllers\PosController;
use Modules\SALES\Http\Controllers\QuotationController;
use Spatie\Permission\Middleware\PermissionMiddleware;

Route::middleware(['web', 'auth'])->prefix('sales')->name('sales.')->group(function (): void {

    Route::get('api/options/{type}', OptionsController::class)->where('type', '[a-z\-]+')->name('api.options');

    // ── Quotations ─────────────────────────────────────────────────────
    Route::get('quotations', [QuotationController::class, 'index'])->middleware(PermissionMiddleware::class.':sales.quotations.view')->name('quotations.index');
    Route::get('quotations/create', [QuotationController::class, 'create'])->middleware(PermissionMiddleware::class.':sales.quotations.manage')->name('quotations.create');
    Route::post('quotations', [QuotationController::class, 'store'])->middleware(PermissionMiddleware::class.':sales.quotations.manage')->name('quotations.store');
    Route::get('quotations/{quotation}', [QuotationController::class, 'show'])->middleware(PermissionMiddleware::class.':sales.quotations.view')->name('quotations.show');
    Route::get('quotations/{quotation}/edit', [QuotationController::class, 'edit'])->middleware(PermissionMiddleware::class.':sales.quotations.manage')->name('quotations.edit');
    Route::put('quotations/{quotation}', [QuotationController::class, 'update'])->middleware(PermissionMiddleware::class.':sales.quotations.manage')->name('quotations.update');
    Route::delete('quotations/{quotation}', [QuotationController::class, 'destroy'])->middleware(PermissionMiddleware::class.':sales.quotations.manage')->name('quotations.destroy');
    Route::post('quotations/{quotation}/send', [QuotationController::class, 'send'])->middleware(PermissionMiddleware::class.':sales.quotations.manage')->name('quotations.send');
    Route::post('quotations/{quotation}/accept', [QuotationController::class, 'accept'])->middleware(PermissionMiddleware::class.':sales.quotations.manage')->name('quotations.accept');
    Route::post('quotations/{quotation}/convert', [QuotationController::class, 'convert'])->middleware(PermissionMiddleware::class.':sales.quotations.manage')->name('quotations.convert');

    // ── Orders ─────────────────────────────────────────────────────────
    Route::get('orders', [OrderController::class, 'index'])->middleware(PermissionMiddleware::class.':sales.orders.view')->name('orders.index');
    Route::get('orders/create', [OrderController::class, 'create'])->middleware(PermissionMiddleware::class.':sales.orders.manage')->name('orders.create');
    Route::post('orders', [OrderController::class, 'store'])->middleware(PermissionMiddleware::class.':sales.orders.manage')->name('orders.store');
    Route::get('orders/{order}', [OrderController::class, 'show'])->middleware(PermissionMiddleware::class.':sales.orders.view')->name('orders.show');
    Route::get('orders/{order}/edit', [OrderController::class, 'edit'])->middleware(PermissionMiddleware::class.':sales.orders.manage')->name('orders.edit');
    Route::put('orders/{order}', [OrderController::class, 'update'])->middleware(PermissionMiddleware::class.':sales.orders.manage')->name('orders.update');
    Route::delete('orders/{order}', [OrderController::class, 'destroy'])->middleware(PermissionMiddleware::class.':sales.orders.manage')->name('orders.destroy');
    Route::post('orders/{order}/confirm', [OrderController::class, 'confirm'])->middleware(PermissionMiddleware::class.':sales.orders.confirm')->name('orders.confirm');
    Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->middleware(PermissionMiddleware::class.':sales.orders.manage')->name('orders.cancel');
    Route::post('orders/{order}/create-invoice', [OrderController::class, 'createInvoice'])->middleware(PermissionMiddleware::class.':sales.invoices.manage')->name('orders.create-invoice');

    // ── Invoices ───────────────────────────────────────────────────────
    Route::get('invoices', [InvoiceController::class, 'index'])->middleware(PermissionMiddleware::class.':sales.invoices.view')->name('invoices.index');
    Route::get('invoices/create', [InvoiceController::class, 'create'])->middleware(PermissionMiddleware::class.':sales.invoices.manage')->name('invoices.create');
    Route::post('invoices', [InvoiceController::class, 'store'])->middleware(PermissionMiddleware::class.':sales.invoices.manage')->name('invoices.store');
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->middleware(PermissionMiddleware::class.':sales.invoices.view')->name('invoices.show');
    Route::get('invoices/{invoice}/edit', [InvoiceController::class, 'edit'])->middleware(PermissionMiddleware::class.':sales.invoices.manage')->name('invoices.edit');
    Route::put('invoices/{invoice}', [InvoiceController::class, 'update'])->middleware(PermissionMiddleware::class.':sales.invoices.manage')->name('invoices.update');
    Route::delete('invoices/{invoice}', [InvoiceController::class, 'destroy'])->middleware(PermissionMiddleware::class.':sales.invoices.manage')->name('invoices.destroy');
    Route::post('invoices/{invoice}/post', [InvoiceController::class, 'post'])->middleware(PermissionMiddleware::class.':sales.invoices.post')->name('invoices.post');
    Route::post('invoices/{invoice}/pay', [InvoiceController::class, 'pay'])->middleware(PermissionMiddleware::class.':sales.invoices.manage')->name('invoices.pay');
    Route::get('invoices/{invoice}/mushak', [InvoiceController::class, 'mushak'])->middleware(PermissionMiddleware::class.':sales.invoices.view')->name('invoices.mushak');

    // ── Deliveries (Challan) ───────────────────────────────────────────
    Route::get('deliveries', [\Modules\SALES\Http\Controllers\DeliveryController::class, 'index'])->middleware(PermissionMiddleware::class.':sales.deliveries.view')->name('deliveries.index');
    Route::get('deliveries/create', [\Modules\SALES\Http\Controllers\DeliveryController::class, 'create'])->middleware(PermissionMiddleware::class.':sales.deliveries.manage')->name('deliveries.create');
    Route::post('deliveries', [\Modules\SALES\Http\Controllers\DeliveryController::class, 'store'])->middleware(PermissionMiddleware::class.':sales.deliveries.manage')->name('deliveries.store');
    Route::get('deliveries/{delivery}', [\Modules\SALES\Http\Controllers\DeliveryController::class, 'show'])->middleware(PermissionMiddleware::class.':sales.deliveries.view')->name('deliveries.show');
    Route::get('deliveries/{delivery}/edit', [\Modules\SALES\Http\Controllers\DeliveryController::class, 'edit'])->middleware(PermissionMiddleware::class.':sales.deliveries.manage')->name('deliveries.edit');
    Route::put('deliveries/{delivery}', [\Modules\SALES\Http\Controllers\DeliveryController::class, 'update'])->middleware(PermissionMiddleware::class.':sales.deliveries.manage')->name('deliveries.update');
    Route::delete('deliveries/{delivery}', [\Modules\SALES\Http\Controllers\DeliveryController::class, 'destroy'])->middleware(PermissionMiddleware::class.':sales.deliveries.manage')->name('deliveries.destroy');
    Route::post('deliveries/{delivery}/ship', [\Modules\SALES\Http\Controllers\DeliveryController::class, 'ship'])->middleware(PermissionMiddleware::class.':sales.deliveries.manage')->name('deliveries.ship');
    Route::post('deliveries/{delivery}/cancel', [\Modules\SALES\Http\Controllers\DeliveryController::class, 'cancel'])->middleware(PermissionMiddleware::class.':sales.deliveries.manage')->name('deliveries.cancel');

    // ── Sales Returns (Credit Note) ────────────────────────────────────
    Route::get('returns', [\Modules\SALES\Http\Controllers\SalesReturnController::class, 'index'])->middleware(PermissionMiddleware::class.':sales.returns.view')->name('returns.index');
    Route::get('returns/create', [\Modules\SALES\Http\Controllers\SalesReturnController::class, 'create'])->middleware(PermissionMiddleware::class.':sales.returns.manage')->name('returns.create');
    Route::post('returns', [\Modules\SALES\Http\Controllers\SalesReturnController::class, 'store'])->middleware(PermissionMiddleware::class.':sales.returns.manage')->name('returns.store');
    Route::get('returns/{salesReturn}', [\Modules\SALES\Http\Controllers\SalesReturnController::class, 'show'])->middleware(PermissionMiddleware::class.':sales.returns.view')->name('returns.show');
    Route::get('returns/{salesReturn}/edit', [\Modules\SALES\Http\Controllers\SalesReturnController::class, 'edit'])->middleware(PermissionMiddleware::class.':sales.returns.manage')->name('returns.edit');
    Route::put('returns/{salesReturn}', [\Modules\SALES\Http\Controllers\SalesReturnController::class, 'update'])->middleware(PermissionMiddleware::class.':sales.returns.manage')->name('returns.update');
    Route::delete('returns/{salesReturn}', [\Modules\SALES\Http\Controllers\SalesReturnController::class, 'destroy'])->middleware(PermissionMiddleware::class.':sales.returns.manage')->name('returns.destroy');
    Route::post('returns/{salesReturn}/approve', [\Modules\SALES\Http\Controllers\SalesReturnController::class, 'approve'])->middleware(PermissionMiddleware::class.':sales.returns.approve')->name('returns.approve');
    Route::post('returns/{salesReturn}/cancel', [\Modules\SALES\Http\Controllers\SalesReturnController::class, 'cancel'])->middleware(PermissionMiddleware::class.':sales.returns.manage')->name('returns.cancel');

    // ── POS ────────────────────────────────────────────────────────────
    Route::get('pos/terminal', [PosController::class, 'terminal'])->middleware(PermissionMiddleware::class.':pos.terminal.sell')->name('pos.terminal');
    Route::post('pos/sell', [PosController::class, 'sell'])->middleware(PermissionMiddleware::class.':pos.terminal.sell')->name('pos.sell');
    Route::get('pos/sessions', [PosController::class, 'sessions'])->middleware(PermissionMiddleware::class.':pos.sessions.manage')->name('pos.sessions');
    Route::post('pos/sessions/open', [PosController::class, 'openSession'])->middleware(PermissionMiddleware::class.':pos.sessions.manage')->name('pos.sessions.open');
    Route::post('pos/sessions/{session}/close', [PosController::class, 'closeSession'])->middleware(PermissionMiddleware::class.':pos.sessions.manage')->name('pos.sessions.close');
    Route::get('pos/history', [PosController::class, 'history'])->middleware(PermissionMiddleware::class.':pos.terminal.sell')->name('pos.history');
    Route::get('pos/{sale}', [PosController::class, 'show'])->middleware(PermissionMiddleware::class.':pos.terminal.sell')->name('pos.show');
    Route::get('pos/{sale}/receipt', [PosController::class, 'receipt'])->middleware(PermissionMiddleware::class.':pos.terminal.sell')->name('pos.receipt');
    Route::post('pos/{sale}/void', [PosController::class, 'void'])->middleware(PermissionMiddleware::class.':pos.sessions.manage')->name('pos.void');
});
