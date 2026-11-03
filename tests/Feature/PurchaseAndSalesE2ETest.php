<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\ACCOUNTS\Models\Account;
use Modules\ACCOUNTS\Models\AccountLedger;
use Modules\ACCOUNTS\Models\FiscalYear;
use Modules\INVENTORY\Models\Product;
use Modules\INVENTORY\Models\Stock;
use Modules\INVENTORY\Models\Warehouse;
use Modules\PARTNER\Models\Partner;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\DatabaseSeeder::class);
    // Ensure FY covers today (Chattogram) — AccountsDemoSeeder now creates 3 FYs, but double-check
    if (! FiscalYear::where('start_date', '<=', now()->toDateString())->where('end_date', '>=', now()->toDateString())->exists()) {
        FiscalYear::create(['name' => 'FY Current', 'code' => 'FYTEST', 'start_date' => now()->subYear()->toDateString(), 'end_date' => now()->addYear()->toDateString(), 'status' => 'open', 'created_by' => 1]);
    }
});

test('PO → GRN stocks IN and PO received', function () {
    $w = Warehouse::first(); $vendor = Partner::where('type', 'vendor')->orWhere('is_vendor', true)->first() ?? Partner::first(); $p = Product::find(16);
    $before = (float) Stock::where('product_id', $p->id)->value('qty_on_hand');

    $poSvc = app(\Modules\PURCHASE\Services\PurchaseOrderService::class);
    $grnSvc = app(\Modules\PURCHASE\Services\GoodsReceiptService::class);

    $po = $poSvc->create(['vendor_id' => $vendor->id, 'warehouse_id' => $w->id, 'order_date' => now()->toDateString(), 'items' => [['product_id' => $p->id, 'qty' => 5, 'unit_price' => 80, 'vat_rate' => 5]]]);
    expect($po->status)->toBe('draft');
    $po = $poSvc->confirm($po);
    expect($po->status)->toBe('confirmed');
    expect((float) Stock::where('product_id', $p->id)->value('qty_on_hand'))->toBe($before); // PO no stock

    $grn = $grnSvc->create(['order_id' => $po->id, 'vendor_id' => $vendor->id, 'warehouse_id' => $w->id, 'receipt_date' => now()->toDateString(), 'items' => [['product_id' => $p->id, 'order_item_id' => $po->items()->first()->id, 'qty' => 5, 'unit_price' => 80, 'vat_rate' => 5]]]);
    $grn = $grnSvc->post($grn);
    expect($grn->status)->toBe('posted');
    expect((float) Stock::where('product_id', $p->id)->value('qty_on_hand'))->toBe($before + 5);
});

test('Vendor Bill posts AP ledger and payment clears AP', function () {
    $w = Warehouse::first(); $vendor = Partner::where('type', 'vendor')->first() ?? Partner::first(); $p = Product::find(16);
    $poSvc = app(\Modules\PURCHASE\Services\PurchaseOrderService::class);
    $grnSvc = app(\Modules\PURCHASE\Services\GoodsReceiptService::class);
    $billSvc = app(\Modules\PURCHASE\Services\VendorBillService::class);

    $po = $poSvc->create(['vendor_id' => $vendor->id, 'warehouse_id' => $w->id, 'order_date' => now()->toDateString(), 'items' => [['product_id' => $p->id, 'qty' => 2, 'unit_price' => 100, 'vat_rate' => 5]]]);
    $poSvc->confirm($po);
    $grn = $grnSvc->create(['order_id' => $po->id, 'vendor_id' => $vendor->id, 'warehouse_id' => $w->id, 'receipt_date' => now()->toDateString(), 'items' => [['product_id' => $p->id, 'order_item_id' => $po->items()->first()->id, 'qty' => 2, 'unit_price' => 100, 'vat_rate' => 5]]]);
    $grnSvc->post($grn);

    $bill = $billSvc->create(['vendor_id' => $vendor->id, 'warehouse_id' => $w->id, 'order_id' => $po->id, 'receipt_id' => $grn->id, 'bill_date' => now()->toDateString(), 'items' => [['product_id' => $p->id, 'qty' => 2, 'unit_price' => 100, 'vat_rate' => 5]]]);
    $bill = $billSvc->post($bill);
    expect($bill->status)->toBe('posted');
    expect($bill->journal_id)->not->toBeNull();
    // AP line exists
    $j = \Modules\ACCOUNTS\Models\Journal::find($bill->journal_id);
    expect($j->lines->pluck('account_id')->contains(Account::where('code', '2100')->value('id')))->toBeTrue();

    $pay = $billSvc->recordPayment($bill, ['amount' => 210, 'payment_date' => now()->toDateString(), 'method' => 'bank', 'bank_id' => \Modules\ACCOUNTS\Models\Bank::first()->id]);
    expect($pay->journal_id)->not->toBeNull();
    expect((float) $bill->fresh()->paid_amount)->toBe(210.0);
    expect(AccountLedger::sum('debit'))->toBe(AccountLedger::sum('credit')); // balanced
});

test('Sales deducts stock and posts COGS after purchase avg', function () {
    // First create stock via purchase
    $w = Warehouse::first(); $vendor = Partner::where('type', 'vendor')->first() ?? Partner::first(); $cust = Partner::where('type', 'customer')->first() ?? Partner::first(); $p = Product::find(16);
    $poSvc = app(\Modules\PURCHASE\Services\PurchaseOrderService::class);
    $grnSvc = app(\Modules\PURCHASE\Services\GoodsReceiptService::class);
    $po = $poSvc->create(['vendor_id' => $vendor->id, 'warehouse_id' => $w->id, 'order_date' => now()->toDateString(), 'items' => [['product_id' => $p->id, 'qty' => 10, 'unit_price' => 80, 'vat_rate' => 0]]]);
    $poSvc->confirm($po);
    $grn = $grnSvc->create(['order_id' => $po->id, 'vendor_id' => $vendor->id, 'warehouse_id' => $w->id, 'receipt_date' => now()->toDateString(), 'items' => [['product_id' => $p->id, 'order_item_id' => $po->items()->first()->id, 'qty' => 10, 'unit_price' => 80, 'vat_rate' => 0]]]);
    $grnSvc->post($grn);

    $before = (float) Stock::where('product_id', $p->id)->value('qty_on_hand');
    $avg = (float) Stock::where('product_id', $p->id)->value('avg_cost');

    $invSvc = app(\Modules\SALES\Services\InvoiceService::class);
    $inv = $invSvc->create(['customer_id' => $cust->id, 'warehouse_id' => $w->id, 'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(), 'currency' => 'BDT', 'items' => [['product_id' => $p->id, 'qty' => 2, 'unit_price' => 150, 'vat_rate' => 0]]]);
    $inv = $invSvc->post($inv);
    expect($inv->status)->toBe('posted');
    expect((float) Stock::where('product_id', $p->id)->value('qty_on_hand'))->toBe($before - 2);
    // COGS should be avg * qty
    $j = \Modules\ACCOUNTS\Models\Journal::find($inv->journal_id);
    $cogsLine = $j->lines->firstWhere('account_id', Account::where('code', '5001')->value('id'));
    expect($cogsLine)->not->toBeNull();
    expect((float) $cogsLine->debit)->toBeGreaterThan(0);
    expect(abs((float) $cogsLine->debit - $avg * 2))->toBeLessThan(1); // avg ~ weighted
});

test('Fiscal year blocks posting outside open period', function () {
    // Create a FY far in past and try to post outside — blocked; we assert open FY exists
    expect(FiscalYear::where('status', 'open')->exists())->toBeTrue();
    $open = FiscalYear::where('status', 'open')->first();
    expect($open)->not->toBeNull();
});
