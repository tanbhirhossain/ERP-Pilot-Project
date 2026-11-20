<?php

/**
 * The POS session aggregate — report item 26 / P1.2.
 *
 * `PosRepository::createSale()` folds each sale into its session's running total. Nothing in the
 * suite reached that branch before this file: `PosTerminalTest` only renders the terminal and
 * `PosVoidTest` builds its `PosSale` rows with `PosSale::create()`, so `session_id` was always null
 * and the `if ($sale->session_id)` guard always fell through. The line was changed under item 26
 * without a single test executing it. This closes that.
 *
 * The branch is live in production: `Pos/Terminal.vue:177` posts `session_id`, `PosSaleRequest`
 * validates it, `PosController:93` puts it in the DTO and `PosService::sell():57` forwards it here.
 *
 * Helpers are prefixed `psa` because every Feature file loads into one process.
 */

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\INVENTORY\Models\Warehouse;
use Modules\SALES\Interfaces\PosRepositoryInterface;
use Modules\SALES\Models\PosSale;
use Modules\SALES\Models\PosSession;

/** A warehouse the till can sell from. `inventory_warehouses.type` is CHECK-constrained. */
function psaWarehouse(): Warehouse
{
    return Warehouse::create([
        'name' => 'Till Warehouse',
        'code' => 'WH-PSA-1',
        'type' => 'main',
    ]);
}

/** An open session, created directly so the aggregate under test is the only thing that writes it. */
function psaSession(Warehouse $warehouse): PosSession
{
    return PosSession::create([
        'session_no' => 'POS-SESSION-PSA-'.PosSession::count(),
        'warehouse_id' => $warehouse->id,
        'opened_by' => test()->actingAsUser->id,
        'status' => 'open',
        'total_sales' => '0.00',
        'transactions_count' => 0,
    ]);
}

/**
 * Run a sale through the repository, the way `PosService::sell()` does.
 *
 * Line items are left out on purpose: the aggregate reads `total_amount` off the header and never
 * looks at the lines, so a product fixture here would only add a second way for the test to fail.
 *
 * @param  array<string, mixed>  $overrides
 */
function psaSell(PosSession $session, array $overrides = []): PosSale
{
    return app(PosRepositoryInterface::class)->createSale($overrides + [
        'session_id' => $session->id,
        'warehouse_id' => $session->warehouse_id,
        'sale_date' => now()->toDateString(),
        'payment_method' => 'cash',
        'status' => 'completed',
        'subtotal' => '100.00',
        'total_amount' => '100.00',
        'paid_amount' => '100.00',
        'cash_amount' => '100.00',
        'items' => [],
    ]);
}

/** Every SQL statement the query log holds, newest last. @return list<string> */
function psaQueries(): array
{
    return array_column(DB::getQueryLog() ?? [], 'query');
}

/**
 * Did the repository issue an UPDATE against the session table?
 *
 * Matching on `str_contains($sql, 'update')` is not enough: every INSERT in this schema lists an
 * `updated_at` column, so the word "update" appears in statements that change no aggregate.
 */
function psaSessionUpdate(): ?string
{
    return collect(psaQueries())->first(
        static fn (string $sql): bool => str_starts_with(strtolower(ltrim($sql)), 'update')
            && str_contains($sql, 'pos_sessions')
    );
}

beforeEach(function () {
    $this->actingAsUser = User::factory()->create();
    $this->actingAs($this->actingAsUser);
    config(['hashing.bcrypt.rounds' => 4]);
});

test('sales fold into their session total as exact decimal, and count themselves', function () {
    $session = psaSession(psaWarehouse());

    psaSell($session, ['total_amount' => '1234.56', 'subtotal' => '1234.56', 'paid_amount' => '1234.56']);
    psaSell($session, ['total_amount' => '0.07', 'subtotal' => '0.07', 'paid_amount' => '0.07']);

    $fresh = $session->fresh();

    // Both the column and the model cast are decimal(15,2), so this is exact decimal text, not a
    // float that has been re-rounded on the way out.
    expect($fresh->total_sales)->toBe('1234.63')
        ->and($fresh->transactions_count)->toBe(2)
        ->and((string) DB::table('pos_sessions')->where('id', $session->id)->value('total_sales'))->toBe('1234.63');
});

test('the aggregate statement carries exact decimal text, not a float rendering', function () {
    $session = psaSession(psaWarehouse());

    DB::flushQueryLog();
    DB::enableQueryLog();

    // The top of the column's own range: decimal(15,2) holds thirteen integer digits, so this is a
    // value the schema permits and not an invented extreme. Passed as a string, which is what the
    // decimal cast hands every caller anyway.
    psaSell($session, [
        'total_amount' => '1234567890123.45',
        'subtotal' => '1234567890123.45',
        'paid_amount' => '1234567890123.45',
    ]);

    $update = psaSessionUpdate();

    expect($update)->not->toBeNull('no UPDATE against pos_sessions was issued at all');

    // The mechanism, pinned where it is portable. Concatenating a float into the statement produced
    // `total_sales + 1234567890123.4` at PHP's default precision of 14 significant digits — five
    // cents quietly dropped from the till's running total. Reading the stored value back cannot
    // show this on SQLite, where a decimal column has NUMERIC affinity and the value returns as a
    // double, so the assertion is on the SQL text: identical on SQLite and on MySQL.
    expect($update)->toContain('1234567890123.45')
        ->and($update)->not->toContain('1234567890123.4 ');

    DB::disableQueryLog();
});

/**
 * This used to assert the opposite: that a sale naming no session *succeeds* and merely skips the
 * aggregate update. That is exactly what it did, and that was the problem. The day-end posting is
 * the only code that puts a POS day into the ledger and it reaches its sales through the session,
 * so a session-less sale was completed, took its stock off the shelf, kept its cash in the till,
 * and never appeared in revenue, output VAT or cost of goods sold — and no report listed it either,
 * because the collection report is filtered by drawer. The sale is now refused at the service.
 */
/**
 * The guard lives in `PosService::sell()`, not in the repository — `psaSell()` above writes through
 * the repository directly so the aggregate can be probed without a product fixture, so it cannot see
 * this rule. These two go through the service, and need a real product to sell.
 */
test('a sale with no session is refused, because nothing would ever post it', function () {
    $warehouse = psaWarehouse();
    $session = psaSession($warehouse);
    $product = psaProduct($warehouse);

    $before = PosSale::count();

    expect(fn () => app(\Modules\SALES\Services\PosService::class)->sell(new \Modules\SALES\DTO\PosSaleData(
        warehouseId: $warehouse->id,
        lines: [new \Modules\SALES\DTO\InvoiceLineData(productId: $product->id, qty: 1, unitPrice: 100.0)],
        sessionId: null,
    )))->toThrow(\DomainException::class, 'A sale needs an open drawer');

    expect(PosSale::count())->toBe($before, 'a refused sale still wrote a row')
        ->and(PosSession::count())->toBe(1, 'the refused sale created or deleted a session');
});

test('a sale still sells against an open session', function () {
    // The guard must not have broken the path it exists to protect.
    $warehouse = psaWarehouse();
    $session = psaSession($warehouse);
    $product = psaProduct($warehouse);

    $sale = app(\Modules\SALES\Services\PosService::class)->sell(new \Modules\SALES\DTO\PosSaleData(
        warehouseId: $warehouse->id,
        lines: [new \Modules\SALES\DTO\InvoiceLineData(productId: $product->id, qty: 1, unitPrice: 100.0)],
        sessionId: $session->id,
    ));

    expect($sale->exists)->toBeTrue()
        ->and((int) $sale->session_id)->toBe((int) $session->id);
});

/** A stocked product the service can actually take off a shelf. */
function psaProduct(?\Modules\INVENTORY\Models\Warehouse $warehouse = null): \Modules\INVENTORY\Models\Product
{
    $warehouse ??= psaWarehouse();

    $product = \Modules\INVENTORY\Models\Product::create([
        'name' => 'PSA Widget',
        'sku' => 'PSA-W-1',
        'type' => 'stockable',
        'cost_price' => 40,
        'selling_price' => 100,
        'is_active' => true,
        'status' => 'active',
    ]);

    app(\Modules\INVENTORY\Services\StockService::class)->move(new \Modules\INVENTORY\DTO\StockMoveData(
        productId: $product->id,
        variantId: null,
        warehouseId: $warehouse->id,
        locationId: null,
        qty: 10,
        direction: 'in',
        unitCost: 40.0,
    ));

    return $product;
}

test('the aggregate update still stamps updated_at', function () {
    $session = psaSession(psaWarehouse());

    // `Eloquent\Builder::increment()` adds the timestamp itself; the base query builder does not.
    // Pinning it means a future switch to DB::table() cannot silently stop stamping the row.
    DB::table('pos_sessions')->where('id', $session->id)->update(['updated_at' => '2001-01-01 00:00:00']);

    psaSell($session);

    expect((string) DB::table('pos_sessions')->where('id', $session->id)->value('updated_at'))
        ->not->toBe('2001-01-01 00:00:00');
});
