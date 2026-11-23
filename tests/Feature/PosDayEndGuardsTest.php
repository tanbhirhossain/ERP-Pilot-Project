<?php

/**
 * The till takes money into a real place, and the day-end posts what the day did.
 *
 * Two families of defects lived here. At the counter: electronic tenders with no bank were
 * accepted and then posted as drawer cash at the close, so the Z-report reconciled to a count
 * that never happened; cost snapshots came from the product master's stale price instead of
 * the shelf's average, so every sale drifted the inventory account away from the stock ledger.
 * At the close: dues collected at a later till before the selling day posted were lost between
 * the two journals — credited in one, still owed in the other.
 *
 * The purchase and sales mirrors get their share too: a second bill against the same receipt
 * used to pass with the full received quantity again, and a second challan could ship the same
 * order line twice.
 *
 * Helpers are prefixed `pdg`; Pest loads every Feature file into one process.
 */

use Modules\ACCOUNTS\Models\Account;
use Modules\ACCOUNTS\Models\Bank;
use Modules\ACCOUNTS\Models\FiscalYear;
use Modules\ACCOUNTS\Models\Journal;
use Illuminate\Support\Facades\DB;
use Modules\ACCOUNTS\Models\Receivable;
use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\CORE\Models\User;
use Modules\INVENTORY\Models\Product;
use Modules\INVENTORY\Models\Stock;
use Modules\INVENTORY\Models\Warehouse;
use Modules\PARTNER\Models\Partner;
use Modules\PURCHASE\Models\PurchaseOrderItem;
use Modules\PURCHASE\Models\VendorBill;
use Modules\PURCHASE\Services\GoodsReceiptService;
use Modules\PURCHASE\Services\PurchaseOrderService;
use Modules\PURCHASE\Services\VendorBillService;
use Modules\SALES\DTO\InvoiceLineData;
use Modules\SALES\DTO\PosSaleData;
use Modules\SALES\Services\DeliveryService;
use Modules\SALES\Services\OrderService;
use Modules\SALES\Services\PosService;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->seed(SuperAdminSeeder::class);

    FiscalYear::create([
        'name' => 'FY PDG', 'code' => 'FY-PDG',
        'start_date' => now()->startOfYear()->toDateString(),
        'end_date' => now()->endOfYear()->toDateString(),
        'status' => 'open',
    ]);

    foreach ([
        ['1001', 'Cash in Hand', 'asset', 'debit'],
        ['100297', 'PDG Test Bank', 'asset', 'debit'],
        ['1100', 'Accounts Receivable', 'asset', 'debit'],
        ['1200', 'Inventory', 'asset', 'debit'],
        ['1330', 'VAT Input', 'asset', 'debit'],
        ['2100', 'Accounts Payable', 'liability', 'credit'],
        ['2110', 'VAT Payable', 'liability', 'credit'],
        ['2115', 'GRNI', 'liability', 'credit'],
        ['4001', 'Sales Revenue', 'income', 'credit'],
        ['5001', 'Cost of Goods Sold', 'expense', 'debit'],
        ['5100', 'Purchases', 'expense', 'debit'],
    ] as [$code, $name, $type, $nature]) {
        Account::firstOrCreate(['code' => $code], [
            'name' => $name, 'account_type' => $type, 'nature' => $nature,
            'level' => 1, 'is_group' => false, 'is_posting' => true, 'is_active' => true,
            'currency' => 'BDT', 'opening_balance' => 0,
        ]);
    }

    actingAs(User::first());
});

/** Warehouse, weighted product, vendor, customer and a bank with a real GL account. */
function pdgSetup(): array
{
    static $n = 0;
    $n++;

    $warehouse = Warehouse::create([
        'name' => "PDG Store {$n}", 'code' => 'PDG-W'.$n, 'type' => 'main',
        'is_active' => true, 'allow_negative_stock' => false,
    ]);
    $product = Product::create([
        'name' => "PDG Widget {$n}", 'sku' => 'PDG-W'.$n, 'type' => 'stockable',
        'status' => 'active', 'is_active' => true, 'valuation_method' => 'weighted',
        'cost_price' => 100, 'selling_price' => 150, 'vat_rate' => 0,
    ]);
    $vendor = Partner::create(['partner_code' => 'PDG-V'.$n, 'type' => 'vendor', 'name' => "PDG Supplier {$n}"]);
    $customer = Partner::create(['partner_code' => 'PDG-C'.$n, 'type' => 'customer', 'name' => "PDG Customer {$n}"]);
    $bank = Bank::create([
        'name' => "PDG Bank {$n}", 'code' => 'PDG-B'.$n, 'bank_name' => "PDG Bank {$n}",
        'account_no' => '100'.$n, 'account_id' => Account::where('code', '100297')->value('id'),
        'is_active' => true,
    ]);

    return [$warehouse, $product, $vendor, $customer, $bank];
}

/** Put $qty on the shelf at $price through a posted PO + GRN. */
function pdgReceive(Product $product, Warehouse $warehouse, Partner $vendor, float $qty, float $price): array
{
    $order = app(PurchaseOrderService::class)->create([
        'vendor_id' => $vendor->id, 'warehouse_id' => $warehouse->id,
        'order_date' => now()->toDateString(),
        'items' => [[
            'product_id' => $product->id, 'product_name' => $product->name, 'sku' => $product->sku,
            'qty' => $qty, 'unit_price' => $price, 'discount_pct' => 0, 'vat_rate' => 0,
        ]],
    ]);
    $order = app(PurchaseOrderService::class)->confirm($order->fresh());
    $order->load('items');
    $receipt = app(GoodsReceiptService::class)->create([
        'order_id' => $order->id, 'vendor_id' => $vendor->id, 'warehouse_id' => $warehouse->id,
        'receipt_date' => now()->toDateString(),
        'items' => [[
            'order_item_id' => $order->items->first()->id,
            'product_id' => $product->id, 'product_name' => $product->name, 'sku' => $product->sku,
            'qty' => $qty, 'unit_price' => $price, 'discount_pct' => 0, 'vat_rate' => 0,
        ]],
    ]);

    return [$order->fresh('items'), app(GoodsReceiptService::class)->post($receipt->fresh())];
}

function pdgLeg(int $journalId, string $code): array
{
    $r = DB::table('acc_journal_lines as l')->join('acc_accounts as a', 'a.id', '=', 'l.account_id')
        ->where('l.journal_id', $journalId)->where('a.code', $code)
        ->selectRaw('COALESCE(SUM(l.debit),0) AS dr, COALESCE(SUM(l.credit),0) AS cr')->first();

    return [(float) $r->dr, (float) $r->cr];
}

test('a POS sale costs at the shelf average, not the master price', function () {
    [$warehouse, $product, $vendor, $customer] = pdgSetup();
    pdgReceive($product, $warehouse, $vendor, 10, 40);

    $session = app(PosService::class)->openSession(['warehouse_id' => $warehouse->id, 'opening_cash' => 0]);
    $sale = app(PosService::class)->sell(new PosSaleData(
        warehouseId: $warehouse->id,
        lines: [new InvoiceLineData(productId: $product->id, qty: 2, unitPrice: 150)],
        paymentMethod: 'cash', sessionId: $session->id,
    ));

    // The master says 100, the shelf's average is 40: the sale must cost at 40.
    expect((float) $sale->cost_total)->toBe(80.0);

    $closed = app(PosService::class)->closeSession($session->fresh(), []);
    expect(pdgLeg($closed->journal_id, '5001'))->toBe([80.0, 0.0])
        ->and(pdgLeg($closed->journal_id, '1200'))->toBe([0.0, 80.0]);
});

test('electronic tenders need an active bank with a ledger account', function () {
    [$warehouse, $product, $vendor, $customer, $bank] = pdgSetup();
    pdgReceive($product, $warehouse, $vendor, 10, 40);
    $session = app(PosService::class)->openSession(['warehouse_id' => $warehouse->id, 'opening_cash' => 0]);

    $ring = fn (?int $bankId) => app(PosService::class)->sell(new PosSaleData(
        warehouseId: $warehouse->id,
        lines: [new InvoiceLineData(productId: $product->id, qty: 1, unitPrice: 150)],
        paymentMethod: 'card', bankId: $bankId, sessionId: $session->id,
    ));

    try {
        $ring(null);
        $this->fail('card sale with no bank was accepted');
    } catch (\DomainException $e) {
        expect($e->getMessage())->toContain('linked to a ledger account');
    }

    $bank->update(['is_active' => false]);

    try {
        $ring($bank->id);
        $this->fail('card sale into an inactive bank was accepted');
    } catch (\DomainException $e) {
        expect($e->getMessage())->toContain('linked to a ledger account');
    }

    // (`account_id` is NOT NULL on the banks table, so an unlinked bank cannot exist —
    // the service gate covers it anyway for a bank deleted mid-sale.)
    $bank->update(['is_active' => true]);
    $sale = $ring($bank->id);
    expect((float) $sale->bank_amount)->toBe(150.0);

    // The refused tickets moved nothing: only the one good sale left the shelf.
    expect((float) Stock::where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->value('qty_on_hand'))->toBe(9.0);
});

test('a bank deactivated mid-day refuses the close instead of posting to cash', function () {
    [$warehouse, $product, $vendor, $customer, $bank] = pdgSetup();
    pdgReceive($product, $warehouse, $vendor, 10, 40);
    $session = app(PosService::class)->openSession(['warehouse_id' => $warehouse->id, 'opening_cash' => 0]);
    app(PosService::class)->sell(new PosSaleData(
        warehouseId: $warehouse->id,
        lines: [new InvoiceLineData(productId: $product->id, qty: 1, unitPrice: 150)],
        paymentMethod: 'card', bankId: $bank->id, sessionId: $session->id,
    ));

    $bank->update(['is_active' => false]);

    try {
        app(PosService::class)->closeSession($session->fresh(), []);
        $this->fail('day-end posted card takings with the bank gone');
    } catch (\DomainException $e) {
        expect($e->getMessage())->toContain('no longer exists or is no longer active');
    }

    expect($session->fresh()->status)->toBe('open')->and($session->fresh()->journal_id)->toBeNull();

    $bank->update(['is_active' => true]);
    $closed = app(PosService::class)->closeSession($session->fresh(), []);
    expect($closed->status)->toBe('closed')
        ->and(pdgLeg($closed->journal_id, '100297'))->toBe([150.0, 0.0])
        ->and(pdgLeg($closed->journal_id, '1001'))->toBe([0.0, 0.0]);
});

test('a service sale moves no stock and carries no cost', function () {
    [$warehouse, $product, $vendor, $customer] = pdgSetup();
    $service = Product::create([
        'name' => 'PDG Install', 'sku' => 'PDG-SVC', 'type' => 'service',
        'status' => 'active', 'is_active' => true, 'valuation_method' => 'weighted',
        'selling_price' => 500, 'vat_rate' => 0,
    ]);

    $session = app(PosService::class)->openSession(['warehouse_id' => $warehouse->id, 'opening_cash' => 0]);
    $sale = app(PosService::class)->sell(new PosSaleData(
        warehouseId: $warehouse->id,
        lines: [new InvoiceLineData(productId: $service->id, qty: 1, unitPrice: 500)],
        paymentMethod: 'cash', sessionId: $session->id,
    ));

    expect((float) $sale->cost_total)->toBe(0.0)
        ->and(Stock::where('product_id', $service->id)->count())->toBe(0);

    $closed = app(PosService::class)->closeSession($session->fresh(), []);
    expect(pdgLeg($closed->journal_id, '4001'))->toBe([0.0, 500.0])
        ->and(pdgLeg($closed->journal_id, '5001'))->toBe([0.0, 0.0]);
});

test('a voided sale cannot be collected and a collected sale cannot be voided', function () {
    [$warehouse, $product, $vendor, $customer] = pdgSetup();
    pdgReceive($product, $warehouse, $vendor, 10, 40);
    $session = app(PosService::class)->openSession(['warehouse_id' => $warehouse->id, 'opening_cash' => 0]);
    $pos = app(PosService::class);

    $collectable = $pos->sell(new PosSaleData(
        warehouseId: $warehouse->id,
        lines: [new InvoiceLineData(productId: $product->id, qty: 2, unitPrice: 150)],
        paymentMethod: 'due', customerId: $customer->id, sessionId: $session->id,
    ));
    $pos->collectDue($collectable->fresh(), ['session_id' => $session->id, 'amount' => 100, 'method' => 'cash']);

    try {
        $pos->void($collectable->fresh());
        $this->fail('collected sale was voided');
    } catch (\DomainException $e) {
        expect($e->getMessage())->toContain('already has money collected');
    }

    $voidable = $pos->sell(new PosSaleData(
        warehouseId: $warehouse->id,
        lines: [new InvoiceLineData(productId: $product->id, qty: 1, unitPrice: 150)],
        paymentMethod: 'due', customerId: $customer->id, sessionId: $session->id,
    ));
    $pos->void($voidable->fresh());

    try {
        $pos->collectDue($voidable->fresh(), ['session_id' => $session->id, 'amount' => 50, 'method' => 'cash']);
        $this->fail('void sale was collected');
    } catch (\DomainException $e) {
        expect($e->getMessage())->toContain('is void');
    }
});

test('an electronic collection needs its bank, like the sale did', function () {
    [$warehouse, $product, $vendor, $customer, $bank] = pdgSetup();
    pdgReceive($product, $warehouse, $vendor, 10, 40);
    $session = app(PosService::class)->openSession(['warehouse_id' => $warehouse->id, 'opening_cash' => 0]);
    $sale = app(PosService::class)->sell(new PosSaleData(
        warehouseId: $warehouse->id,
        lines: [new InvoiceLineData(productId: $product->id, qty: 2, unitPrice: 150)],
        paymentMethod: 'due', customerId: $customer->id, sessionId: $session->id,
    ));

    try {
        app(PosService::class)->collectDue($sale->fresh(), ['session_id' => $session->id, 'amount' => 100, 'method' => 'card']);
        $this->fail('card collection with no bank was accepted');
    } catch (\DomainException $e) {
        expect($e->getMessage())->toContain('linked to a ledger account');
    }

    $collection = app(PosService::class)->collectDue($sale->fresh(), [
        'session_id' => $session->id, 'amount' => 100, 'method' => 'card', 'bank_id' => $bank->id,
    ]);
    expect((float) $sale->fresh()->collected_amount)->toBe(100.0)
        ->and((int) $collection->bank_id)->toBe($bank->id);
});

test('a due collected at a later till settles the selling day receivable', function () {
    [$warehouse, $product, $vendor, $customer] = pdgSetup();
    pdgReceive($product, $warehouse, $vendor, 10, 40);
    $pos = app(PosService::class);

    $dayA = $pos->openSession(['warehouse_id' => $warehouse->id, 'opening_cash' => 0]);
    $sale = $pos->sell(new PosSaleData(
        warehouseId: $warehouse->id,
        lines: [new InvoiceLineData(productId: $product->id, qty: 2, unitPrice: 150)],
        paymentMethod: 'due', customerId: $customer->id, sessionId: $dayA->id,
    ));

    // Collected at the next till while the selling day is still open: no receivable exists yet.
    // (One drawer per warehouse, so the later till is another warehouse's.)
    $warehouseB = Warehouse::create([
        'name' => 'PDG Store B', 'code' => 'PDG-WB', 'type' => 'main',
        'is_active' => true, 'allow_negative_stock' => false,
    ]);
    $dayB = $pos->openSession(['warehouse_id' => $warehouseB->id, 'opening_cash' => 0]);
    $collection = $pos->collectDue($sale->fresh(), ['session_id' => $dayB->id, 'amount' => 100, 'method' => 'cash']);
    expect($collection->receivable_id)->toBeNull();

    $closedB = $pos->closeSession($dayB->fresh(), []);
    expect(pdgLeg($closedB->journal_id, '1100'))->toBe([0.0, 100.0]);

    // The selling day closes after: its receivable is born part-paid, and the collection links up.
    $closedA = $pos->closeSession($dayA->fresh(), []);
    expect(pdgLeg($closedA->journal_id, '1100'))->toBe([300.0, 0.0]);

    $receivable = Receivable::where('journal_id', $closedA->journal_id)->where('partner_id', $customer->id)->first();
    expect($receivable)->not->toBeNull()
        ->and((float) $receivable->amount)->toBe(300.0)
        ->and((float) $receivable->received_amount)->toBe(100.0)
        ->and($receivable->status)->toBe('partial')
        ->and((int) $collection->fresh()->receivable_id)->toBe($receivable->id);
});

test('a billed receipt cannot be billed again, and the order unbilled room is shared', function () {
    [$warehouse, $product, $vendor, $customer] = pdgSetup();
    [$order, $receipt] = pdgReceive($product, $warehouse, $vendor, 10, 40);
    $billSvc = app(VendorBillService::class);

    $make = fn (float $qty, ?int $receiptId = null) => $billSvc->post($billSvc->create(array_filter([
        'order_id' => $order->id, 'receipt_id' => $receiptId,
        'vendor_id' => $vendor->id, 'warehouse_id' => $warehouse->id,
        'bill_date' => now()->toDateString(),
        'items' => [[
            'receipt_item_id' => $receiptId ? $receipt->fresh()->items->first()->id : null,
            'product_id' => $product->id, 'product_name' => $product->name, 'sku' => $product->sku,
            'qty' => $qty, 'unit_price' => 40, 'discount_pct' => 0, 'vat_rate' => 0,
        ]],
    ]))->fresh());

    // One delivery gets one vendor invoice.
    $make(10, $receipt->id);

    try {
        $make(1, $receipt->id);
        $this->fail('a billed receipt took a second bill');
    } catch (\DomainException $e) {
        expect($e->getMessage())->toContain('One delivery gets one vendor invoice');
    }

    // The order's unbilled room is shared across billing paths: a direct bill for 6 leaves 4.
    $billOn = function ($order, $receipt, float $qty, ?int $receiptId = null) use ($billSvc, $vendor, $warehouse, $product) {
        return $billSvc->post($billSvc->create(array_filter([
            'order_id' => $order->id, 'receipt_id' => $receiptId,
            'vendor_id' => $vendor->id, 'warehouse_id' => $warehouse->id,
            'bill_date' => now()->toDateString(),
            'items' => [[
                'receipt_item_id' => $receiptId ? $receipt->fresh()->items->first()->id : null,
                'product_id' => $product->id, 'product_name' => $product->name, 'sku' => $product->sku,
                'qty' => $qty, 'unit_price' => 40, 'discount_pct' => 0, 'vat_rate' => 0,
            ]],
        ]))->fresh());
    };

    [$order2, $receipt2] = pdgReceive($product, $warehouse, $vendor, 10, 40);
    $billOn($order2, $receipt2, 6);
    expect((float) PurchaseOrderItem::where('order_id', $order2->id)->value('qty_billed'))->toBe(6.0);
    $billOn($order2, $receipt2, 4, $receipt2->id);
    expect((float) PurchaseOrderItem::where('order_id', $order2->id)->value('qty_billed'))->toBe(10.0);

    // The receipt holds 10, but only 4 are unbilled: the refusal must say so, not quote received.
    [$order3, $receipt3] = pdgReceive($product, $warehouse, $vendor, 10, 40);
    $billOn($order3, $receipt3, 6);

    try {
        $billOn($order3, $receipt3, 10, $receipt3->id);
        $this->fail('ten units billed when only four were unbilled');
    } catch (\DomainException $e) {
        expect($e->getMessage())->toContain('already on another bill');
    }
});

test('an order line cannot ship twice', function () {
    [$warehouse, $product, $vendor, $customer] = pdgSetup();
    pdgReceive($product, $warehouse, $vendor, 10, 40);

    $order = app(OrderService::class)->create([
        'customer_id' => $customer->id, 'warehouse_id' => $warehouse->id,
        'order_date' => now()->toDateString(),
        'items' => [[
            'product_id' => $product->id, 'product_name' => $product->name, 'sku' => $product->sku,
            'qty' => 10, 'unit_price' => 150, 'discount_pct' => 0, 'vat_rate' => 0,
        ]],
    ]);
    $order = app(OrderService::class)->confirm($order->fresh());
    $order->load('items');

    $ship = fn (float $qty) => app(DeliveryService::class)->ship(app(DeliveryService::class)->create([
        'order_id' => $order->id, 'customer_id' => $customer->id, 'warehouse_id' => $warehouse->id,
        'delivery_date' => now()->toDateString(),
        'items' => [[
            'order_item_id' => $order->items->first()->id,
            'product_id' => $product->id, 'qty' => $qty,
        ]],
    ])->fresh());

    $ship(10);
    expect((float) Stock::where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->value('qty_on_hand'))->toBe(0.0);

    try {
        $ship(1);
        $this->fail('eleventh unit shipped against a ten-unit order');
    } catch (\DomainException $e) {
        expect($e->getMessage())->toContain('already left on another challan');
    }
});
