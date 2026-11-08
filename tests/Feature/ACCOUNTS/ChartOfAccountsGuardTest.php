<?php

/**
 * The chart-of-accounts guard: a document must not be marked posted when the ledger
 * entry behind it was skipped.
 *
 * Every posting path in the money modules resolved its accounts the same way:
 *
 *     $apId = $posting->accountIdByCode($apCode);
 *     if ($apId) { ...build lines...; $journalId = $posting->postJournal($dto)->id; }
 *     $bill->update(['status' => 'posted', 'journal_id' => $journalId]);
 *
 * `accountIdByCode()` is declared `: ?int`, so a missing account is a legitimate return
 * value — and 24 call sites treated null as "post nothing, carry on". The status update sits
 * *outside* the guard, so the document was marked posted with `journal_id = null` and no
 * ledger entries at all. Payables never moved, receivables never moved, a bill could be
 * recorded as PAID with no cash entry anywhere. Nothing threw, nothing logged, and the trial
 * balance simply disagreed with the subledgers.
 *
 * This is why the defect survived: only `PurchaseAndSalesE2ETest` seeds a chart of accounts,
 * so every other test that posts a document has been exercising the null branch. The suite
 * was green *because* of the silent skip, not in spite of it.
 *
 * There is a second, subtler shape: a leg dropped from a journal that still balances.
 * `InvoiceService` gates COGS on `if ($totalCost > 0 && $cogsId && $invId)`. With no 5001 in
 * the chart the AR/Sales/VAT legs still balance, so the journal posts cleanly and the cost of
 * the sale is never recognised — profit is overstated and no error is possible. A balance
 * check cannot catch that; only requiring the account can.
 *
 * The fix routes every resolution through `Modules\ACCOUNTS\Support\ChartOfAccounts::requireId()`,
 * which throws a `DomainException` naming the missing code. All seven services post inside
 * `DB::transaction()`, so the throw rolls the document back to draft and the controllers —
 * which already catch `DomainException` and flash it — tell the user which account to create.
 *
 * Helper names are prefixed because Pest loads every Feature file into one process, and the
 * file is self-contained because Pest only loads the helpers of the files it is asked to run.
 */

use Modules\ACCOUNTS\Models\Account;
use Modules\ACCOUNTS\Models\FiscalYear;
use Modules\ACCOUNTS\Models\Journal;
use Modules\ACCOUNTS\Models\JournalLine;
use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\CORE\Models\User;
use Modules\INVENTORY\Interfaces\InventoryStockProviderInterface;
use Modules\INVENTORY\Interfaces\InventoryValuationProviderInterface;
use Modules\INVENTORY\Models\Product;
use Modules\INVENTORY\Models\Warehouse;
use Modules\PARTNER\Models\Partner;
use Modules\PURCHASE\Models\GoodsReceipt;
use Modules\PURCHASE\Models\PurchasePayment;
use Modules\PURCHASE\Models\VendorBill;
use Modules\PURCHASE\Services\GoodsReceiptService;
use Modules\PURCHASE\Services\PurchaseReturnService;
use Modules\PURCHASE\Services\VendorBillService;
use Modules\SALES\Models\Invoice;
use Modules\SALES\Services\InvoiceService;

function coaGuardAdmin(): User
{
    test()->seed(SuperAdminSeeder::class);

    $admin = User::where('email', config('core.super_admin.email', 'admin@example.com'))->first();
    expect($admin)->not->toBeNull('SuperAdminSeeder produced no admin user');

    return $admin;
}

/** The fixture date is inside this year, and so is `now()` (KitService journals today). */
function coaGuardFiscalYear(): FiscalYear
{
    return FiscalYear::create([
        'name' => 'FY 2026-27',
        'code' => 'FY2627-COA',
        'start_date' => '2026-07-01',
        'end_date' => '2027-06-30',
        'status' => 'open',
    ]);
}

/** Every code the seven services resolve, with the type/nature the ledger expects. */
function coaGuardChartMap(): array
{
    return [
        '1001' => ['Cash in Hand', 'asset', 'debit'],
        '1100' => ['Accounts Receivable', 'asset', 'debit'],
        '1200' => ['Inventory', 'asset', 'debit'],
        '1330' => ['VAT Input', 'asset', 'debit'],
        '2100' => ['Accounts Payable', 'liability', 'credit'],
        '2110' => ['VAT Payable', 'liability', 'credit'],
        '2115' => ['Goods Received Not Invoiced', 'liability', 'credit'],
        '4001' => ['Sales Revenue', 'income', 'credit'],
        '5001' => ['Cost of Goods Sold', 'expense', 'debit'],
        '5100' => ['Purchases', 'expense', 'debit'],
        '6000' => ['Landed Cost Expense', 'expense', 'debit'],
    ];
}

function coaGuardAccount(string $code): Account
{
    [$name, $type, $nature] = coaGuardChartMap()[$code];

    return Account::create([
        'code' => $code,
        'name' => $name,
        'account_type' => $type,
        'nature' => $nature,
        'level' => 1,
        'is_group' => false,
        'is_posting' => true,
        'is_active' => true,
        'currency' => 'BDT',
        'opening_balance' => 0,
    ]);
}

/**
 * The full chart, minus the codes a test wants to be missing.
 *
 * The table is cleared first because it is not empty on a fresh database:
 * `modules/PURCHASE/Database/Migrations/2026_11_03_000075_add_grni_to_receipts.php` creates
 * account 2115 itself ("if demo seeder not run"). That migration is the reason a stock install
 * has exactly one account in its chart — and therefore the reason every posting path below took
 * its null branch in production, not just in tests.
 */
function coaGuardChartExcept(string ...$missing): void
{
    // forceDelete: `code` carries a UNIQUE index and Account soft-deletes, so a soft-deleted
    // 2115 would still collide with the one this test needs to create.
    Account::query()->forceDelete();

    // Cast both sides: PHP turns a numeric-string array key into an int, so array_keys() yields
    // int 1200 while $missing holds string '1200' — and a strict in_array() then never matches,
    // quietly building the full chart in every "missing account" test.
    $missing = array_map('strval', $missing);

    foreach (array_keys(coaGuardChartMap()) as $code) {
        $code = (string) $code;

        if (! in_array($code, $missing, true)) {
            coaGuardAccount($code);
        }
    }
}

function coaGuardParty(string $type, string $suffix): Partner
{
    return Partner::create([
        'partner_code' => strtoupper(substr($type, 0, 3)).'-COA-'.$suffix,
        'type' => $type,
        'name' => 'Chart Of Accounts Co',
    ]);
}

function coaGuardWarehouse(): Warehouse
{
    return Warehouse::create(['name' => 'COA Warehouse', 'code' => 'WH-COA-1']);
}

function coaGuardProduct(float $cost = 100.0, string $type = 'stockable'): Product
{
    static $n = 0;
    $n++;

    return Product::create([
        'name' => 'COA Widget '.$n,
        'sku' => 'COA-WID-'.$n,
        'type' => $type,
        'status' => 'active',
        'valuation_method' => 'weighted',
        'cost_price' => $cost,
        'selling_price' => 150.0,
    ]);
}

/** Stock movement is not what these tests are about; keep it out of the way. */
function coaGuardStubStock(): void
{
    test()->mock(InventoryStockProviderInterface::class, function ($mock) {
        $mock->shouldReceive('deductStock')->andReturnNull();
        $mock->shouldReceive('restock')->andReturnNull();
        $mock->shouldReceive('reserveStock')->andReturnNull();
        $mock->shouldReceive('releaseReservation')->andReturnNull();
    });
}

function coaGuardBill(VendorBillService $service, array $overrides = []): VendorBill
{
    $vendor = coaGuardParty('vendor', 'BILL');
    $warehouse = coaGuardWarehouse();
    $product = coaGuardProduct();

    return $service->create(array_merge([
        'vendor_id' => $vendor->id,
        'warehouse_id' => $warehouse->id,
        'bill_date' => '2026-08-15',
        'subtotal' => 200.00,
        'discount_amount' => 0,
        'vat_amount' => 0,
        'total_amount' => 200.00,
        'items' => [[
            'product_id' => $product->id,
            'qty' => 2,
            'unit_price' => 100.00,
            'discount_pct' => 0,
            'vat_rate' => 0,
        ]],
    ], $overrides));
}

function coaGuardInvoice(InvoiceService $service, float $costPrice = 100.0): Invoice
{
    $customer = coaGuardParty('customer', 'INV');
    $warehouse = coaGuardWarehouse();
    $product = coaGuardProduct($costPrice, $costPrice > 0 ? 'stockable' : 'service');

    return $service->create([
        'customer_id' => $customer->id,
        'warehouse_id' => $warehouse->id,
        'invoice_date' => '2026-08-15',
        'subtotal' => 150.00,
        'discount_amount' => 0,
        'vat_amount' => 0,
        'total_amount' => 150.00,
        'items' => [[
            'product_id' => $product->id,
            'qty' => 1,
            'unit_price' => 150.00,
            'cost_price' => $costPrice,
            'discount_pct' => 0,
            'vat_rate' => 0,
        ]],
    ]);
}

/** Comment-stripped source, so an explanatory comment can never satisfy or break a scan. */
function coaGuardStripComments(string $source): string
{
    $out = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= $token[1];

            continue;
        }
        $out .= $token;
    }

    return $out;
}

// ─────────────────────────── vendor bills ───────────────────────────

test('a vendor bill cannot be marked posted when the payable account is missing', function () {
    $this->actingAs(coaGuardAdmin());
    coaGuardFiscalYear();
    coaGuardChartExcept('2100');

    $service = app(VendorBillService::class);
    $bill = coaGuardBill($service);

    expect(fn () => $service->post($bill))->toThrow(DomainException::class, '2100');

    $fresh = $bill->fresh();
    expect($fresh->status)->toBe('draft', 'the bill was marked posted with no ledger entry behind it')
        ->and($fresh->journal_id)->toBeNull()
        ->and(Journal::count())->toBe(0, 'a journal was written even though the posting was refused');
});

test('a vendor bill posts a balanced journal when the chart is complete', function () {
    $this->actingAs(coaGuardAdmin());
    coaGuardFiscalYear();
    coaGuardChartExcept();

    $service = app(VendorBillService::class);
    $bill = coaGuardBill($service);
    $posted = $service->post($bill);

    expect($posted->status)->toBe('posted')
        ->and($posted->journal_id)->not->toBeNull('the bill posted without a journal');

    $journal = Journal::find($posted->journal_id);
    expect((float) $journal->total_debit)->toBe(200.0)
        ->and((float) $journal->total_credit)->toBe(200.0)
        ->and($journal->status)->toBe('posted');

    $apId = Account::where('code', '2100')->value('id');
    expect((float) JournalLine::where('journal_id', $journal->id)->where('account_id', $apId)->value('credit'))
        ->toBe(200.0, 'payables did not move');
});

test('a bill carrying VAT cannot post when the VAT input account is missing', function () {
    $this->actingAs(coaGuardAdmin());
    coaGuardFiscalYear();
    coaGuardChartExcept('1330');

    $service = app(VendorBillService::class);
    $bill = coaGuardBill($service, [
        'subtotal' => 200.00,
        'vat_amount' => 30.00,
        'total_amount' => 230.00,
        'items' => [[
            'product_id' => coaGuardProduct()->id,
            'qty' => 2,
            'unit_price' => 100.00,
            'discount_pct' => 0,
            'vat_rate' => 15,
        ]],
    ]);

    // Before the guard this threw "Journal not balanced: debit 200 != credit 230" — true, but it
    // never said which account was missing, and only because the imbalance happened to surface.
    expect(fn () => $service->post($bill))->toThrow(DomainException::class, '1330');

    expect($bill->fresh()->status)->toBe('draft');
});

test('a bill cannot be recorded as paid when the cash account is missing', function () {
    $this->actingAs(coaGuardAdmin());
    coaGuardFiscalYear();
    coaGuardChartExcept('1001');

    $service = app(VendorBillService::class);
    $bill = coaGuardBill($service);
    $bill->update(['status' => 'posted']);

    expect(fn () => $service->recordPayment($bill, [
        'payment_date' => '2026-08-20',
        'amount' => 50.00,
    ]))->toThrow(DomainException::class, '1001');

    $fresh = $bill->fresh();
    expect(PurchasePayment::count())->toBe(0, 'a payment row survived the refused posting')
        ->and((float) $fresh->paid_amount)->toBe(0.0, 'the bill was marked as paying down a debt the ledger never saw')
        ->and($fresh->payment_status)->toBe('unpaid');
});

// ─────────────────────────── sales invoices ───────────────────────────

test('an invoice cannot be marked posted when the receivable account is missing', function () {
    $this->actingAs(coaGuardAdmin());
    coaGuardFiscalYear();
    coaGuardChartExcept('1100');
    config(['sales.stock.deduct_on_invoice' => false]);

    $service = app(InvoiceService::class);
    $invoice = coaGuardInvoice($service);

    expect(fn () => $service->post($invoice))->toThrow(DomainException::class, '1100');

    $fresh = $invoice->fresh();
    expect($fresh->status)->toBe('draft')
        ->and($fresh->journal_id)->toBeNull()
        ->and(Journal::count())->toBe(0);
});

test('an invoice cannot post a balanced journal that silently omits COGS', function () {
    $this->actingAs(coaGuardAdmin());
    coaGuardFiscalYear();
    coaGuardChartExcept('5001');
    config(['sales.stock.deduct_on_invoice' => false]);

    $service = app(InvoiceService::class);
    $invoice = coaGuardInvoice($service, 100.0);

    // The dangerous shape: AR Dr 150 / Sales Cr 150 balances perfectly, so nothing downstream
    // can object — the 100 of cost simply never reaches the ledger and profit is overstated.
    expect(fn () => $service->post($invoice))->toThrow(DomainException::class, '5001');

    expect($invoice->fresh()->status)->toBe('draft');
});

test('an invoice with no cost still posts when the COGS account is absent', function () {
    $this->actingAs(coaGuardAdmin());
    coaGuardFiscalYear();
    coaGuardChartExcept('5001', '1200');
    config(['sales.stock.deduct_on_invoice' => false]);

    test()->mock(InventoryValuationProviderInterface::class, function ($mock) {
        $mock->shouldReceive('getUnitCost')->andReturn(0.0);
    });

    $service = app(InvoiceService::class);
    $invoice = coaGuardInvoice($service, 0.0);
    $posted = $service->post($invoice);

    // The guard must not over-fire: a service line legitimately has no cost leg.
    expect($posted->status)->toBe('posted')
        ->and($posted->journal_id)->not->toBeNull();

    $journal = Journal::find($posted->journal_id);
    expect((float) $journal->total_debit)->toBe(150.0)
        ->and(JournalLine::where('journal_id', $journal->id)->count())->toBe(2, 'AR and Sales only');
});

// ─────────────────────── returns and receipts ───────────────────────

test('a purchase return cannot be marked posted when the inventory account is missing', function () {
    $this->actingAs(coaGuardAdmin());
    coaGuardFiscalYear();
    coaGuardChartExcept('1200');
    coaGuardStubStock();

    $service = app(PurchaseReturnService::class);
    $vendor = coaGuardParty('vendor', 'RET');
    $warehouse = coaGuardWarehouse();
    $product = coaGuardProduct();

    $return = $service->create([
        'vendor_id' => $vendor->id,
        'warehouse_id' => $warehouse->id,
        'return_date' => '2026-08-15',
        'subtotal' => 100.00,
        'discount_amount' => 0,
        'vat_amount' => 0,
        'total_amount' => 100.00,
        'items' => [[
            'product_id' => $product->id,
            'qty' => 1,
            'unit_price' => 100.00,
            'discount_pct' => 0,
            'vat_rate' => 0,
        ]],
    ]);

    expect(fn () => $service->post($return))->toThrow(DomainException::class, '1200');

    $fresh = $return->fresh();
    expect($fresh->status)->toBe('draft', 'stock already left the warehouse for a return the ledger never saw')
        ->and($fresh->journal_id)->toBeNull();
});

test('a goods receipt cannot post without its GRNI accrual', function () {
    $this->actingAs(coaGuardAdmin());
    coaGuardFiscalYear();
    coaGuardChartExcept('2115');
    coaGuardStubStock();

    $service = app(GoodsReceiptService::class);
    $vendor = coaGuardParty('vendor', 'GRN');
    $warehouse = coaGuardWarehouse();
    $product = coaGuardProduct();

    $receipt = GoodsReceipt::create([
        'receipt_no' => 'GRN-COA-1',
        'vendor_id' => $vendor->id,
        'warehouse_id' => $warehouse->id,
        'receipt_date' => '2026-08-15',
        'subtotal' => 200.00,
        'discount_amount' => 0,
        'total_amount' => 200.00,
        'status' => 'draft',
    ]);
    $receipt->items()->create([
        'product_id' => $product->id,
        'product_name' => $product->name,
        'qty' => 2,
        'unit_price' => 100.00,
        'line_total' => 200.00,
    ]);

    // Without the accrual, the later bill debits Inventory a second time instead of reversing
    // GRNI — the receipt is not a harmless no-op, it changes how the bill is accounted.
    expect(fn () => $service->post($receipt))->toThrow(DomainException::class, '2115');

    expect($receipt->fresh()->status)->toBe('draft');
});

// ─────────────────────── drift guard on the sources ───────────────────────

test('every document service resolves accounts through the guarded choke point', function () {
    $root = dirname(__DIR__, 3);

    // A scan that finds nothing passes vacuously, so the floor is asserted too.
    $services = [
        'modules/PURCHASE/Services/VendorBillService.php' => 6,
        'modules/SALES/Services/InvoiceService.php' => 7,
        'modules/SALES/Services/SalesReturnService.php' => 5,
        'modules/PURCHASE/Services/PurchaseReturnService.php' => 3,
        'modules/PURCHASE/Services/GoodsReceiptService.php' => 2,
        'modules/PURCHASE/Services/LandedCostService.php' => 2,
        'modules/INVENTORY/Services/KitService.php' => 1,
    ];

    $scanned = 0;
    $totalGuards = 0;

    foreach ($services as $relative => $minimum) {
        $path = $root.'/'.$relative;
        expect(is_file($path))->toBeTrue("expected service is missing: {$relative}");

        $source = coaGuardStripComments((string) file_get_contents($path));

        expect(substr_count($source, 'accountIdByCode('))->toBe(0,
            "{$relative} still resolves an account with the nullable lookup, so a missing code can skip the ledger again");

        $guards = substr_count($source, 'ChartOfAccounts::requireId(')
            + substr_count($source, 'ChartOfAccounts::firstExistingId(');
        expect($guards)->toBeGreaterThanOrEqual($minimum,
            "{$relative} has {$guards} guarded resolutions, expected at least {$minimum}");

        $totalGuards += $guards;
        $scanned++;
    }

    expect($scanned)->toBe(7, 'the service list drifted')
        ->and($totalGuards)->toBeGreaterThan(20, 'suspiciously few guarded resolutions across seven services');

    // The nullable lookup stays on the provider for genuinely optional callers; it is the
    // document services that must not use it.
    $provider = $root.'/modules/ACCOUNTS/Services/AccountsPostingProvider.php';
    expect(is_file($provider))->toBeTrue()
        ->and(substr_count(coaGuardStripComments((string) file_get_contents($provider)), 'accountIdByCode'))->toBeGreaterThan(0);

    expect(is_file($root.'/modules/ACCOUNTS/Support/ChartOfAccounts.php'))->toBeTrue(
        'the ChartOfAccounts choke point is missing');
});
