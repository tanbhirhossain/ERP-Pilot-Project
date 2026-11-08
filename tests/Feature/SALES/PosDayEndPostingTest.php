<?php

/**
 * POS day-end posting — report item 39.
 *
 * The counter used to account for itself per sale. `PosService::sell()` created a `sales_invoices`
 * row for every till transaction and posted it immediately, so a 200-sale day wrote 200 invoices and
 * 200 journals, while `closeSession()` — the only event that counts the drawer — wrote nothing to
 * the ledger at all. Two consequences beyond the noise, both of which this file pins closed:
 *
 *  - `JournalService::syncPayableReceivable()` books `total_debit` as an open receivable for any
 *    `SV` journal that carries a customer. A cash sale at the till is settled before the receipt
 *    prints, yet it produced an open AR row, so AR aging was full of money nobody owed.
 *  - The day's takings could not be reconciled to a posting, because there was no posting: cash in
 *    the drawer, card settlements and credit sales each went their own way per transaction.
 *
 * The model here is the standard Z-report. The sale is recorded and the stock leaves in real time —
 * inventory has to be true at the counter, not at midnight — and the accounting happens once, when
 * the session closes: a single balanced journal per session carrying cash, bank, receivables,
 * revenue, VAT and COGS, with credit sales raising one receivable per customer and counter
 * collections of earlier dues settling them inside the day that collected the money.
 *
 * Helpers are prefixed `pdp` because every Feature file loads into one process.
 */

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\ACCOUNTS\Models\Bank;
use Modules\ACCOUNTS\Models\Receivable;
use Modules\INVENTORY\DTO\StockMoveData;
use Modules\INVENTORY\Models\Product;
use Modules\INVENTORY\Models\Stock;
use Modules\INVENTORY\Models\Warehouse;
use Modules\INVENTORY\Services\StockService;
use Modules\PARTNER\Models\Partner;
use Modules\SALES\DTO\InvoiceLineData;
use Modules\SALES\DTO\PosSaleData;
use Modules\SALES\Models\PosSale;
use Modules\SALES\Models\PosSession;
use Modules\SALES\Services\PosService;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    config(['hashing.bcrypt.rounds' => 4]);

    $this->actingAs(User::where('email', config('core.super_admin.email', 'admin@example.com'))->first()
        ?? User::first());
});

/** A till that can actually hand over stock: warehouse, one product at a known cost, 100 units in. */
function pdpTill(float $unitCost = 5.00, float $qtyIn = 100.0): array
{
    $warehouse = Warehouse::create([
        'name' => 'POS Till Warehouse',
        'code' => 'WH-PDP-1',
        'type' => 'main',
        'is_active' => true,
        'allow_negative_stock' => false,
    ]);

    $product = Product::create([
        'name' => 'PDP Counter Item',
        'sku' => 'PDP-ITEM-1',
        'type' => 'stockable',
        'status' => 'active',
        'valuation_method' => 'weighted',
        'cost_price' => $unitCost,
        'selling_price' => 100.00,
    ]);

    app(StockService::class)->move(new StockMoveData(
        productId: $product->id,
        variantId: null,
        warehouseId: $warehouse->id,
        locationId: null,
        qty: $qtyIn,
        direction: 'in',
        uomId: null,
        unitCost: $unitCost,
        referenceType: 'pdp_fixture',
        referenceNo: 'PDP-OPENING',
        lotNo: null,
    ));

    return [$warehouse, $product];
}

/** An open drawer with a known float. */
function pdpSession(int $warehouseId, float $openingCash = 1000.00): PosSession
{
    return app(PosService::class)->openSession([
        'warehouse_id' => $warehouseId,
        'opening_cash' => $openingCash,
    ]);
}

function pdpCustomer(string $name = 'PDP Credit Customer', array $overrides = []): Partner
{
    // `partners` keys its code on `partner_code` and has no `code` column. An invented key in a
    // mass-assign is not a no-op, it is a SQL error.
    return Partner::create($overrides + [
        'name' => $name,
        'type' => 'customer',
        'partner_code' => 'PDP-CUST-'.(Partner::count() + 1),
        'is_active' => true,
    ]);
}

/**
 * Sell through the real service.
 *
 * @param  array<string, mixed>  $overrides
 */
function pdpSell(PosSession $session, Product $product, float $qty, float $unitPrice, array $overrides = []): PosSale
{
    $dto = new PosSaleData(
        warehouseId: $session->warehouse_id,
        lines: [new InvoiceLineData(
            productId: $product->id,
            qty: $qty,
            unitPrice: $unitPrice,
            discountPct: $overrides['discountPct'] ?? 0,
            vatRate: $overrides['vatRate'] ?? 0,
        )],
        paymentMethod: $overrides['paymentMethod'] ?? 'cash',
        customerId: $overrides['customerId'] ?? null,
        customerName: $overrides['customerName'] ?? 'Walk-in Customer',
        bankId: $overrides['bankId'] ?? null,
        cashAmount: $overrides['cashAmount'] ?? 0,
        bankAmount: $overrides['bankAmount'] ?? 0,
        sessionId: $session->id,
    );

    unset($overrides['paymentMethod'], $overrides['customerId'], $overrides['customerName'],
        $overrides['bankId'], $overrides['cashAmount'], $overrides['bankAmount'],
        $overrides['vatRate'], $overrides['discountPct']);

    return app(PosService::class)->sell($dto);
}

/** The debit and credit a journal wrote to one account code, as decimal strings. */
function pdpLeg(int $journalId, string $code): array
{
    $row = DB::table('acc_journal_lines as l')
        ->join('acc_accounts as a', 'a.id', '=', 'l.account_id')
        ->where('l.journal_id', $journalId)
        ->where('a.code', $code)
        ->selectRaw('COALESCE(SUM(l.debit), 0) as dr, COALESCE(SUM(l.credit), 0) as cr')
        ->first();

    return [(float) $row->dr, (float) $row->cr];
}

function pdpStockQty(int $productId, int $warehouseId): float
{
    return (float) (Stock::where('product_id', $productId)->where('warehouse_id', $warehouseId)->value('qty_on_hand') ?? 0);
}

// ─────────────────────────────────────────────────────────────────────────────
// The sale is a sale, not an invoice
// ─────────────────────────────────────────────────────────────────────────────

test('a counter sale records the sale and writes no invoice and no journal', function () {
    [$warehouse, $product] = pdpTill();
    $session = pdpSession($warehouse->id);

    $sale = pdpSell($session, $product, 2, 100.00);

    expect($sale->pos_no)->toStartWith('POS-')
        ->and($sale->status)->toBe('completed')
        ->and((string) $sale->total_amount)->toBe('200.00');

    // The defect: every sale used to create and post an invoice, so the ledger took one entry per
    // transaction and AR aging filled up with settled counter sales.
    expect(DB::table('sales_invoices')->count())->toBe(0, 'a counter sale created a sales invoice')
        ->and(DB::table('acc_journals')->count())->toBe(0, 'a counter sale posted a journal before the session closed')
        ->and($sale->invoice_id)->toBeNull('the sale is still linked to an invoice document');
});

test('a counter sale takes the stock out immediately, at a cost snapshotted before the layers move', function () {
    [$warehouse, $product] = pdpTill(unitCost: 5.00);
    $session = pdpSession($warehouse->id);

    $before = pdpStockQty($product->id, $warehouse->id);

    $sale = pdpSell($session, $product, 3, 100.00);

    // Inventory is true at the counter. Waiting for the day-end would let the till oversell.
    expect(pdpStockQty($product->id, $warehouse->id))->toBe($before - 3.0)
        ->and(DB::table('inventory_stock_moves')
            ->where('reference_no', $sale->pos_no)
            ->where('direction', 'out')
            ->exists())->toBeTrue('no stock movement was written for the sale');

    // The cost is captured when the goods leave, not re-derived at close, or the day would be
    // valued at whatever the cost happens to be by then.
    expect((string) $sale->cost_total)->toBe('15.00');
});

// ─────────────────────────────────────────────────────────────────────────────
// Credit at the till
// ─────────────────────────────────────────────────────────────────────────────

test('a credit sale records the due instead of a payment, and refuses to be owed by nobody', function () {
    [$warehouse, $product] = pdpTill();
    $session = pdpSession($warehouse->id);
    $customer = pdpCustomer();

    $sale = pdpSell($session, $product, 2, 100.00, [
        'paymentMethod' => 'due',
        'customerId' => $customer->id,
        'customerName' => $customer->name,
    ]);

    expect((string) $sale->due_amount)->toBe('200.00')
        ->and((string) $sale->paid_amount)->toBe('0.00')
        ->and((string) $sale->cash_amount)->toBe('0.00')
        ->and((string) $sale->collected_amount)->toBe('0.00');

    // A receivable has to be owed by somebody, or the day-end journal debits AR with no subledger
    // behind it and the balance can never be chased.
    $thrown = null;

    try {
        pdpSell($session, $product, 1, 100.00, ['paymentMethod' => 'due']);
    } catch (DomainException $e) {
        $thrown = $e;
    }

    $this->assertNotNull($thrown, 'a credit sale with no customer was accepted');
    $this->assertStringContainsString('customer', strtolower($thrown->getMessage()));
});

// ─────────────────────────────────────────────────────────────────────────────
// The day-end posting
// ─────────────────────────────────────────────────────────────────────────────

test('closing a session posts one balanced journal carrying the whole day', function () {
    [$warehouse, $product] = pdpTill(unitCost: 5.00);
    $bank = Bank::first();
    $customer = pdpCustomer();
    $session = pdpSession($warehouse->id, openingCash: 1000.00);

    $this->assertNotNull($bank, 'the chart has no bank to settle card takings into');

    // 2 x 100.00 = 200.00 cash, no VAT.
    pdpSell($session, $product, 2, 100.00);
    // 1 x 500.00 + 10% VAT = 550.00 on card: net 500.00, VAT 50.00.
    pdpSell($session, $product, 1, 500.00, [
        'paymentMethod' => 'card',
        'bankId' => $bank->id,
        'vatRate' => 10,
    ]);
    // 6 x 100.00 = 600.00 on credit to a named customer.
    $due = pdpSell($session, $product, 6, 100.00, [
        'paymentMethod' => 'due',
        'customerId' => $customer->id,
        'customerName' => $customer->name,
    ]);

    // The day: net sales 1,300.00, VAT 50.00, takings 1,350.00 — 200.00 cash, 550.00 card,
    // 600.00 owed. Nine units left the shelf at 5.00, so COGS is 45.00.
    $closed = app(PosService::class)->closeSession($session->fresh(), [
        'closing_cash' => 1200.00,
        'notes' => 'Day end',
    ]);

    expect(DB::table('acc_journals')->count())->toBe(1, 'the close did not produce exactly one journal');

    $journal = DB::table('acc_journals')->first();

    expect($journal->type)->toBe('SV')
        ->and((string) $closed->journal_id)->toBe((string) $journal->id)
        ->and($closed->posted_at)->not->toBeNull()
        ->and((float) $journal->total_debit)->toBe((float) $journal->total_credit, 'the day-end journal does not balance');

    // Cash 200, card 550 into the bank's own GL account, credit 600 into AR.
    [$cashDr] = pdpLeg($journal->id, config('accounts.defaults.cash_code', '1001'));
    [$bankDr] = pdpLeg((int) $journal->id, (string) DB::table('acc_accounts')->where('id', $bank->account_id)->value('code'));
    [$arDr] = pdpLeg((int) $journal->id, config('accounts.defaults.receivable_code', '1100'));
    [$salesDr, $salesCr] = pdpLeg((int) $journal->id, config('accounts.defaults.sales_code', '4001'));
    [, $vatCr] = pdpLeg((int) $journal->id, config('accounts.defaults.vat_payable_code', '2110'));
    [$cogsDr] = pdpLeg((int) $journal->id, config('accounts.defaults.cogs_code', '5001'));
    [$invDr, $invCr] = pdpLeg((int) $journal->id, config('accounts.defaults.inventory_code', '1200'));

    expect($cashDr)->toBe(200.0)
        ->and($bankDr)->toBe(550.0)
        ->and($arDr)->toBe(600.0)
        ->and($salesCr)->toBe(1300.0, 'revenue is not the day\'s net sales')
        ->and($salesDr)->toBe(0.0)
        ->and($vatCr)->toBe(50.0, 'the day\'s VAT did not reach the payable account')
        ->and($cogsDr)->toBe(45.0, 'COGS is not nine units at 5.00')
        ->and($invCr)->toBe(45.0)
        ->and($invDr)->toBe(0.0);

    // What the close posted, kept on the session so the drawer reconciles without re-deriving it.
    expect((string) $closed->cash_total)->toBe('200.00')
        ->and((string) $closed->bank_total)->toBe('550.00')
        ->and((string) $closed->due_total)->toBe('600.00')
        ->and((string) $closed->net_sales_total)->toBe('1300.00')
        ->and((string) $closed->vat_total)->toBe('50.00')
        ->and((string) $closed->total_sales)->toBe('1350.00', 'the drawer total is net sales plus VAT')
        ->and((string) $closed->cogs_total)->toBe('45.00')
        ->and((int) $closed->transactions_count)->toBe(3)
        ->and((string) $closed->expected_cash)->toBe('1200.00', 'expected cash is not the float plus the cash taken')
        ->and((string) $closed->cash_difference)->toBe('0.00');

    // The journal belongs to the session, not to a customer: it spans several, and naming one
    // would make syncPayableReceivable() book the entire day as that customer's debt.
    expect($journal->partner_id)->toBeNull('the day-end journal names a partner, so the whole day becomes one receivable');

    expect($due->fresh()->status)->toBe('completed');
});

test('the day-end journal raises one receivable per credit customer and none for cash', function () {
    [$warehouse, $product] = pdpTill();
    $session = pdpSession($warehouse->id);

    $first = pdpCustomer('PDP Customer One');
    $second = pdpCustomer('PDP Customer Two');

    pdpSell($session, $product, 1, 100.00);                                   // cash, settled now
    pdpSell($session, $product, 2, 100.00, ['paymentMethod' => 'due', 'customerId' => $first->id, 'customerName' => $first->name]);
    pdpSell($session, $product, 3, 100.00, ['paymentMethod' => 'due', 'customerId' => $first->id, 'customerName' => $first->name]);
    pdpSell($session, $product, 1, 100.00, ['paymentMethod' => 'due', 'customerId' => $second->id, 'customerName' => $second->name]);

    app(PosService::class)->closeSession($session->fresh(), ['closing_cash' => 1100.00]);

    $receivables = DB::table('acc_receivables')->get();

    // Two credit customers, two receivables — not one per sale, and not one for the cash sale that
    // was settled before the receipt printed. That is the AR-aging defect this model removes.
    expect($receivables)->toHaveCount(2);

    $owedByFirst = $receivables->firstWhere('partner_id', $first->id);
    $owedBySecond = $receivables->firstWhere('partner_id', $second->id);

    expect((float) $owedByFirst->amount)->toBe(500.0, 'two credit sales to the same customer did not consolidate')
        ->and((float) $owedBySecond->amount)->toBe(100.0)
        ->and((float) $owedByFirst->received_amount)->toBe(0.0)
        ->and($owedByFirst->status)->toBe('open');

    // Both hang off the one session journal, so the subledger and the ledger agree.
    $journalId = DB::table('acc_journals')->value('id');

    expect($receivables->pluck('journal_id')->unique()->all())->toBe([$journalId]);
});

test('a session can only be posted once', function () {
    [$warehouse, $product] = pdpTill();
    $session = pdpSession($warehouse->id);

    pdpSell($session, $product, 1, 100.00);

    app(PosService::class)->closeSession($session->fresh(), ['closing_cash' => 1100.00]);

    expect(DB::table('acc_journals')->count())->toBe(1);

    $thrown = null;

    try {
        app(PosService::class)->closeSession($session->fresh(), ['closing_cash' => 1100.00]);
    } catch (DomainException $e) {
        $thrown = $e;
    }

    $this->assertNotNull($thrown, 'a closed session was closed again');
    $this->assertSame(1, DB::table('acc_journals')->count(), 'the second close posted a second journal for the same day');
});

test('a session with nothing in it closes without posting an empty journal', function () {
    [$warehouse] = pdpTill();
    $session = pdpSession($warehouse->id, openingCash: 500.00);

    $closed = app(PosService::class)->closeSession($session->fresh(), ['closing_cash' => 500.00]);

    expect($closed->status)->toBe('closed')
        ->and($closed->journal_id)->toBeNull('an empty drawer posted a journal')
        ->and(DB::table('acc_journals')->count())->toBe(0)
        ->and((string) $closed->expected_cash)->toBe('500.00')
        ->and((string) $closed->cash_difference)->toBe('0.00');
});

// ─────────────────────────────────────────────────────────────────────────────
// Collections at the counter
// ─────────────────────────────────────────────────────────────────────────────

test('collecting an earlier due at the till settles the receivable and joins that day\'s collection', function () {
    [$warehouse, $product] = pdpTill();
    $customer = pdpCustomer('PDP Debtor');

    // Day one: sell on credit and close, so the receivable exists.
    $dayOne = pdpSession($warehouse->id, openingCash: 0.00);
    $due = pdpSell($dayOne, $product, 4, 100.00, [
        'paymentMethod' => 'due',
        'customerId' => $customer->id,
        'customerName' => $customer->name,
    ]);
    app(PosService::class)->closeSession($dayOne->fresh(), ['closing_cash' => 0.00]);

    $receivable = DB::table('acc_receivables')->first();

    expect($receivable)->not->toBeNull()
        ->and((float) $receivable->amount)->toBe(400.0);

    $journalsAfterDayOne = DB::table('acc_journals')->count();

    // Day two: the customer pays 250 in cash at the counter.
    $dayTwo = pdpSession($warehouse->id, openingCash: 0.00);

    $collection = app(PosService::class)->collectDue($due->fresh(), [
        'amount' => 250.00,
        'method' => 'cash',
        'session_id' => $dayTwo->id,
    ]);

    expect((string) $collection->collection_no)->toStartWith('POS-COL-')
        ->and((string) $collection->amount)->toBe('250.00')
        ->and((string) $due->fresh()->collected_amount)->toBe('250.00')
        ->and((string) $due->fresh()->due_amount)->toBe('400.00', 'the original due must not be rewritten by a payment against it');

    // The receivable follows the collection: part paid, still open, and still balanceable.
    $receivable = DB::table('acc_receivables')->where('id', $receivable->id)->first();

    expect((float) $receivable->received_amount)->toBe(250.0)
        ->and((float) $receivable->balance)->toBe(150.0)
        ->and($receivable->status)->toBe('partial');

    // No journal of its own — the money is picked up by the day that collected it.
    expect(DB::table('acc_journals')->count())->toBe($journalsAfterDayOne, 'a counter collection posted its own journal');

    $closed = app(PosService::class)->closeSession($dayTwo->fresh(), ['closing_cash' => 250.00]);

    expect((string) $closed->collected_total)->toBe('250.00')
        ->and((string) $closed->cash_total)->toBe('250.00')
        ->and((string) $closed->net_sales_total)->toBe('0.00');

    // Day two's journal is the collection: Dr Cash, Cr AR. Nothing else moved.
    $journal = DB::table('acc_journals')->orderByDesc('id')->first();

    [$cashDr] = pdpLeg((int) $journal->id, config('accounts.defaults.cash_code', '1001'));
    [$arDr, $arCr] = pdpLeg((int) $journal->id, config('accounts.defaults.receivable_code', '1100'));

    expect($cashDr)->toBe(250.0)
        ->and($arCr)->toBe(250.0, 'the collection did not reduce receivables')
        ->and($arDr)->toBe(0.0, 'day two raised new receivables it did not sell')
        ->and((float) $journal->total_debit)->toBe((float) $journal->total_credit);
});

test('a collection cannot exceed what is owed, and cannot go into a closed drawer', function () {
    [$warehouse, $product] = pdpTill();
    $customer = pdpCustomer('PDP Overpayer');

    $dayOne = pdpSession($warehouse->id, openingCash: 0.00);
    $due = pdpSell($dayOne, $product, 2, 100.00, [
        'paymentMethod' => 'due',
        'customerId' => $customer->id,
        'customerName' => $customer->name,
    ]);
    app(PosService::class)->closeSession($dayOne->fresh(), ['closing_cash' => 0.00]);

    $over = null;

    try {
        app(PosService::class)->collectDue($due->fresh(), ['amount' => 250.00, 'method' => 'cash', 'session_id' => $dayOne->id]);
    } catch (DomainException $e) {
        $over = $e;
    }

    $this->assertNotNull($over, 'a collection larger than the outstanding due was accepted');
    $this->assertSame(0, DB::table('pos_collections')->count(), 'the refused collection was still written');

    // A closed drawer is already in the ledger; money into it would be unaccounted for.
    $closedDrawer = null;

    try {
        app(PosService::class)->collectDue($due->fresh(), ['amount' => 50.00, 'method' => 'cash', 'session_id' => $dayOne->id]);
    } catch (DomainException $e) {
        $closedDrawer = $e;
    }

    $this->assertNotNull($closedDrawer, 'a collection was accepted into a closed session');
    $this->assertStringContainsString('open', strtolower($closedDrawer->getMessage()));
});

// ─────────────────────────────────────────────────────────────────────────────
// Voids either side of the close
// ─────────────────────────────────────────────────────────────────────────────

test('the receivable is dated from the customer\'s own payment terms, not a global default', function () {
    [$warehouse, $product] = pdpTill();
    $session = pdpSession($warehouse->id);
    $customer = pdpCustomer('PDP Terms', ['payment_terms_days' => 7]);

    pdpSell($session, $product, 1, 1000.00, ['paymentMethod' => 'due', 'customerId' => $customer->id]);
    app(PosService::class)->closeSession($session, ['closing_cash' => 1000.00]);

    $receivable = Receivable::where('partner_id', $customer->id)->firstOrFail();

    // `(string) $carbon` carries the time even on a `date` cast, so compare date strings.
    $this->assertSame(
        Carbon::parse($receivable->due_date)->subDays(7)->toDateString(),
        $receivable->invoice_date->toDateString(),
        'the due date must be the sale date plus the customer\'s own terms'
    );
});

test('a credit sale that would take the customer past their credit limit is refused before stock moves', function () {
    [$warehouse, $product] = pdpTill();
    $session = pdpSession($warehouse->id);
    $customer = pdpCustomer('PDP Limited', ['credit_limit' => 500.00]);

    pdpSell($session, $product, 1, 400.00, ['paymentMethod' => 'due', 'customerId' => $customer->id]);
    app(PosService::class)->closeSession($session, ['closing_cash' => 1000.00]);

    // The first day's credit sale is now an open receivable of 400 against a 500 limit, so a further
    // 200 takes the customer to 600 and must be refused with nothing written and no stock moved.
    $second = pdpSession($warehouse->id);
    $stockBefore = pdpStockQty($product->id, $warehouse->id);

    try {
        pdpSell($second, $product, 1, 200.00, ['paymentMethod' => 'due', 'customerId' => $customer->id]);
        $this->fail('a credit sale past the customer\'s limit was accepted');
    } catch (DomainException $e) {
        $this->assertStringContainsString('credit limit', $e->getMessage());
    }

    $this->assertSame($stockBefore, pdpStockQty($product->id, $warehouse->id), 'a refused sale must not move stock');
    $this->assertSame(0, PosSale::where('session_id', $second->id)->count(), 'a refused sale must not be written');
});

test('a customer with no credit limit set is not limited by one', function () {
    [$warehouse, $product] = pdpTill();
    $session = pdpSession($warehouse->id);
    $customer = pdpCustomer('PDP Unlimited', ['credit_limit' => 0]);

    $sale = pdpSell($session, $product, 1, 50000.00, ['paymentMethod' => 'due', 'customerId' => $customer->id]);

    $this->assertSame('50000.00', (string) $sale->due_amount);
});

test('voiding before the close puts the stock back and leaves nothing for the close to post', function () {
    [$warehouse, $product] = pdpTill(unitCost: 5.00);
    $session = pdpSession($warehouse->id);

    $kept = pdpSell($session, $product, 1, 100.00);
    $mistake = pdpSell($session, $product, 4, 100.00);

    expect(pdpStockQty($product->id, $warehouse->id))->toBe(95.0);

    app(PosService::class)->void($mistake);

    expect($mistake->fresh()->status)->toBe('void')
        ->and(pdpStockQty($product->id, $warehouse->id))->toBe(99.0, 'the void did not put the stock back');

    $closed = app(PosService::class)->closeSession($session->fresh(), ['closing_cash' => 1100.00]);

    // The day is what was actually sold, not what was rung up.
    expect((string) $closed->net_sales_total)->toBe('100.00')
        ->and((string) $closed->cash_total)->toBe('100.00')
        ->and((string) $closed->cogs_total)->toBe('5.00')
        ->and((int) $closed->transactions_count)->toBe(1)
        ->and((int) $closed->void_count)->toBe(1);

    $journal = DB::table('acc_journals')->first();

    expect($journal)->not->toBeNull();

    [$cashDr] = pdpLeg((int) $journal->id, config('accounts.defaults.cash_code', '1001'));
    [, $salesCr] = pdpLeg((int) $journal->id, config('accounts.defaults.sales_code', '4001'));
    [$cogsDr] = pdpLeg((int) $journal->id, config('accounts.defaults.cogs_code', '5001'));

    expect($cashDr)->toBe(100.0)
        ->and($salesCr)->toBe(100.0)
        ->and($cogsDr)->toBe(5.0, 'a voided sale still carried its cost into the day');

    expect($kept->fresh()->status)->toBe('completed');
});

test('voiding after the close is refused, because the money is already in the ledger', function () {
    [$warehouse, $product] = pdpTill();
    $session = pdpSession($warehouse->id);

    $sale = pdpSell($session, $product, 2, 100.00);

    app(PosService::class)->closeSession($session->fresh(), ['closing_cash' => 1200.00]);

    $stockBefore = pdpStockQty($product->id, $warehouse->id);
    $journalsBefore = DB::table('acc_journals')->count();

    $thrown = null;

    try {
        app(PosService::class)->void($sale->fresh());
    } catch (DomainException $e) {
        $thrown = $e;
    }

    // Silently restocking a sale whose revenue is already posted would leave the ledger claiming
    // money the till no longer holds, with no reversal anywhere. Refusing is the honest answer;
    // the correction is a return in the next session.
    $this->assertNotNull($thrown, 'a sale in a posted session was voided');
    $this->assertStringContainsString('posted', strtolower($thrown->getMessage()));

    expect($sale->fresh()->status)->toBe('completed')
        ->and(pdpStockQty($product->id, $warehouse->id))->toBe($stockBefore, 'the refused void still moved stock')
        ->and(DB::table('acc_journals')->count())->toBe($journalsBefore);
});
