<?php

/*
 * The POS collection register, and the drawer's close.
 *
 * Four gaps this file pins shut:
 *
 *  1. There was nowhere to read a collection. `pos_collections` rows could be seen one at a time on a
 *     sale's own page, or as a single summed figure inside a drawer's day-end row. The register
 *     (`PosReportService::collections()`, `sales.pos.collections`) lists them and groups them by the
 *     person who took the money.
 *  2. The day-end report attributes a collection to the drawer's opener, because that is whose day the
 *     ledger posts it into — correct for reconciling a till, and wrong for asking who collected what
 *     when two cashiers work one drawer. Both views now exist and this file asserts they differ, so
 *     neither can be "simplified" into the other.
 *  3. Closing a drawer demanded a counted figure, and the till sent 0 whenever the field was left
 *     alone, so every uncounted drawer was recorded as short by its entire takings. `PosService` had
 *     always treated the count as optional; the controller and the till did not.
 *  4. Closing returned the operator to the till with a flash message. The Z-report the close produces
 *     was reachable only by going to POS Drawers and finding the row — so it read as "not working".
 */

use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Inertia;
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
    // Closing posts a journal, so the chart of accounts and an open fiscal year have to exist. Seed
    // the real base data rather than hand-building a chart that could drift from the shipped one.
    $this->seed(DatabaseSeeder::class);
    config(['hashing.bcrypt.rounds' => 4]);
});

/** A till user with exactly the POS permissions named. */
function pcxUser(string $name, string $email, array $perms): User
{
    test()->seed(SuperAdminSeeder::class);

    foreach (['pos.terminal.sell', 'pos.sessions.manage', 'pos.reports.view', 'dashboard.view'] as $perm) {
        Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'PCX '.implode('-', $perms), 'guard_name' => 'web']);
    $role->syncPermissions(array_merge($perms, ['dashboard.view']));

    $user = User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('password')]);
    $user->assignRole($role);

    return $user;
}

/** A warehouse with one product on the shelf. */
function pcxTill(): array
{
    $warehouse = Warehouse::create([
        'name' => 'PCX Warehouse', 'code' => 'WH-PCX-1', 'type' => 'main',
        'is_active' => true, 'is_default' => true, 'allow_negative_stock' => true,
    ]);

    $product = Product::create([
        'name' => 'PCX Widget', 'sku' => 'PCX-W-1', 'type' => 'stockable', 'status' => 'active',
        'valuation_method' => 'weighted', 'cost_price' => 10.00, 'selling_price' => 100.00,
    ]);

    Stock::updateOrCreate(
        ['product_id' => $product->id, 'warehouse_id' => $warehouse->id],
        ['qty_on_hand' => 200, 'qty_reserved' => 0, 'qty_available' => 200],
    );

    return [$warehouse, $product];
}

function pcxCustomer(string $name = 'PCX Debtor'): Partner
{
    return Partner::create([
        'name' => $name, 'type' => 'customer',
        'partner_code' => 'PCX-CUST-'.(Partner::count() + 1),
        'is_active' => true, 'credit_limit' => 0,
    ]);
}

/**
 * Straight through the model rather than the service, so two cashiers can each hold a drawer in the
 * same warehouse — the service allows one open drawer per till, which is a rule about a physical
 * till and not about a fixture.
 */
function pcxOpen(int $warehouseId, User $cashier, float $float = 0.0): PosSession
{
    $session = PosSession::create([
        'session_no' => 'PCX-SESSION-'.str_pad((string) (PosSession::count() + 1), 3, '0', STR_PAD_LEFT),
        'warehouse_id' => $warehouseId,
        'opened_by' => $cashier->id,
        'opening_cash' => $float,
        'status' => 'open',
        'opened_at' => now(),
    ]);

    auth()->login($cashier);

    return $session;
}

function pcxSell(PosService $pos, PosSession $session, Product $product, float $qty, float $price, array $overrides = []): PosSale
{
    return $pos->sell(new PosSaleData(
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
        paidAmount: $overrides['paidAmount'] ?? 0,
    ));
}

/**
 * One credit sale rung up by A, and one payment against it taken by B *into A's still-open drawer*.
 *
 * That single arrangement is what separates the two reports: the ledger books the money into A's day,
 * because A's drawer took it, while the person who actually took it was B.
 */
function pcxDay(): array
{
    $pos = app(PosService::class);
    [$warehouse, $product] = pcxTill();
    $debtor = pcxCustomer();

    $a = pcxUser('PCX Cashier A', 'pcx.a@example.com', ['pos.terminal.sell', 'pos.sessions.manage']);
    $b = pcxUser('PCX Cashier B', 'pcx.b@example.com', ['pos.terminal.sell', 'pos.sessions.manage']);

    $sessionA = pcxOpen($warehouse->id, $a, 500.00);
    pcxSell($pos, $sessionA, $product, 5, 100.00);                                     // cash 500
    $credit = pcxSell($pos, $sessionA, $product, 2, 100.00, [                          // credit 200
        'paymentMethod' => 'due', 'customerId' => $debtor->id, 'customerName' => $debtor->name,
    ]);

    auth()->login($b);
    $collection = $pos->collectDue($credit, [
        'amount' => 150.00, 'method' => 'cash', 'session_id' => $sessionA->id,
        'notes' => 'part payment at the other till, taken by B',
    ]);

    return compact('pos', 'warehouse', 'product', 'debtor', 'a', 'b', 'sessionA', 'credit', 'collection');
}

// ─────────────────────────────────────────────────────────────────────────────
// The register
// ─────────────────────────────────────────────────────────────────────────────

test('the register lists each collection with its context, as decimal text', function () {
    $day = pcxDay();

    $report = app(PosReportService::class)->collections([]);

    expect($report['rows'])->toHaveCount(1);
    $row = $report['rows'][0];

    $this->assertSame($day['collection']->collection_no, $row['collection_no']);
    $this->assertSame('PCX Cashier B', $row['collector'], 'the person who took the money');
    $this->assertSame('PCX Debtor', $row['customer']);
    $this->assertSame($day['credit']->pos_no, $row['sale_no'], 'so the payment can be traced to its sale');
    $this->assertSame($day['sessionA']->session_no, $row['session_no'], 'and to the drawer that took it');
    $this->assertSame('cash', $row['method']);

    // Decimal text, not a float rendering: this is money on a screen somebody reconciles against.
    $this->assertSame('150.00', $row['amount']);
    $this->assertSame('150.00', $report['totals']['total']);
    $this->assertSame('150.00', $report['totals']['cash']);
    $this->assertSame('0.00', $report['totals']['bank']);
    $this->assertSame(1, $report['totals']['collections']);
});

test('the register groups by the collector while the day-end groups by the drawer that took it', function () {
    $day = pcxDay();

    // A second payment on the same sale, into the same drawer, taken by the drawer's own opener. Two
    // collectors at one drawer is the only arrangement that separates the two reports: grouped by
    // collector it is two buckets, grouped by drawer it collapses into one.
    auth()->login($day['a']);
    $day['pos']->collectDue($day['credit']->fresh(), [
        'amount' => 50.00, 'method' => 'card', 'session_id' => $day['sessionA']->id,
        'notes' => 'balance settled by A',
    ]);

    $reports = app(PosReportService::class);
    $byCollector = collect($reports->collections([])['byCollector'])->keyBy('user_id');
    $byDrawer = collect($reports->dayEnd([])['byUser'])->keyBy('user_id');

    expect($byCollector)->toHaveCount(2, 'two people took money at this drawer, so two buckets');
    expect($byCollector->keys()->sort()->values()->all())
        ->toBe(collect([$day['a']->id, $day['b']->id])->sort()->values()->all());

    // B took the first payment at A's till, so the register is B's for that money — and only B's.
    expect($byCollector[$day['b']->id]['collector'])->toBe('PCX Cashier B')
        ->and($byCollector[$day['b']->id]['collections'])->toBe(1)
        ->and($byCollector[$day['b']->id]['total'])->toBe('150.00')
        ->and($byCollector[$day['b']->id]['cash'])->toBe('150.00')
        ->and($byCollector[$day['b']->id]['bank'])->toBe('0.00');

    // A took the balance, by card, which is money but not cash in the drawer.
    expect($byCollector[$day['a']->id]['collector'])->toBe('PCX Cashier A')
        ->and($byCollector[$day['a']->id]['total'])->toBe('50.00')
        ->and($byCollector[$day['a']->id]['bank'])->toBe('50.00')
        ->and($byCollector[$day['a']->id]['cash'])->toBe('0.00');

    // The ledger posts both into A's day, because A's drawer took them. Both views are right; they
    // answer different questions, and neither may be folded into the other.
    expect($byDrawer[$day['a']->id]['collected'])->toBe('200.00')
        ->and($byDrawer[$day['a']->id]['due'])->toBe('200.00');
});

test('the register narrows by collector, customer, method and date', function () {
    $day = pcxDay();
    $reports = app(PosReportService::class);
    $today = now()->toDateString();

    expect($reports->collections(['from' => $today, 'to' => $today])['rows'])->toHaveCount(1, 'the collection is today\'s');

    expect($reports->collections(['user_id' => $day['b']->id])['rows'])->toHaveCount(1, 'B took it');
    expect($reports->collections(['user_id' => $day['a']->id])['rows'])->toHaveCount(0, 'A did not');

    expect($reports->collections(['partner_id' => $day['debtor']->id])['rows'])->toHaveCount(1);
    expect($reports->collections(['method' => 'cash'])['rows'])->toHaveCount(1);
    expect($reports->collections(['method' => 'card'])['rows'])->toHaveCount(0);

    $yesterday = now()->subDay()->toDateString();
    expect($reports->collections(['from' => $yesterday, 'to' => $yesterday])['rows'])->toHaveCount(0, 'not yesterday');
    expect($reports->collections(['from' => $yesterday, 'to' => $yesterday])['totals']['total'])->toBe('0.00');
});

test('a collection cannot exist without a drawer, so the register has no orphan bucket', function () {
    $day = pcxDay();

    // Guard one: the service refuses money with no open session to take it in.
    try {
        $day['pos']->collectDue($day['credit'], ['amount' => 10.00, 'method' => 'cash', 'session_id' => null]);
        $this->fail('a collection with no drawer must be refused');
    } catch (DomainException $e) {
        $this->assertStringContainsString('must name the open session', $e->getMessage());
    }

    // Guard two: the column is NOT NULL, so no other writer can slip one in either. Copied from a
    // real row so that session_id is the only constraint this insert can break.
    $copy = (array) DB::table('pos_collections')->firstOrFail();
    unset($copy['id']);
    $copy['collection_no'] = 'PCX-ORPHAN-1';
    $copy['session_id'] = null;

    try {
        DB::table('pos_collections')->insert($copy);
        $this->fail('the schema must refuse a collection with no drawer');
    } catch (QueryException $e) {
        $this->assertStringContainsString('NOT NULL constraint failed: pos_collections.session_id', $e->getMessage());
    }

    // So the register carries no bucket for one: a key that can only ever read zero is a claim that
    // orphaned money is a live worry here, and it is not.
    $totals = app(PosReportService::class)->collections([])['totals'];
    $this->assertArrayNotHasKey('without_session', $totals);
    $this->assertArrayNotHasKey('without_session_total', $totals);
    $this->assertSame('150.00', $totals['total'], 'the one real collection is still counted');
});

test('the register is refused over HTTP without pos.reports.view and renders with it', function () {
    $day = pcxDay();

    $this->actingAs($day['b'])->get(route('sales.pos.collections'))->assertForbidden();

    test()->seed(SuperAdminSeeder::class);
    $admin = User::where('email', config('core.super_admin.email', 'admin@example.com'))->firstOrFail();

    $this->actingAs($admin)
        ->get(route('sales.pos.collections'))
        ->assertOk()
        ->assertInertia(fn (Inertia $page) => $page
            ->has('report.rows')
            ->has('report.byCollector')
            ->has('report.totals')
            ->has('collectors')
            ->has('customers'));
});

// ─────────────────────────────────────────────────────────────────────────────
// The drawer's close
// ─────────────────────────────────────────────────────────────────────────────

test('a drawer closed without a count records no variance, and one closed with a count records the real one', function () {
    $day = pcxDay();

    // 500 float + 500 cash taken = 1000 expected. Closed with no count at all.
    $closed = $day['pos']->closeSession($day['sessionA']->fresh(), []);

    $this->assertNull($closed->closing_cash, 'no count was taken, so none is recorded');
    $this->assertNull($closed->cash_difference, 'and pretending there is a variance would be worse than null');
    // 500 float + 500 cash rung up + 150 collected into this drawer = what it should hold.
    $this->assertSame('1150.00', (string) $closed->fresh()->expected_cash, 'a collection taken in this drawer is part of its expected cash');

    // The same drawer, counted short by 100 — the variance the report needs.
    $second = pcxOpen($day['warehouse']->id, $day['a'], 0.0);
    pcxSell($day['pos'], $second, $day['product'], 1, 100.00);
    $counted = $day['pos']->closeSession($second->fresh(), ['closing_cash' => 0.00]);

    $this->assertSame('0.00', (string) $counted->closing_cash, 'a counted zero is a count, not an absence');
    $this->assertSame('-100.00', (string) $counted->cash_difference);
});

test('closing a drawer over HTTP lands the operator on its Z-report when they may read reports', function () {
    $day = pcxDay();
    $manager = pcxUser('PCX Manager', 'pcx.m@example.com', ['pos.terminal.sell', 'pos.sessions.manage', 'pos.reports.view']);

    $this->actingAs($manager)
        ->from(route('sales.pos.terminal'))
        ->post(route('sales.pos.sessions.close', $day['sessionA']->id), [])
        ->assertRedirect(route('sales.pos.sessions.dayEnd', $day['sessionA']->id));

    $this->assertSame('closed', $day['sessionA']->fresh()->status);
});

test('closing a drawer over HTTP returns to the till for a cashier who may not read reports', function () {
    $day = pcxDay();

    // Walking a cashier into the Z-report they have no permission for would trade one dead end for
    // another, so the close still succeeds and they simply stay where they were.
    $this->actingAs($day['b'])
        ->from(route('sales.pos.terminal'))
        ->post(route('sales.pos.sessions.close', $day['sessionA']->id), [])
        ->assertRedirect(route('sales.pos.terminal'));

    $this->assertSame('closed', $day['sessionA']->fresh()->status);
    $this->assertNull($day['sessionA']->fresh()->cash_difference, 'no count was sent, so none is invented');
});

test('the till tells the front end which drawers are open and what this cashier may do', function () {
    $day = pcxDay();

    // The till used to receive only the drawer the signed-in user had opened themselves, while
    // `show()` and `collect()` resolve the open drawer by warehouse — so a cashier standing at a
    // supervisor's till was told there was no session at all.
    $this->actingAs($day['b'])
        ->get(route('sales.pos.terminal'))
        ->assertOk()
        ->assertInertia(fn (Inertia $page) => $page
            ->where('canManageSessions', true)
            ->where('canViewReports', false)
            ->has('openSessions', 1)
            ->where('openSessions.0.id', $day['sessionA']->id)
            ->where('openSessions.0.warehouse_id', $day['warehouse']->id)
            ->where('openSessions.0.mine', false));

    $cashier = pcxUser('PCX Till Only', 'pcx.t@example.com', ['pos.terminal.sell']);

    $this->actingAs($cashier)
        ->get(route('sales.pos.terminal'))
        ->assertOk()
        ->assertInertia(fn (Inertia $page) => $page
            ->where('canManageSessions', false, 'a cashier who may not manage drawers must not be offered buttons that 403')
            ->where('canViewReports', false));
});
