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

test('a sale with no open session still sells and touches no aggregate', function () {
    $warehouse = psaWarehouse();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $sale = psaSell(psaSession($warehouse), ['session_id' => null]);

    expect($sale->exists)->toBeTrue()
        ->and($sale->pos_no)->not->toBeEmpty()
        ->and(PosSession::count())->toBe(1, 'a session-less sale created or deleted a session')
        ->and(psaSessionUpdate())->toBeNull('the aggregate ran for a sale that named no session');

    DB::disableQueryLog();
});

test('the aggregate update still stamps updated_at', function () {
    $session = psaSession(psaWarehouse());

    // `Eloquent\Builder::increment()` adds the timestamp itself; the base query builder does not.
    // Pinning it means a future switch to DB::table() cannot silently stop stamping the row.
    DB::table('pos_sessions')->where('id', $session->id)->update(['updated_at' => '2001-01-01 00:00:00']);

    psaSell($session);

    expect((string) DB::table('pos_sessions')->where('id', $session->id)->value('updated_at'))
        ->not->toBe('2001-01-01 00:00:00');
});
