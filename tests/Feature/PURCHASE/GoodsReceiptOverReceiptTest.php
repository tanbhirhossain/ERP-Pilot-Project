<?php

/**
 * What a goods receipt is allowed to do to the order behind it.
 *
 * Both cases below were found on the live books, not imagined here:
 *
 *   - PO-00001 owed nothing — GRN-00001 had already put its whole 50 on the shelf — and a second
 *     receipt of the same 50 was accepted without a word. Stock doubled, GRNI was credited 15,500 for
 *     goods nobody delivered, and the order flipped to "received" all over again.
 *   - Cancelling that duplicate knocked the order's `qty_received` from 50 to zero, because the
 *     receipt line carried no `order_item_id` and `void()` decremented every line regardless while
 *     `post()` had only ever incremented the linked ones. The goods stayed on the shelf, the order
 *     said none had arrived, and the two disagreed silently.
 *
 * Helpers are prefixed `p3grn`; Pest loads every Feature file into one process.
 */

use Modules\ACCOUNTS\Models\Account;
use Modules\ACCOUNTS\Models\FiscalYear;
use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\CORE\Models\User;
use Modules\INVENTORY\Models\Product;
use Modules\INVENTORY\Models\Stock;
use Modules\INVENTORY\Models\Warehouse;
use Modules\PARTNER\Models\Partner;
use Modules\PURCHASE\Models\GoodsReceipt;
use Modules\PURCHASE\Models\PurchaseOrder;
use Modules\PURCHASE\Models\VendorBill;
use Modules\PURCHASE\Services\GoodsReceiptService;
use Modules\PURCHASE\Services\PurchaseOrderService;
use Modules\PURCHASE\Services\VendorBillService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->seed(SuperAdminSeeder::class);

    foreach (config('purchase.permissions') as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // The Pest database is built from migrations alone, so the chart is empty and the GRNI accrual —
    // which is deliberately not best-effort — refuses to post without these two leaves. Same shape as
    // LedgerStatementTest's accounts: a real posting leaf with an explicit nature.
    FiscalYear::create([
        'name' => 'FY 2026-27', 'code' => 'FY2627-GRN',
        'start_date' => now()->startOfYear()->toDateString(),
        'end_date' => now()->endOfYear()->toDateString(),
        'status' => 'open',
    ]);

    foreach ([
        ['1200', 'Inventory', 'asset', 'debit'],
        ['2100', 'Accounts Payable', 'liability', 'credit'],
        ['2115', 'Goods Received Not Invoiced', 'liability', 'credit'],
    ] as [$code, $name, $type, $nature]) {
        Account::firstOrCreate(['code' => $code], [
            'name' => $name, 'account_type' => $type, 'nature' => $nature,
            'level' => 1, 'is_group' => false, 'is_posting' => true, 'is_active' => true,
            'currency' => 'BDT', 'opening_balance' => 0,
        ]);
    }
});

/** A warehouse, a product, a vendor and a confirmed order for $qty at $price. */
function p3grnOrder(float $qty = 10.0, float $price = 100.0): array
{
    static $n = 0;
    $n++;

    actingAs(User::first());

    $warehouse = Warehouse::create([
        'name' => "P3grn Store {$n}", 'code' => 'P3GRN-W'.$n, 'type' => 'main',
        'is_active' => true, 'allow_negative_stock' => false,
    ]);

    $product = Product::create([
        'name' => "P3grn Widget {$n}", 'sku' => 'P3GRN-W'.$n, 'type' => 'stockable',
        'status' => 'active', 'is_active' => true, 'valuation_method' => 'weighted',
        'cost_price' => $price, 'selling_price' => $price * 2, 'vat_rate' => 0,
    ]);

    $vendor = Partner::create(['partner_code' => 'P3GRN-V'.$n, 'type' => 'vendor', 'name' => "P3grn Supplier {$n}"]);

    $order = app(PurchaseOrderService::class)->create([
        'vendor_id' => $vendor->id,
        'warehouse_id' => $warehouse->id,
        'order_date' => now()->toDateString(),
        'items' => [[
            'product_id' => $product->id, 'product_name' => $product->name, 'sku' => $product->sku,
            'qty' => $qty, 'unit_price' => $price,
        ]],
    ]);

    $order = app(PurchaseOrderService::class)->confirm($order->fresh());

    return [$order->fresh('items'), $warehouse, $product, $vendor];
}

/** A draft receipt against one order line, for the outstanding quantity unless told otherwise. */
function p3grnDraft(PurchaseOrder $order, ?float $qty = null, bool $linkToOrderLine = true): GoodsReceipt
{
    actingAs(User::first());

    $line = $order->items->first();

    return app(GoodsReceiptService::class)->create([
        'order_id' => $order->id,
        'vendor_id' => $order->vendor_id,
        'warehouse_id' => $order->warehouse_id,
        'receipt_date' => now()->toDateString(),
        'items' => [[
            'order_item_id' => $linkToOrderLine ? $line->id : null,
            'product_id' => $line->product_id,
            'product_name' => $line->product_name,
            'sku' => $line->sku,
            'qty' => $qty ?? (float) $line->qty,
            'unit_price' => (float) $line->unit_price,
            'discount_pct' => 0,
            'vat_rate' => 0,
        ]],
    ]);
}

function p3grnPost(GoodsReceipt $receipt): GoodsReceipt
{
    actingAs(User::first());

    return app(GoodsReceiptService::class)->post($receipt->fresh());
}

function p3grnOnHand(int $productId, int $warehouseId): float
{
    return (float) Stock::where('product_id', $productId)->where('warehouse_id', $warehouseId)->value('qty_on_hand');
}

/* ─────────────────────────── receiving more than was ordered ─────────────────────────── */

test('a second receipt of a fully received line is refused, and moves nothing', function () {
    [$order, $warehouse, $product] = p3grnOrder(qty: 10.0, price: 100.0);

    $first = p3grnPost(p3grnDraft($order));
    expect($first->status)->toBe('posted')
        ->and(p3grnOnHand($product->id, $warehouse->id))->toBe(10.0);

    $before = p3grnOnHand($product->id, $warehouse->id);

    $duplicate = p3grnDraft($order->fresh('items'));

    expect(fn () => p3grnPost($duplicate))
        ->toThrow(\DomainException::class, 'the order still owes');

    expect(p3grnOnHand($product->id, $warehouse->id))->toBe($before, 'the refused receipt still moved stock')
        ->and($duplicate->fresh()->status)->toBe('draft', 'a refused receipt was marked posted')
        ->and($duplicate->fresh()->grni_journal_id)->toBeNull('a refused receipt still accrued GRNI');
});

test('receiving part of the outstanding quantity is still allowed', function () {
    // The guard must refuse *more than owed*, not "more than nothing" — a half delivery is normal.
    [$order, $warehouse, $product] = p3grnOrder(qty: 10.0, price: 100.0);

    p3grnPost(p3grnDraft($order, qty: 4.0));
    p3grnPost(p3grnDraft($order->fresh('items'), qty: 6.0));

    expect(p3grnOnHand($product->id, $warehouse->id))->toBe(10.0)
        ->and((float) $order->fresh('items')->items->first()->qty_received)->toBe(10.0);
});

/* ─────────────────────────── voiding a receipt ─────────────────────────── */

test('voiding a receipt only subtracts the lines it actually added', function () {
    // The live shape: one posted receipt that incremented qty_received, then another whose line is not
    // linked to the order. Voiding the unlinked one used to decrement the linked line's received
    // quantity, so the order claimed zero received while its goods sat on the shelf.
    [$order, $warehouse, $product] = p3grnOrder(qty: 10.0, price: 100.0);

    p3grnPost(p3grnDraft($order));

    $line = $order->fresh('items')->items->first();
    expect((float) $line->qty_received)->toBe(10.0);

    // An ad-hoc receipt for the same product, with no order line behind it.
    $adhoc = p3grnDraft($order->fresh('items'), qty: 2.0, linkToOrderLine: false);
    p3grnPost($adhoc);

    expect(p3grnOnHand($product->id, $warehouse->id))->toBe(12.0);

    actingAs(User::first());
    app(GoodsReceiptService::class)->void($adhoc->fresh());

    expect((float) $order->fresh('items')->items->first()->qty_received)->toBe(
        10.0,
        'voiding an unlinked receipt subtracted from an order line it never added to',
    )->and(p3grnOnHand($product->id, $warehouse->id))->toBe(10.0);
});

/* ─────────────────────────── one delivery, one invoice ─────────────────────────── */

/** A posted bill against the receipt's single line. */
function p3grnBill(GoodsReceipt $receipt): VendorBill
{
    actingAs(User::first());

    $line = $receipt->fresh('items')->items->first();

    return app(VendorBillService::class)->create([
        'order_id' => $receipt->order_id,
        'receipt_id' => $receipt->id,
        'vendor_id' => $receipt->vendor_id,
        'warehouse_id' => $receipt->warehouse_id,
        'bill_date' => now()->toDateString(),
        'items' => [[
            'receipt_item_id' => $line->id,
            'product_id' => $line->product_id,
            'product_name' => $line->product_name,
            'qty' => (float) $line->qty,
            'unit_price' => (float) $line->unit_price,
            'discount_pct' => 0, 'vat_rate' => 0,
        ]],
    ]);
}

test('a second bill against the same receipt is refused while the first stands', function () {
    // Found on the live books: PB-00001 and PB-00002 both named GRN-00001, so the same accrual was
    // reversed twice and GRNI ran to a credit-side overdraft of 31,000 that nobody could explain.
    [$order] = p3grnOrder(qty: 10.0, price: 100.0);

    $receipt = p3grnPost(p3grnDraft($order));

    $first = p3grnBill($receipt);
    app(VendorBillService::class)->post($first->fresh());
    expect($first->fresh()->status)->toBe('posted');

    $second = p3grnBill($receipt);

    expect(fn () => app(VendorBillService::class)->post($second->fresh()))
        ->toThrow(\DomainException::class, 'is already billed on');

    expect($second->fresh()->status)->toBe('draft', 'a refused bill was marked posted');
});

test('voiding the first bill re-opens the receipt for a corrected one', function () {
    // The guard must not lock a genuine mistake in place: a wrong bill still has to be replaceable.
    [$order] = p3grnOrder(qty: 10.0, price: 100.0);

    $receipt = p3grnPost(p3grnDraft($order));

    $wrong = p3grnBill($receipt);
    app(VendorBillService::class)->post($wrong->fresh());

    actingAs(User::first());
    app(VendorBillService::class)->void($wrong->fresh());

    $right = p3grnBill($receipt);
    app(VendorBillService::class)->post($right->fresh());

    expect($right->fresh()->status)->toBe('posted');
});


test('a stale second confirmation cannot post the same receipt twice', function () {
    [$order, $warehouse, $product] = p3grnOrder(qty: 10.0, price: 100.0);
    $draft = p3grnDraft($order, qty: 2.0, linkToOrderLine: false);
    $stale = GoodsReceipt::findOrFail($draft->id);
    app(GoodsReceiptService::class)->post($draft);
    expect(p3grnOnHand($product->id, $warehouse->id))->toBe(2.0);
    expect(fn () => app(GoodsReceiptService::class)->post($stale))
        ->toThrow(\DomainException::class, 'Only draft can be posted.');
    expect(p3grnOnHand($product->id, $warehouse->id))->toBe(2.0);
});


test('a receipt stays draft until the authorised confirm receive action posts it', function () {
    [$order, $warehouse, $product] = p3grnOrder(qty: 10.0, price: 100.0);
    $draft = p3grnDraft($order, qty: 4.0);
    expect($draft->fresh()->status)->toBe('draft')->and(p3grnOnHand($product->id, $warehouse->id))->toBe(0.0);
    $this->actingAs(User::first())->get(route('purchase.receipts.show', $draft->id))
        ->assertOk()->assertInertia(fn ($page) => $page->where('receipt.status', 'draft')->where('canPost', true));
    $this->actingAs(User::first())->post(route('purchase.receipts.post', $draft->id))->assertRedirect();
    expect($draft->fresh()->status)->toBe('posted')->and(p3grnOnHand($product->id, $warehouse->id))->toBe(4.0);
});
