<?php

/**
 * Money settles the document it was paid against — and voiding un-does exactly that.
 *
 * The purchase side learned this the hard way: a payment posted against one bill settled the
 * oldest open payable instead, so the bill register and the AP subledger disagreed about who was
 * owed what. The sales side carried the same FIFO settlement, plus a recordPayment() with no
 * guards at all — no due check, no method check, and a bank payment with no bank silently posted
 * to cash. And voiding any journal reversed the ledger while the subledger stood untouched.
 *
 * Helpers are prefixed `spv`; Pest loads every Feature file into one process.
 */

use Modules\ACCOUNTS\Models\Account;
use Modules\ACCOUNTS\Models\Bank;
use Modules\ACCOUNTS\Models\FiscalYear;
use Modules\ACCOUNTS\Models\Payable;
use Modules\ACCOUNTS\Models\Receivable;
use Modules\ACCOUNTS\Services\JournalService;
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
use Modules\SALES\Services\InvoiceService;
use Modules\SALES\Services\PosService;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->seed(SuperAdminSeeder::class);

    FiscalYear::create([
        'name' => 'FY SPV', 'code' => 'FY-SPV',
        'start_date' => now()->startOfYear()->toDateString(),
        'end_date' => now()->endOfYear()->toDateString(),
        'status' => 'open',
    ]);

    foreach ([
        ['1001', 'Cash in Hand', 'asset', 'debit'],
        ['100299', 'SPV Test Bank', 'asset', 'debit'],
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
function spvSetup(): array
{
    static $n = 0;
    $n++;

    $warehouse = Warehouse::create([
        'name' => "SPV Store {$n}", 'code' => 'SPV-W'.$n, 'type' => 'main',
        'is_active' => true, 'allow_negative_stock' => false,
    ]);
    $product = Product::create([
        'name' => "SPV Widget {$n}", 'sku' => 'SPV-W'.$n, 'type' => 'stockable',
        'status' => 'active', 'is_active' => true, 'valuation_method' => 'weighted',
        'cost_price' => 50, 'selling_price' => 100, 'vat_rate' => 0,
    ]);
    $vendor = Partner::create(['partner_code' => 'SPV-V'.$n, 'type' => 'vendor', 'name' => "SPV Supplier {$n}"]);
    $customer = Partner::create(['partner_code' => 'SPV-C'.$n, 'type' => 'customer', 'name' => "SPV Customer {$n}"]);
    $bank = Bank::create([
        'name' => "SPV Bank {$n}", 'code' => 'SPV-B'.$n, 'bank_name' => "SPV Bank {$n}",
        'account_no' => '000'.$n, 'account_id' => Account::where('code', '100299')->value('id'),
        'is_active' => true,
    ]);

    return [$warehouse, $product, $vendor, $customer, $bank];
}

/** Put $qty on the shelf at $price through a posted PO + GRN. */
function spvReceive(Product $product, Warehouse $warehouse, Partner $vendor, float $qty, float $price): array
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

function spvBill($order, $receipt, Partner $vendor, Warehouse $warehouse): \Modules\PURCHASE\Models\VendorBill
{
    $line = $order->items->first();
    $bill = app(VendorBillService::class)->create([
        'order_id' => $order->id, 'receipt_id' => $receipt->id,
        'vendor_id' => $vendor->id, 'warehouse_id' => $warehouse->id,
        'bill_date' => now()->toDateString(),
        'items' => [[
            'receipt_item_id' => $receipt->fresh()->items->first()->id,
            'product_id' => $line->product_id, 'product_name' => $line->product_name, 'sku' => $line->sku,
            'qty' => (float) $line->qty, 'unit_price' => (float) $line->unit_price,
            'discount_pct' => 0, 'vat_rate' => 0,
        ]],
    ]);

    return app(VendorBillService::class)->post($bill->fresh());
}

function spvInvoice(Partner $customer, Warehouse $warehouse, Product $product, float $qty, float $price): \Modules\SALES\Models\Invoice
{
    $inv = app(InvoiceService::class)->create([
        'customer_id' => $customer->id, 'warehouse_id' => $warehouse->id,
        'invoice_date' => now()->toDateString(),
        'items' => [[
            'product_id' => $product->id, 'product_name' => $product->name, 'sku' => $product->sku,
            'qty' => $qty, 'unit_price' => $price, 'discount_pct' => 0, 'vat_rate' => 0,
        ]],
    ]);

    return app(InvoiceService::class)->post($inv->fresh());
}

function spvReceivable(int $journalId): ?Receivable
{
    return Receivable::where('journal_id', $journalId)->first();
}

function spvPayable(int $journalId): ?Payable
{
    return Payable::where('journal_id', $journalId)->first();
}

/* ─────────────────────────── targeted settlement ─────────────────────────── */

test('a collection settles only the invoice it was taken against', function () {
    [$warehouse, $product, $vendor, $customer] = spvSetup();
    spvReceive($product, $warehouse, $vendor, 10, 50);
    $first = spvInvoice($customer, $warehouse, $product, 2, 100);
    $second = spvInvoice($customer, $warehouse, $product, 2, 100);

    $payment = app(InvoiceService::class)->recordPayment($second, [
        'amount' => 200, 'payment_date' => now()->toDateString(), 'method' => 'cash',
    ]);

    expect($payment->payment_no)->toBe('PAY-00001')
        ->and(spvReceivable($first->journal_id)->received_amount)->toBe('0.00')
        ->and(spvReceivable($first->journal_id)->status)->toBe('open')
        ->and(spvReceivable($second->journal_id)->received_amount)->toBe('200.00')
        ->and(spvReceivable($second->journal_id)->status)->toBe('paid')
        ->and($first->fresh()->payment_status)->toBe('unpaid')
        ->and($second->fresh()->payment_status)->toBe('paid')
        ->and((float) Receivable::where('partner_id', $customer->id)->sum('balance'))->toBe(200.0);
});

test('a collection larger than the due is refused, and moves nothing', function () {
    [$warehouse, $product, $vendor, $customer] = spvSetup();
    spvReceive($product, $warehouse, $vendor, 10, 50);
    $invoice = spvInvoice($customer, $warehouse, $product, 2, 100);

    foreach ([200.01, 200.001, 0, -50, 'abc'] as $bad) {
        try {
            app(InvoiceService::class)->recordPayment($invoice->fresh(), [
                'amount' => $bad, 'payment_date' => now()->toDateString(), 'method' => 'cash',
            ]);
            $this->fail("amount {$bad} was accepted");
        } catch (\DomainException) {
        }
    }

    expect($invoice->fresh()->paid_amount)->toBe('0.00')
        ->and(spvReceivable($invoice->journal_id)->received_amount)->toBe('0.00')
        ->and(\Modules\SALES\Models\Payment::count())->toBe(0);
});

test('a bank collection needs an active bank with a ledger account', function () {
    [$warehouse, $product, $vendor, $customer, $bank] = spvSetup();
    spvReceive($product, $warehouse, $vendor, 10, 50);
    $invoice = spvInvoice($customer, $warehouse, $product, 2, 100);

    // No bank at all: the money must not silently land in cash.
    try {
        app(InvoiceService::class)->recordPayment($invoice->fresh(), [
            'amount' => 200, 'payment_date' => now()->toDateString(), 'method' => 'bank',
        ]);
        $this->fail('bank payment without a bank was accepted');
    } catch (\DomainException $e) {
        expect($e->getMessage())->toContain('active bank');
    }

    $bank->update(['is_active' => false]);
    try {
        app(InvoiceService::class)->recordPayment($invoice->fresh(), [
            'amount' => 200, 'payment_date' => now()->toDateString(), 'method' => 'bank', 'bank_id' => $bank->id,
        ]);
        $this->fail('inactive bank was accepted');
    } catch (\DomainException) {
    }
    $bank->update(['is_active' => true]);

    $payment = app(InvoiceService::class)->recordPayment($invoice->fresh(), [
        'amount' => 200, 'payment_date' => now()->toDateString(), 'method' => 'bank', 'bank_id' => $bank->id,
    ]);
    $debitAccount = \Illuminate\Support\Facades\DB::table('acc_journal_lines')
        ->where('journal_id', $payment->journal_id)->where('debit', '>', 0)->value('account_id');

    expect($debitAccount)->toBe($bank->account_id)
        ->and($invoice->fresh()->payment_status)->toBe('paid');
});

/* ─────────────────────────── payment voids ─────────────────────────── */

test('voiding a collection un-settles the invoice everywhere', function () {
    [$warehouse, $product, $vendor, $customer] = spvSetup();
    spvReceive($product, $warehouse, $vendor, 10, 50);
    $invoice = spvInvoice($customer, $warehouse, $product, 2, 100);
    $payment = app(InvoiceService::class)->recordPayment($invoice, [
        'amount' => 200, 'payment_date' => now()->toDateString(), 'method' => 'cash',
    ]);

    $voided = app(InvoiceService::class)->voidPayment($payment, 'wrong invoice');

    expect($voided->status)->toBe('void')
        ->and($voided->voided_by)->not->toBeNull()
        ->and(\Modules\ACCOUNTS\Models\Journal::find($payment->journal_id)->status)->toBe('void')
        ->and(spvReceivable($invoice->journal_id)->received_amount)->toBe('0.00')
        ->and(spvReceivable($invoice->journal_id)->status)->toBe('open')
        ->and($invoice->fresh()->paid_amount)->toBe('0.00')
        ->and($invoice->fresh()->payment_status)->toBe('unpaid')
        ->and($invoice->fresh()->status)->toBe('posted');
});

test('voiding a vendor payment un-settles the bill everywhere', function () {
    [$warehouse, $product, $vendor] = spvSetup();
    [$order, $receipt] = spvReceive($product, $warehouse, $vendor, 10, 50);
    $bill = spvBill($order, $receipt, $vendor, $warehouse);
    $payment = app(VendorBillService::class)->recordPayment($bill, [
        'amount' => 500, 'payment_date' => now()->toDateString(), 'method' => 'cash',
    ]);

    expect($payment->payment_no)->toBe('PP-00001');

    $voided = app(VendorBillService::class)->voidPayment($payment, 'duplicate entry');

    expect($voided->status)->toBe('void')
        ->and(\Modules\ACCOUNTS\Models\Journal::find($payment->journal_id)->status)->toBe('void')
        ->and(spvPayable($bill->journal_id)->paid_amount)->toBe('0.00')
        ->and(spvPayable($bill->journal_id)->status)->toBe('open')
        ->and($bill->fresh()->paid_amount)->toBe('0.00')
        ->and($bill->fresh()->payment_status)->toBe('unpaid');
});

/* ─────────────────────────── void guards ─────────────────────────── */

test('voiding a bill voids its payable, and a paid bill cannot be voided', function () {
    [$warehouse, $product, $vendor] = spvSetup();
    [$order, $receipt] = spvReceive($product, $warehouse, $vendor, 10, 50);
    $bill = spvBill($order, $receipt, $vendor, $warehouse);

    app(VendorBillService::class)->void($bill->fresh());

    expect($bill->fresh()->status)->toBe('cancelled')
        ->and(spvPayable($bill->journal_id)->status)->toBe('void');

    [$order2, $receipt2] = spvReceive($product, $warehouse, $vendor, 4, 50);
    $paid = spvBill($order2, $receipt2, $vendor, $warehouse);
    app(VendorBillService::class)->recordPayment($paid, [
        'amount' => 200, 'payment_date' => now()->toDateString(), 'method' => 'cash',
    ]);
    try {
        app(VendorBillService::class)->void($paid->fresh());
        $this->fail('paid bill was voided');
    } catch (\DomainException) {
    }
    expect($paid->fresh()->status)->toBe('posted');
});

test('a source journal cannot be voided from the journal screen', function () {
    [$warehouse, $product, $vendor] = spvSetup();
    [$order, $receipt] = spvReceive($product, $warehouse, $vendor, 10, 50);
    $bill = spvBill($order, $receipt, $vendor, $warehouse);
    $journal = \Modules\ACCOUNTS\Models\Journal::find($bill->journal_id);

    try {
        app(JournalService::class)->void($journal, 'manual attempt');
        $this->fail('bill journal was voided without its source');
    } catch (\DomainException $e) {
        expect($e->getMessage())->toContain('source document');
    }
    expect($journal->fresh()->status)->toBe('posted')
        ->and(spvPayable($bill->journal_id)->status)->toBe('open');

    // A manual journal still voids the way it always did.
    $manual = app(JournalService::class)->create([
        'journal_date' => now()->toDateString(), 'type' => 'JV', 'narration' => 'SPV manual',
        'lines' => [
            ['account_id' => Account::where('code', '5001')->value('id'), 'debit' => 100, 'credit' => 0],
            ['account_id' => Account::where('code', '1001')->value('id'), 'debit' => 0, 'credit' => 100],
        ],
    ]);
    $manual = app(JournalService::class)->post($manual->fresh());
    app(JournalService::class)->void($manual->fresh(), 'correction');
    expect($manual->fresh()->status)->toBe('void');

    // …but its own reversal cannot be voided back.
    $reversal = \Modules\ACCOUNTS\Models\Journal::where('source_type', 'journal_void')->where('source_id', $manual->id)->first();
    try {
        app(JournalService::class)->void($reversal, 'un-reverse');
        $this->fail('reversal journal was voided');
    } catch (\DomainException $e) {
        expect($e->getMessage())->toContain('reversal');
    }
});

test('a day-end journal cannot be voided, and a manual settlement void is refused', function () {
    [$warehouse, $product, $vendor] = spvSetup();
    spvReceive($product, $warehouse, $vendor, 10, 50);
    $session = app(PosService::class)->openSession(['warehouse_id' => $warehouse->id, 'opening_cash' => 0]);
    app(PosService::class)->sell(new PosSaleData(
        warehouseId: $warehouse->id,
        lines: [new InvoiceLineData(productId: $product->id, qty: 1, unitPrice: 100)],
        paymentMethod: 'cash', sessionId: $session->id,
    ));
    $closed = app(PosService::class)->closeSession($session->fresh(), []);
    $dayJournal = \Modules\ACCOUNTS\Models\Journal::find($closed->journal_id);

    try {
        app(JournalService::class)->void($dayJournal, 'manual attempt', true);
        $this->fail('day-end journal was voided');
    } catch (\DomainException $e) {
        expect($e->getMessage())->toContain('day-end');
    }

    // A manual journal that paid down payables through FIFO cannot be un-applied exactly.
    $settler = app(JournalService::class)->create([
        'journal_date' => now()->toDateString(), 'type' => 'PV', 'narration' => 'SPV manual paydown',
        'partner_id' => $vendor->id, 'partner_type' => 'vendor',
        'lines' => [
            ['account_id' => Account::where('code', '2100')->value('id'), 'debit' => 10, 'credit' => 0],
            ['account_id' => Account::where('code', '1001')->value('id'), 'debit' => 0, 'credit' => 10],
        ],
    ]);
    $settler = app(JournalService::class)->post($settler->fresh());
    try {
        app(JournalService::class)->void($settler->fresh(), 'take it back');
        $this->fail('FIFO settlement was un-applied');
    } catch (\DomainException $e) {
        expect($e->getMessage())->toContain('cannot be un-applied exactly');
    }
    expect($settler->fresh()->status)->toBe('posted');
});

test('a second journal for the same source is refused, but manual journals stay repeatable', function () {
    [$warehouse, $product, $vendor] = spvSetup();
    [$order, $receipt] = spvReceive($product, $warehouse, $vendor, 10, 50);
    $bill = spvBill($order, $receipt, $vendor, $warehouse);

    $lines = [
        ['account_id' => Account::where('code', '1001')->value('id'), 'debit' => 5, 'credit' => 0],
        ['account_id' => Account::where('code', '4001')->value('id'), 'debit' => 0, 'credit' => 5],
    ];

    // The loser's insert hits the one-journal-per-source wall: double-clicks and racing closes
    // get words, not a second journal.
    try {
        app(JournalService::class)->create([
            'journal_date' => now()->toDateString(), 'type' => 'JV', 'narration' => 'SPV double journal',
            'source_type' => 'purchase_bill', 'source_id' => $bill->id, 'lines' => $lines,
        ]);
        $this->fail('a second journal was created for one bill');
    } catch (\DomainException $e) {
        expect($e->getMessage())->toContain('already has its journal');
    }

    // Manual journals carry no source, and NULLs never conflict.
    $first = app(JournalService::class)->create([
        'journal_date' => now()->toDateString(), 'type' => 'JV', 'narration' => 'SPV manual one', 'lines' => $lines,
    ]);
    $second = app(JournalService::class)->create([
        'journal_date' => now()->toDateString(), 'type' => 'JV', 'narration' => 'SPV manual two', 'lines' => $lines,
    ]);
    expect($second->id)->not->toBe($first->id);
});
