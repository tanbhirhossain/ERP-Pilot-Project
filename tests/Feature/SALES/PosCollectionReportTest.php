<?php

/**
 * The user-wise POS collection report — report item 39.
 *
 * The day-end posting put one journal per drawer into the ledger, which answers "what did the till
 * do today". It does not answer the question a shop owner actually asks at night: *which cashier took
 * which money, and who collected what*. Those figures live across three tables now — `pos_sales` for
 * what was rung up, `pos_collections` for money taken against an earlier credit sale, and
 * `pos_sessions` for the drawer that owns both — so they are reported from here rather than left to
 * be reconstructed by hand.
 *
 * Two properties matter enough to pin:
 *
 *  - **A collection belongs to the cashier who took the money, not the one who made the sale.** The
 *    accounting already works that way — the collection posts as Dr cash, Cr receivables in the day
 *    that collected it — so a report that attributed it to the selling cashier would disagree with
 *    the ledger it is supposed to explain.
 *  - **An open drawer is reported too, from its sales rather than from its stored totals.** Those
 *    totals are only written at the close; a report that read them would show zeros for every till
 *    still trading, which is exactly when somebody wants to look.
 *
 * Helpers are prefixed `pcr` because every Feature file loads into one process.
 */

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\CORE\Models\User;
use Modules\INVENTORY\Models\Product;
use Modules\INVENTORY\Models\Stock;
use Modules\INVENTORY\Models\Warehouse;
use Modules\PARTNER\Models\Partner;
use Modules\SALES\DTO\InvoiceLineData;
use Modules\SALES\DTO\PosSaleData;
use Modules\SALES\Models\PosSale;
use Modules\SALES\Models\PosSession;
use Modules\SALES\Services\PosReportService;
use Modules\SALES\Services\PosService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    // The close posts a journal, so the chart of accounts, an open fiscal year and its periods have
    // to exist. Same base as PosDayEndPostingTest: seed the real base data rather than hand-building
    // a COA that could drift from the one the application ships.
    $this->seed(DatabaseSeeder::class);
    config(['hashing.bcrypt.rounds' => 4]);
});

/** A cashier who can ring up sales, with their own name on the report. */
function pcrCashier(string $name, string $email): User
{
    test()->seed(SuperAdminSeeder::class);

    Permission::firstOrCreate(['name' => 'pos.terminal.sell', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'pos.sessions.manage', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'pos.reports.view', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'dashboard.view', 'guard_name' => 'web']);

    $role = Role::firstOrCreate(['name' => 'PCR Cashier', 'guard_name' => 'web']);
    $role->givePermissionTo(['pos.terminal.sell', 'pos.sessions.manage', 'dashboard.view']);

    $user = User::create([
        'name' => $name,
        'email' => $email,
        'password' => bcrypt('password'),
    ]);
    $user->assignRole($role);

    return $user;
}

/** A till with stock in it: warehouse, one product at a known cost, 200 units on the shelf. */
function pcrTill(): array
{
    $warehouse = Warehouse::create([
        'name' => 'PCR Warehouse', 'code' => 'WH-PCR-1', 'type' => 'main',
        'is_active' => true, 'is_default' => true, 'allow_negative_stock' => true,
    ]);

    $product = Product::create([
        'name' => 'PCR Widget', 'sku' => 'PCR-W-1', 'type' => 'stockable', 'status' => 'active',
        // `inventory_products` prices on `selling_price` (with `mrp` alongside); there is no
        // `sale_price` column, and a made-up key in a mass-assign is a SQL error, not a no-op.
        'valuation_method' => 'weighted', 'cost_price' => 10.00, 'selling_price' => 100.00,
    ]);

    Stock::updateOrCreate(
        ['product_id' => $product->id, 'warehouse_id' => $warehouse->id],
        ['qty_on_hand' => 200, 'qty_reserved' => 0, 'qty_available' => 200],
    );

    return [$warehouse, $product];
}

function pcrOpen(PosService $pos, int $warehouseId, User $cashier, float $openingCash = 0.0): PosSession
{
    // Straight through the repository rather than the service, so two cashiers can each have a drawer
    // open in the same warehouse — the service allows one open session per warehouse, which is a rule
    // about a physical till, not about a report fixture.
    $session = PosSession::create([
        'session_no' => 'PCR-SESSION-'.str_pad((string) (PosSession::count() + 1), 3, '0', STR_PAD_LEFT),
        'warehouse_id' => $warehouseId,
        'opened_by' => $cashier->id,
        'opening_cash' => $openingCash,
        'status' => 'open',
        'opened_at' => now(),
    ]);

    auth()->login($cashier);

    return $session;
}

function pcrSell(PosService $pos, PosSession $session, Product $product, float $qty, float $price, array $overrides = []): void
{
    $pos->sell(new PosSaleData(
        warehouseId: $session->warehouse_id,
        lines: [new InvoiceLineData(
            productId: $product->id,
            qty: $qty,
            unitPrice: $price,
            vatRate: $overrides['vatRate'] ?? 0,
            discountPct: $overrides['discountPct'] ?? 0,
        )],
        paymentMethod: $overrides['paymentMethod'] ?? 'cash',
        customerId: $overrides['customerId'] ?? null,
        customerName: $overrides['customerName'] ?? 'Walk-in Customer',
        bankId: $overrides['bankId'] ?? null,
        sessionId: $session->id,
    ));
}

function pcrCustomer(string $name = 'PCR Debtor'): Partner
{
    return Partner::create([
        'name' => $name, 'type' => 'customer',
        'partner_code' => 'PCR-CUST-'.(Partner::count() + 1),
        'is_active' => true, 'credit_limit' => 0,
    ]);
}

/**
 * Two cashiers, two drawers, one credit sale collected by the *other* cashier.
 *
 *  - A: cash 500, card 300, credit 200 to the debtor; closes counting exactly what the day says.
 *  - B: cash 100, and collects 150 of A's credit sale at their own counter. Left open.
 *
 * @return array{pos: PosService, warehouse: Warehouse, product: Product, debtor: Partner, a: User, b: User, sessionA: PosSession, sessionB: PosSession}
 */
function pcrDay(): array
{
    $pos = app(PosService::class);
    [$warehouse, $product] = pcrTill();
    $debtor = pcrCustomer();

    $a = pcrCashier('PCR Cashier A', 'pcr.a@example.com');
    $b = pcrCashier('PCR Cashier B', 'pcr.b@example.com');

    $sessionA = pcrOpen($pos, $warehouse->id, $a, 1000.00);
    pcrSell($pos, $sessionA, $product, 5, 100.00);                                      // cash 500
    pcrSell($pos, $sessionA, $product, 3, 100.00, ['paymentMethod' => 'card']);          // card 300
    pcrSell($pos, $sessionA, $product, 2, 100.00, [                                      // credit 200
        'paymentMethod' => 'due', 'customerId' => $debtor->id, 'customerName' => $debtor->name,
    ]);

    $pos->closeSession($sessionA->fresh(), ['closing_cash' => 1500.00]); // 1000 float + 500 cash

    $sessionB = pcrOpen($pos, $warehouse->id, $b, 0.00);
    pcrSell($pos, $sessionB, $product, 1, 100.00);                                       // cash 100

    $creditSale = PosSale::where('session_id', $sessionA->id)
        ->where('payment_method', 'due')->firstOrFail();

    auth()->login($b);
    $pos->collectDue($creditSale, [
        'amount' => 150.00, 'method' => 'cash', 'session_id' => $sessionB->id,
        'notes' => 'part payment',
    ]);

    return compact('pos', 'warehouse', 'product', 'debtor', 'a', 'b', 'sessionA', 'sessionB');
}

// ─────────────────────────────────────────────────────────────────────────────
// The report itself
// ─────────────────────────────────────────────────────────────────────────────

test('the report gives one row per drawer, and reports an open drawer from its sales', function () {
    $day = pcrDay();

    $report = app(PosReportService::class)->dayEnd([
        'from' => now()->toDateString(),
        'to' => now()->toDateString(),
    ]);

    expect($report['rows'])->toHaveCount(2, 'both drawers belong to the day');

    $open = collect($report['rows'])->firstWhere('session_id', $day['sessionB']->id);
    expect($open)->not->toBeNull('the still-open drawer must be reported');

    // Its stored totals are all zero, because those are only written at the close. The report derives
    // them from the sales instead, so a till that is still trading is not reported as empty.
    $this->assertSame('0.00', (string) $day['sessionB']->fresh()->net_sales_total);
    $this->assertSame('100.00', $open['cash'], 'an open drawer reports the cash it has actually taken');
    $this->assertSame('150.00', $open['collected'], 'and the dues it has collected');
    $this->assertSame('100.00', $open['net']);
    $this->assertSame(1, $open['transactions']);
    $this->assertSame('open', $open['status']);
});

test('a collection is reported against the cashier who took the money, not the one who made the sale', function () {
    $day = pcrDay();

    $report = app(PosReportService::class)->dayEnd([]);
    $byUser = collect($report['byUser'])->keyBy('user_id');

    // A rang up the credit sale; B collected part of it at their own counter. The ledger already
    // books the collection into B's day, so the report has to agree with the ledger.
    expect($byUser[$day['a']->id]['due'])->toBe('200.00')
        ->and($byUser[$day['a']->id]['collected'])->toBe('0.00')
        ->and($byUser[$day['b']->id]['collected'])->toBe('150.00')
        ->and($byUser[$day['b']->id]['due'])->toBe('0.00')
        ->and($byUser[$day['b']->id]['cashier'])->toBe('PCR Cashier B');

    // Takings = what the drawer took in money: cash and card, plus dues collected, but not credit
    // sales, which are money the business is owed rather than money in the till.
    expect($byUser[$day['a']->id]['takings'])->toBe('800.00', 'A took 500 cash and 300 card')
        ->and($byUser[$day['b']->id]['takings'])->toBe('250.00', 'B took 100 cash and collected 150');

    expect($report['totals']['collected'])->toBe('150.00')
        ->and($report['totals']['takings'])->toBe('1050.00');
});

test('a closed drawer reports exactly the figures its posted day-end journal carries', function () {
    $day = pcrDay();

    $report = app(PosReportService::class)->dayEnd([]);
    $row = collect($report['rows'])->firstWhere('session_id', $day['sessionA']->id);
    $session = $day['sessionA']->fresh();

    $this->assertSame((string) $session->net_sales_total, $row['net']);
    $this->assertSame((string) $session->vat_total, $row['vat']);
    $this->assertSame((string) $session->cogs_total, $row['cogs']);
    $this->assertSame((string) $session->cash_total, $row['cash']);
    $this->assertSame((string) $session->bank_total, $row['bank']);
    $this->assertSame((string) $session->due_total, $row['due']);
    $this->assertSame((string) $session->collected_total, $row['collected']);
    $this->assertSame((int) $session->journal_id, $row['journal_id'], 'the row must point at its journal');
    $this->assertNotEmpty($row['journal_no'], 'so the Z-report can be opened from the report');

    // And the drawer reconciliation: float in, what the day says it should hold, what was counted.
    $this->assertSame('1000.00', $row['opening_cash']);
    $this->assertSame('1500.00', $row['expected_cash']);
    $this->assertSame('1500.00', $row['closing_cash']);
    $this->assertSame('0.00', $row['variance'], 'a count that matches reports no variance');
});

test('a drawer counted short reports the shortage as a negative variance', function () {
    $day = pcrDay();
    $pos = $day['pos'];
    [$warehouse, $product] = [$day['warehouse'], $day['product']];

    $c = pcrCashier('PCR Cashier C', 'pcr.c@example.com');
    $session = pcrOpen($pos, $warehouse->id, $c, 500.00);
    pcrSell($pos, $session, $product, 2, 100.00); // 200 cash, so 700 expected

    $pos->closeSession($session->fresh(), ['closing_cash' => 640.00]);

    $row = collect(app(PosReportService::class)->dayEnd([])['rows'])
        ->firstWhere('session_id', $session->id);

    $this->assertSame('700.00', $row['expected_cash']);
    $this->assertSame('-60.00', $row['variance'], 'short by 60, reported as a negative, not hidden');
});

test('the report narrows to one cashier, one warehouse or a date range', function () {
    $day = pcrDay();
    $service = app(PosReportService::class);
    $today = now()->toDateString();

    $onlyA = $service->dayEnd(['user_id' => $day['a']->id, 'from' => $today, 'to' => $today]);
    expect($onlyA['rows'])->toHaveCount(1)
        ->and($onlyA['byUser'])->toHaveCount(1)
        ->and($onlyA['byUser'][0]['cashier'])->toBe('PCR Cashier A');

    $yesterday = $service->dayEnd(['from' => now()->subDay()->toDateString(), 'to' => now()->subDay()->toDateString()]);
    expect($yesterday['rows'])->toBe([], 'a day with no drawers reports nothing rather than everything');

    $otherWarehouse = $service->dayEnd(['warehouse_id' => $day['warehouse']->id + 999]);
    expect($otherWarehouse['rows'])->toBe([]);

    $closed = $service->dayEnd(['status' => 'closed']);
    expect($closed['rows'])->toHaveCount(1)
        ->and($closed['rows'][0]['session_id'])->toBe($day['sessionA']->id);
});

test('a voided sale is counted as a void and drops out of the takings', function () {
    $day = pcrDay();
    $pos = $day['pos'];

    $c = pcrCashier('PCR Cashier D', 'pcr.d@example.com');
    $session = pcrOpen($pos, $day['warehouse']->id, $c, 0.00);
    pcrSell($pos, $session, $day['product'], 1, 100.00);
    pcrSell($pos, $session, $day['product'], 1, 250.00);

    $kept = PosSale::where('session_id', $session->id)->orderBy('id')->first();
    $pos->void($kept);

    $row = collect($service = app(PosReportService::class)->dayEnd([])['rows'])
        ->firstWhere('session_id', $session->id);

    $this->assertSame(1, $row['transactions'], 'a void is not a transaction');
    $this->assertSame(1, $row['voids'], 'but it is counted, because that is what a supervisor audits');
    $this->assertSame('250.00', $row['net'], 'and its money leaves the day');
});

test('the report publishes decimal text, not floats', function () {
    pcrDay();

    $report = app(PosReportService::class)->dayEnd([]);

    foreach ($report['rows'] as $row) {
        foreach (['net', 'vat', 'discount', 'cogs', 'cash', 'bank', 'due', 'collected', 'takings'] as $field) {
            $this->assertIsString($row[$field], "{$field} must be a decimal string");
            $this->assertMatchesRegularExpression('/^-?\d+\.\d{2}$/', $row[$field], "{$field} is not money text: {$row[$field]}");
        }
    }

    foreach ($report['byUser'] as $user) {
        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $user['takings']);
    }
});

test('the report is refused over HTTP without pos.reports.view', function () {
    $day = pcrDay();

    // A cashier can ring up sales and close a drawer, but the day-end report is a management view:
    // it shows every cashier's takings and variances side by side.
    $this->actingAs($day['a'])
        ->get(route('sales.pos.reports.dayEnd'))
        ->assertForbidden();

    test()->seed(SuperAdminSeeder::class);
    $admin = User::where('email', config('core.super_admin.email', 'admin@example.com'))->firstOrFail();

    $this->actingAs($admin)
        ->get(route('sales.pos.reports.dayEnd'))
        ->assertOk();
});
