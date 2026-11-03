<?php

use Illuminate\Support\Facades\Route;
use Modules\PARTNER\Http\Controllers\OptionsController;
use Modules\PARTNER\Http\Controllers\PartnerController;
use Modules\PARTNER\Http\Controllers\PartnerGroupController;
use Modules\PARTNER\Http\Controllers\PriceListController;
use Spatie\Permission\Middleware\PermissionMiddleware;

Route::middleware(['web', 'auth'])->prefix('partner')->name('partner.')->group(function (): void {

    // JSON options for searchable selects
    Route::get('api/options/{type}', OptionsController::class)->where('type', '[a-z\\-]+')->name('api.options');

    // ── Vendors ──────────────────────────────────────────────────────────
    Route::get('vendors', [PartnerController::class, 'index'])->defaults('type', 'vendor')->middleware(PermissionMiddleware::class.':partner.vendors.view')->name('vendors.index');
    Route::get('vendors/create', [PartnerController::class, 'create'])->defaults('type', 'vendor')->middleware(PermissionMiddleware::class.':partner.vendors.create')->name('vendors.create');
    Route::post('vendors', [PartnerController::class, 'store'])->middleware(PermissionMiddleware::class.':partner.vendors.create')->name('vendors.store');
    Route::get('vendors/{partner}', [PartnerController::class, 'show'])->middleware(PermissionMiddleware::class.':partner.vendors.view')->name('vendors.show');
    Route::get('vendors/{partner}/edit', [PartnerController::class, 'edit'])->middleware(PermissionMiddleware::class.':partner.vendors.update')->name('vendors.edit');
    Route::put('vendors/{partner}', [PartnerController::class, 'update'])->middleware(PermissionMiddleware::class.':partner.vendors.update')->name('vendors.update');
    Route::delete('vendors/{partner}', [PartnerController::class, 'destroy'])->middleware(PermissionMiddleware::class.':partner.vendors.delete')->name('vendors.destroy');

    // ── Customers ────────────────────────────────────────────────────────
    Route::get('customers', [PartnerController::class, 'index'])->defaults('type', 'customer')->middleware(PermissionMiddleware::class.':partner.customers.view')->name('customers.index');
    Route::get('customers/create', [PartnerController::class, 'create'])->defaults('type', 'customer')->middleware(PermissionMiddleware::class.':partner.customers.create')->name('customers.create');
    Route::post('customers', [PartnerController::class, 'store'])->middleware(PermissionMiddleware::class.':partner.customers.create')->name('customers.store');
    Route::get('customers/{partner}', [PartnerController::class, 'show'])->middleware(PermissionMiddleware::class.':partner.customers.view')->name('customers.show');
    Route::get('customers/{partner}/edit', [PartnerController::class, 'edit'])->middleware(PermissionMiddleware::class.':partner.customers.update')->name('customers.edit');
    Route::put('customers/{partner}', [PartnerController::class, 'update'])->middleware(PermissionMiddleware::class.':partner.customers.update')->name('customers.update');
    Route::delete('customers/{partner}', [PartnerController::class, 'destroy'])->middleware(PermissionMiddleware::class.':partner.customers.delete')->name('customers.destroy');

    // ── Partners generic (type=both, detail, actions) ──────────────────
    Route::get('partners/{partner}', [PartnerController::class, 'show'])->middleware(PermissionMiddleware::class.':partner.vendors.view')->name('partners.show');
    Route::post('partners/{partner}/toggle-active', [PartnerController::class, 'toggleActive'])->middleware(PermissionMiddleware::class.':partner.vendors.update')->name('partners.toggle-active');
    Route::post('partners/{partner}/verify', [PartnerController::class, 'verify'])->middleware(PermissionMiddleware::class.':partner.verifications.manage')->name('partners.verify');
    Route::post('partners/{partner}/notes', [PartnerController::class, 'addNote'])->middleware(PermissionMiddleware::class.':partner.vendors.view')->name('partners.notes.store');
    Route::post('partners/{partner}/documents', [PartnerController::class, 'addDocument'])->middleware(PermissionMiddleware::class.':partner.vendors.view')->name('partners.documents.store');
    Route::get('partners/{partner}/credit-check', [PartnerController::class, 'creditCheck'])->middleware(PermissionMiddleware::class.':partner.vendors.view')->name('partners.credit-check');

    // ── Partner Groups ───────────────────────────────────────────────────
    Route::get('groups', [PartnerGroupController::class, 'index'])->middleware(PermissionMiddleware::class.':partner.groups.manage')->name('groups.index');
    Route::get('groups/create', [PartnerGroupController::class, 'create'])->middleware(PermissionMiddleware::class.':partner.groups.manage')->name('groups.create');
    Route::post('groups', [PartnerGroupController::class, 'store'])->middleware(PermissionMiddleware::class.':partner.groups.manage')->name('groups.store');
    Route::get('groups/{group}/edit', [PartnerGroupController::class, 'edit'])->middleware(PermissionMiddleware::class.':partner.groups.manage')->name('groups.edit');
    Route::put('groups/{group}', [PartnerGroupController::class, 'update'])->middleware(PermissionMiddleware::class.':partner.groups.manage')->name('groups.update');
    Route::delete('groups/{group}', [PartnerGroupController::class, 'destroy'])->middleware(PermissionMiddleware::class.':partner.groups.manage')->name('groups.destroy');

    // ── Price Lists ──────────────────────────────────────────────────────
    Route::get('pricelists', [PriceListController::class, 'index'])->middleware(PermissionMiddleware::class.':partner.pricelists.manage')->name('pricelists.index');
    Route::get('pricelists/create', [PriceListController::class, 'create'])->middleware(PermissionMiddleware::class.':partner.pricelists.manage')->name('pricelists.create');
    Route::post('pricelists', [PriceListController::class, 'store'])->middleware(PermissionMiddleware::class.':partner.pricelists.manage')->name('pricelists.store');
    Route::get('pricelists/{priceList}', [PriceListController::class, 'show'])->middleware(PermissionMiddleware::class.':partner.pricelists.manage')->name('pricelists.show');
    Route::get('pricelists/{priceList}/edit', [PriceListController::class, 'edit'])->middleware(PermissionMiddleware::class.':partner.pricelists.manage')->name('pricelists.edit');
    Route::put('pricelists/{priceList}', [PriceListController::class, 'update'])->middleware(PermissionMiddleware::class.':partner.pricelists.manage')->name('pricelists.update');
    Route::delete('pricelists/{priceList}', [PriceListController::class, 'destroy'])->middleware(PermissionMiddleware::class.':partner.pricelists.manage')->name('pricelists.destroy');
});
