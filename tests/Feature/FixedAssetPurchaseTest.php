<?php

/**
 * Buying a fixed asset: the bill capitalises it, the register owns it afterwards.
 *
 * A vendor bill line marked `fixed_asset` posts its net into the category's fixed-asset
 * account and creates one `in_use` register row per unit in the same transaction — there is
 * no second registration step to forget, and no draft to strand. From there the asset is
 * ordinary register stock: it depreciates, it blocks the bill's void once it has, and it
 * can only leave through disposal. Assets never touch stock documents: no GRN, no invoice,
 * no challan, no POS sale.
 *
 * Helpers are prefixed `fap`; Pest loads every Feature file into one process.
 */

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\ACCOUNTS\Models\Account;
use Modules\ACCOUNTS\Models\AssetCategory;
use Modules\ACCOUNTS\Models\DepreciationRun;
use Modules\ACCOUNTS\Models\FixedAsset;
use Modules\ACCOUNTS\Models\FiscalYear;
use Modules\ACCOUNTS\Models\Journal;
use Modules\ACCOUNTS\Services\DepreciationService;
use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\CORE\Models\User;
use Modules\INVENTORY\Models\Product;
use Modules\INVENTORY\Models\Warehouse;
use Modules\PARTNER\Models\Partner;
use Modules\PURCHASE\Services\GoodsReceiptService;
use Modules\PURCHASE\Services\PurchaseOrderService;
use Modules\PURCHASE\Services\VendorBillService;
use Modules\SALES\DTO\InvoiceLineData;
use Modules\SALES\DTO\PosSaleData;
use Modules\SALES\Services\DeliveryService;
use Modules\SALES\Services\InvoiceService;
use Modules\SALES\Services\PosService;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->seed(SuperAdminSeeder::class);

    // Wide open on purpose: the catch-up test reaches two months back, which crosses New
    // Year when the suite runs in January — and a closed fiscal month must never fail a test
    // that is not about fiscal months.
    FiscalYear::create([
        'name' => 'FY FAP', 'code' => 'FY-FAP',
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => now()->addYear()->toDateString(),
        'status' => 'open',
    ]);

    foreach ([
        ['1200', 'Inventory', 'asset', 'debit'],
        ['1330', 'VAT Input', 'asset', 'debit'],
        ['1510', 'IT Equipment', 'asset', 'debit'],
        ['1520', 'Accumulated Depreciation — IT', 'asset', 'credit'],
        ['2100', 'Accounts Payable', 'liability', 'credit'],
        ['2115', 'GRNI', 'liability', 'credit'],
        ['5100', 'Purchases', 'expense', 'debit'],
        ['5600', 'Depreciation Expense', 'expense', 'debit'],
    ] as [$code, $name, $type, $nature]) {
        Account::firstOrCreate(['code' => $code], [
            'name' => $name, 'account_type' => $type, 'nature' => $nature,
            'level' => 1, 'is_group' => false, 'is_posting' => true, 'is_active' => true,
            'currency' => 'BDT', 'opening_balance' => 0,
        ]);
    }

    AssetCategory::create([
        'name' => 'IT Equipment', 'code' => 'IT',
        'asset_account_id' => Account::where('code', '1510')->value('id'),
        'accum_dep_account_id' => Account::where('code', '1520')->value('id'),
        'dep_expense_account_id' => Account::where('code', '5600')->value('id'),
        'default_method' => 'straight_line', 'default_useful_life_years' => 4,
        'default_salvage_pct' => 5, 'default_db_factor' => 2, 'is_active' => true,
    ]);

    actingAs(User::first());
});

/** Vendor, warehouse, an asset product and a stock product. */
function fapSetup(): array
{
    static $n = 0;
    $n++;

    $vendor = Partner::create(['partner_code' => 'FAP-V'.$n, 'type' => 'vendor', 'name' => "FAP Supplier {$n}"]);
    $customer = Partner::create(['partner_code' => 'FAP-C'.$n, 'type' => 'customer', 'name' => "FAP Customer {$n}"]);
    $warehouse = Warehouse::create([
        'name' => "FAP Store {$n}", 'code' => 'FAP-W'.$n, 'type' => 'main',
        'is_active' => true, 'allow_negative_stock' => false,
    ]);
    $asset = Product::create([
        'name' => "FAP Laptop {$n}", 'sku' => 'FAP-L'.$n, 'type' => 'asset',
        'status' => 'active', 'is_active' => true,
        'cost_price' => 30000, 'selling_price' => 0, 'vat_rate' => 15,
    ]);
    $goods = Product::create([
        'name' => "FAP Widget {$n}", 'sku' => 'FAP-G'.$n, 'type' => 'stockable',
        'status' => 'active', 'is_active' => true, 'valuation_method' => 'weighted',
        'cost_price' => 100, 'selling_price' => 150, 'vat_rate' => 0,
    ]);

    return [$vendor, $customer, $warehouse, $asset, $goods];
}

function fapCategory(): AssetCategory
{
    return AssetCategory::where('code', 'IT')->firstOrFail();
}

/** A direct bill with one asset line, drafted but not posted. */
function fapAssetBill(Partner $vendor, Warehouse $warehouse, Product $asset, array $line = [], array $bill = []): \Modules\PURCHASE\Models\VendorBill
{
    return app(VendorBillService::class)->create(array_merge([
        'vendor_id' => $vendor->id, 'warehouse_id' => $warehouse->id,
        'bill_date' => now()->toDateString(),
        'items' => [array_merge([
            'product_id' => $asset->id, 'product_name' => $asset->name, 'sku' => $asset->sku,
            'qty' => 2, 'unit_price' => 30000, 'discount_pct' => 0, 'vat_rate' => 15,
            'line_type' => 'fixed_asset', 'asset_category_id' => fapCategory()->id,
        ], $line)],
    ], $bill));
}

function fapLeg(int $journalId, string $code): array
{
    $r = DB::table('acc_journal_lines as l')->join('acc_accounts as a', 'a.id', '=', 'l.account_id')
        ->where('l.journal_id', $journalId)->where('a.code', $code)
        ->selectRaw('COALESCE(SUM(l.debit),0) dr, COALESCE(SUM(l.credit),0) cr')->first();

    return [(float) $r->dr, (float) $r->cr];
}

function fapRefused(callable $fn, string $needle): string
{
    try {
        $fn();
    } catch (\DomainException|\Symfony\Component\HttpKernel\Exception\HttpException $e) {
        expect($e->getMessage())->toContain($needle);

        return $e->getMessage();
    }
    expect(false)->toBeTrue('was NOT refused — expected: '.$needle);

    return '';
}

/* ─────────────────────────── capitalise on post ─────────────────────────── */

test('posting a direct asset bill capitalises one in-use asset per unit, net of VAT', function () {
    [$vendor, , $warehouse, $asset] = fapSetup();

    $bill = app(VendorBillService::class)->post(fapAssetBill($vendor, $warehouse, $asset)->fresh());

    expect($bill->status)->toBe('posted')
        ->and(fapLeg($bill->journal_id, '1510'))->toBe([60000.0, 0.0])
        ->and(fapLeg($bill->journal_id, '2100'))->toBe([0.0, 69000.0]);

    $journal = Journal::find($bill->journal_id);
    expect(abs((float) $journal->total_debit - (float) $journal->total_credit))->toBeLessThan(0.005);

    $assets = FixedAsset::where('bill_id', $bill->id)->orderBy('id')->get();
    expect($assets)->toHaveCount(2)
        ->and($assets->pluck('status')->unique()->all())->toBe(['in_use'])
        ->and($assets->pluck('cost')->map(fn ($c) => (float) $c)->all())->toBe([30000.0, 30000.0])
        ->and($assets[0]->asset_code)->not->toBe($assets[1]->asset_code)
        ->and($assets[0]->name)->toContain('(1/2)')
        ->and((float) $assets[0]->salvage_value)->toBe(1500.0)
        ->and($assets[0]->method)->toBe('straight_line')
        ->and((int) $assets[0]->useful_life_years)->toBe(4);
});

test('unit costs always add back to the line exactly', function () {
    [$vendor, , $warehouse, $asset] = fapSetup();

    // A bare price × qty always divides evenly by qty, so the remainder is forced with a
    // fractional discount: 300.00 − 1.00 over 3 units leaves 2 paise for the last unit.
    $bill = fapAssetBill($vendor, $warehouse, $asset, ['qty' => 3, 'unit_price' => 100, 'discount_pct' => 0.333, 'vat_rate' => 0]);
    $bill = app(VendorBillService::class)->post($bill->fresh());

    $costs = FixedAsset::where('bill_id', $bill->id)->orderBy('id')->pluck('cost')->map(fn ($c) => (float) $c)->all();
    expect($costs)->toBe([99.66, 99.66, 99.68])->and(round(array_sum($costs), 2))->toBe(299.0);
});

test('voiding a bill cascades to its fresh assets and keeps them soft-deleted', function () {
    [$vendor, , $warehouse, $asset] = fapSetup();
    $bill = app(VendorBillService::class)->post(fapAssetBill($vendor, $warehouse, $asset)->fresh());

    app(VendorBillService::class)->void($bill->fresh());

    expect($bill->fresh()->status)->toBe('cancelled')
        ->and(FixedAsset::where('bill_id', $bill->id)->count())->toBe(0)
        ->and(FixedAsset::withTrashed()->where('bill_id', $bill->id)->count())->toBe(2);
});

test('voiding a bill whose asset already depreciated is refused by name', function () {
    [$vendor, , $warehouse, $asset] = fapSetup();
    $bill = app(VendorBillService::class)->post(fapAssetBill($vendor, $warehouse, $asset)->fresh());
    app(DepreciationService::class)->run(now()->format('Y-m'), auth()->id());

    $message = fapRefused(fn () => app(VendorBillService::class)->void($bill->fresh()), 'already has depreciation');

    expect($message)->toContain($bill->bill_no)
        ->and($bill->fresh()->status)->toBe('posted');
});

test('non-recoverable VAT becomes part of the asset cost, with no VAT leg', function () {
    [$vendor, , $warehouse, $asset] = fapSetup();

    $bill = fapAssetBill($vendor, $warehouse, $asset, ['qty' => 1, 'unit_price' => 10000], ['vat_recoverable' => false]);
    $bill = app(VendorBillService::class)->post($bill->fresh());

    expect(fapLeg($bill->journal_id, '1510'))->toBe([11500.0, 0.0])
        ->and(fapLeg($bill->journal_id, '1330'))->toBe([0.0, 0.0])
        ->and(fapLeg($bill->journal_id, '2100'))->toBe([0.0, 11500.0])
        ->and((float) FixedAsset::where('bill_id', $bill->id)->value('cost'))->toBe(11500.0);
});

/* ─────────────────────────── posting guards ─────────────────────────── */

test('an asset product billed on a goods line is refused, and vice versa', function () {
    [$vendor, , $warehouse, $asset, $goods] = fapSetup();

    $bill = fapAssetBill($vendor, $warehouse, $asset, ['line_type' => 'goods', 'asset_category_id' => null]);
    fapRefused(fn () => app(VendorBillService::class)->post($bill->fresh()), 'mark the line fixed-asset');

    $bill = fapAssetBill($vendor, $warehouse, $goods, ['asset_category_id' => fapCategory()->id]);
    fapRefused(fn () => app(VendorBillService::class)->post($bill->fresh()), 'not an asset product');
});

test('a fractional asset quantity is refused', function () {
    [$vendor, , $warehouse, $asset] = fapSetup();

    $bill = fapAssetBill($vendor, $warehouse, $asset, ['qty' => 2.5]);
    fapRefused(fn () => app(VendorBillService::class)->post($bill->fresh()), 'whole units');
});

test('an asset line without a category is refused', function () {
    [$vendor, , $warehouse, $asset] = fapSetup();

    $bill = fapAssetBill($vendor, $warehouse, $asset, ['asset_category_id' => null]);
    fapRefused(fn () => app(VendorBillService::class)->post($bill->fresh()), 'no active asset category');
});

test('assets never arrive through a GRN, and never bill against one', function () {
    [$vendor, , $warehouse, $asset, $goods] = fapSetup();

    $order = app(PurchaseOrderService::class)->create([
        'vendor_id' => $vendor->id, 'warehouse_id' => $warehouse->id,
        'order_date' => now()->toDateString(),
        'items' => [[
            'product_id' => $asset->id, 'product_name' => $asset->name, 'sku' => $asset->sku,
            'qty' => 1, 'unit_price' => 30000, 'discount_pct' => 0, 'vat_rate' => 15,
        ]],
    ]);
    $order = app(PurchaseOrderService::class)->confirm($order->fresh());
    $order->load('items');

    $receipt = app(GoodsReceiptService::class)->create([
        'order_id' => $order->id, 'vendor_id' => $vendor->id, 'warehouse_id' => $warehouse->id,
        'receipt_date' => now()->toDateString(),
        'items' => [[
            'order_item_id' => $order->items->first()->id,
            'product_id' => $asset->id, 'product_name' => $asset->name, 'sku' => $asset->sku,
            'qty' => 1, 'unit_price' => 30000, 'discount_pct' => 0, 'vat_rate' => 15,
        ]],
    ]);
    fapRefused(fn () => app(GoodsReceiptService::class)->post($receipt->fresh()), 'never arrive through a stock receipt');

    // And a GRN bill carrying an asset line is refused even when the GRN itself is clean.
    $order2 = app(PurchaseOrderService::class)->create([
        'vendor_id' => $vendor->id, 'warehouse_id' => $warehouse->id,
        'order_date' => now()->toDateString(),
        'items' => [[
            'product_id' => $goods->id, 'product_name' => $goods->name, 'sku' => $goods->sku,
            'qty' => 5, 'unit_price' => 100, 'discount_pct' => 0, 'vat_rate' => 0,
        ]],
    ]);
    $order2 = app(PurchaseOrderService::class)->confirm($order2->fresh());
    $order2->load('items');
    $receipt2 = app(GoodsReceiptService::class)->create([
        'order_id' => $order2->id, 'vendor_id' => $vendor->id, 'warehouse_id' => $warehouse->id,
        'receipt_date' => now()->toDateString(),
        'items' => [[
            'order_item_id' => $order2->items->first()->id,
            'product_id' => $goods->id, 'product_name' => $goods->name, 'sku' => $goods->sku,
            'qty' => 5, 'unit_price' => 100, 'discount_pct' => 0, 'vat_rate' => 0,
        ]],
    ]);
    $receipt2 = app(GoodsReceiptService::class)->post($receipt2->fresh());

    $bill = app(VendorBillService::class)->create([
        'order_id' => $order2->id, 'receipt_id' => $receipt2->id,
        'vendor_id' => $vendor->id, 'warehouse_id' => $warehouse->id,
        'bill_date' => now()->toDateString(),
        'items' => [[
            'receipt_item_id' => $receipt2->fresh()->items->first()->id,
            'product_id' => $asset->id, 'product_name' => $asset->name, 'sku' => $asset->sku,
            'qty' => 1, 'unit_price' => 30000, 'discount_pct' => 0, 'vat_rate' => 15,
            'line_type' => 'fixed_asset', 'asset_category_id' => fapCategory()->id,
        ]],
    ]);
    fapRefused(fn () => app(VendorBillService::class)->post($bill->fresh()), 'carries fixed-asset lines');
});

/* ─────────────────────────── PO two-way match ─────────────────────────── */

test('a PO bill matches goods and asset lines, and the asset room cannot be billed twice', function () {
    [$vendor, , $warehouse, $asset, $goods] = fapSetup();

    $order = app(PurchaseOrderService::class)->create([
        'vendor_id' => $vendor->id, 'warehouse_id' => $warehouse->id,
        'order_date' => now()->toDateString(),
        'items' => [
            ['product_id' => $goods->id, 'product_name' => $goods->name, 'sku' => $goods->sku, 'qty' => 5, 'unit_price' => 100, 'discount_pct' => 0, 'vat_rate' => 0],
            ['product_id' => $asset->id, 'product_name' => $asset->name, 'sku' => $asset->sku, 'qty' => 2, 'unit_price' => 30000, 'discount_pct' => 0, 'vat_rate' => 15],
        ],
    ]);
    $order = app(PurchaseOrderService::class)->confirm($order->fresh());

    $bill = app(VendorBillService::class)->create([
        'order_id' => $order->id, 'vendor_id' => $vendor->id, 'warehouse_id' => $warehouse->id,
        'bill_date' => now()->toDateString(),
        'items' => [
            ['product_id' => $goods->id, 'product_name' => $goods->name, 'sku' => $goods->sku, 'qty' => 5, 'unit_price' => 100, 'discount_pct' => 0, 'vat_rate' => 0, 'line_type' => 'goods'],
            ['product_id' => $asset->id, 'product_name' => $asset->name, 'sku' => $asset->sku, 'qty' => 2, 'unit_price' => 30000, 'discount_pct' => 0, 'vat_rate' => 15, 'line_type' => 'fixed_asset', 'asset_category_id' => fapCategory()->id],
        ],
    ]);
    $bill = app(VendorBillService::class)->post($bill->fresh());

    expect(fapLeg($bill->journal_id, '1510'))->toBe([60000.0, 0.0])
        ->and(FixedAsset::where('bill_id', $bill->id)->count())->toBe(2);

    $again = app(VendorBillService::class)->create([
        'order_id' => $order->id, 'vendor_id' => $vendor->id, 'warehouse_id' => $warehouse->id,
        'bill_date' => now()->toDateString(),
        'items' => [[
            'product_id' => $asset->id, 'product_name' => $asset->name, 'sku' => $asset->sku,
            'qty' => 1, 'unit_price' => 30000, 'discount_pct' => 0, 'vat_rate' => 15,
            'line_type' => 'fixed_asset', 'asset_category_id' => fapCategory()->id,
        ]],
    ]);
    fapRefused(fn () => app(VendorBillService::class)->post($again->fresh()), 'unbilled');
});

/* ─────────────────────────── assets never sell ─────────────────────────── */

test('assets cannot be invoiced, delivered or sold at the till', function () {
    [$vendor, $customer, $warehouse, $asset] = fapSetup();

    $invoice = app(InvoiceService::class)->create([
        'customer_id' => $customer->id, 'warehouse_id' => $warehouse->id,
        'invoice_date' => now()->toDateString(),
        'items' => [[
            'product_id' => $asset->id, 'product_name' => $asset->name, 'sku' => $asset->sku,
            'qty' => 1, 'unit_price' => 30000, 'discount_pct' => 0, 'vat_rate' => 0,
        ]],
    ]);
    fapRefused(fn () => app(InvoiceService::class)->post($invoice->fresh()), 'asset register');

    $delivery = app(DeliveryService::class)->create([
        'customer_id' => $customer->id, 'warehouse_id' => $warehouse->id,
        'delivery_date' => now()->toDateString(),
        'items' => [[
            'product_id' => $asset->id, 'product_name' => $asset->name, 'sku' => $asset->sku, 'qty' => 1,
        ]],
    ]);
    fapRefused(fn () => app(DeliveryService::class)->ship($delivery->fresh()), 'by disposal');

    $session = app(PosService::class)->openSession(['warehouse_id' => $warehouse->id, 'opening_cash' => 0]);
    fapRefused(
        fn () => app(PosService::class)->sell(new PosSaleData(
            warehouseId: $warehouse->id,
            lines: [new InvoiceLineData(productId: $asset->id, qty: 1, unitPrice: 30000)],
            paymentMethod: 'cash',
            sessionId: $session->id
        )),
        'cannot be sold through POS'
    );
});

/* ─────────────────────────── catch-up and schedule ─────────────────────────── */

test('catch-up runs every outstanding month with its own journal', function () {
    [$vendor, , $warehouse, $asset] = fapSetup();

    $twoMonthsAgo = now()->subMonths(2)->format('Y-m');
    FixedAsset::create([
        'asset_code' => 'FA-TEST-1', 'name' => 'FAP Server', 'category_id' => fapCategory()->id,
        'purchase_date' => $twoMonthsAgo.'-05', 'in_service_date' => $twoMonthsAgo.'-05',
        'cost' => 12000, 'salvage_value' => 0, 'method' => 'straight_line',
        'useful_life_years' => 4, 'db_factor' => 2,
        'accumulated_dep' => 0, 'net_book_value' => 12000, 'status' => 'in_use',
    ]);

    $runs = app(DepreciationService::class)->catchUp(now()->format('Y-m'), auth()->id());

    expect($runs)->toHaveCount(3)
        ->and(DepreciationRun::orderBy('period')->pluck('period')->all())
        ->toBe([$twoMonthsAgo, now()->subMonth()->format('Y-m'), now()->format('Y-m')]);

    $journalIds = DepreciationRun::pluck('journal_id')->all();
    expect(array_unique($journalIds))->toHaveCount(3);

    foreach (DepreciationRun::all() as $run) {
        $journal = Journal::find($run->journal_id);
        expect(abs((float) $journal->total_debit - (float) $journal->total_credit))->toBeLessThan(0.005);
    }

    expect((float) FixedAsset::where('asset_code', 'FA-TEST-1')->value('accumulated_dep'))->toBeGreaterThan(0);
});

test('the scheduled command is a no-op success on an already-run period', function () {
    [$vendor, , $warehouse, $asset] = fapSetup();
    $bill = app(VendorBillService::class)->post(fapAssetBill($vendor, $warehouse, $asset)->fresh());
    $period = now()->format('Y-m');
    app(DepreciationService::class)->run($period, auth()->id());

    $code = Artisan::call('erp:depreciation-run', ['--period' => $period]);

    expect($code)->toBe(0)->and(Artisan::output())->toContain('already run');
});
