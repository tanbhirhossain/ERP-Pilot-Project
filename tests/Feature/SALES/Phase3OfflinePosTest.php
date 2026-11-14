<?php

/**
 * F22 — the offline till: what a queued ticket becomes when the network comes back.
 *
 * An offline POS is easy to demo and easy to get wrong in a way that only shows up as a drawer that
 * does not balance. The three properties this file exists to hold:
 *
 *  1. **A replay is a lookup, not an insert.** The device mints a UUID when the cashier presses Sell;
 *     it is unique on `pos_sales`. A till whose connection died one second after a committed sale will
 *     retry, and the retry must answer with the sale that already exists. Two sales from one ticket is
 *     stock gone twice, cash in the drawer the ledger never saw, and a day-end journal that balances
 *     against nothing.
 *  2. **One bad ticket cannot sink a batch.** A drawer closed while the network was down rejects *its*
 *     ticket; the other two land. A whole-batch 422 would leave a cashier choosing between resending
 *     duplicates and throwing sales away.
 *  3. **The ledger path is not re-implemented for offline.** Every row goes through `PosService::sell()`
 *     — stock moves, cost is snapshotted, the drawer's aggregates update — and *nothing* posts a
 *     journal, because the day still posts once when the drawer closes.
 *
 * `DatabaseSeeder` ships demo partners, products, warehouses and sales, so every count here is scoped
 * to rows this file created (`offlineRung()`, a UUID of its own making) rather than to table totals.
 *
 * Helpers are prefixed `pof` because Pest loads every Feature file into one process.
 */

use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\ACCOUNTS\Models\Journal;
use Modules\CORE\Models\Role;
use Modules\CORE\Models\User;
use Modules\INVENTORY\DTO\StockMoveData;
use Modules\INVENTORY\Models\Product;
use Modules\INVENTORY\Models\Stock;
use Modules\INVENTORY\Models\Warehouse;
use Modules\INVENTORY\Services\StockService;
use Modules\SALES\Models\PosSale;
use Modules\SALES\Models\PosSession;
use Modules\SALES\Requests\PosSaleRequest;
use Modules\SALES\Services\PosService;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    config(['hashing.bcrypt.rounds' => 4]);

    $this->actingAs(User::where('email', config('core.super_admin.email', 'admin@example.com'))->first() ?? User::first());
});

/** A warehouse with one priced product and known stock, so "the same path" is checkable. */
function pofTill(float $qtyIn = 100.0, int $extraProducts = 0): array
{
    $warehouse = Warehouse::create([
        'name' => 'Offline Till Store',
        'code' => 'WH-OFF-'.(Warehouse::count() + 1),
        'type' => 'main',
        'is_active' => true,
        'allow_negative_stock' => false,
    ]);

    $product = Product::create([
        'name' => 'Offline Counter Item',
        'sku' => 'OFF-ITEM-'.Str::random(4),
        'type' => 'stockable',
        'status' => 'active',
        'is_active' => true,
        'valuation_method' => 'weighted',
        'cost_price' => 40.00,
        'selling_price' => 100.00,
        'vat_rate' => 0,
        'barcode' => 'OFF'.random_int(10000, 99999),
    ]);

    for ($i = 1; $i <= $extraProducts; $i++) {
        Product::create([
            'name' => "Offline Extra Item {$i} ".Str::random(4),
            'sku' => 'OFF-EXTRA-'.Str::random(6),
            'type' => 'stockable',
            'status' => 'active',
            'is_active' => true,
            'valuation_method' => 'weighted',
            'cost_price' => 10.00,
            'selling_price' => 15.00,
            'vat_rate' => 0,
        ]);
    }

    app(StockService::class)->move(new StockMoveData(
        productId: $product->id,
        variantId: null,
        warehouseId: $warehouse->id,
        locationId: null,
        qty: $qtyIn,
        direction: 'in',
        uomId: null,
        unitCost: 40.00,
        referenceType: 'pof_fixture',
        referenceNo: 'POF-OPENING',
        lotNo: null,
    ));

    return [$warehouse, $product];
}

function pofSession(int $warehouseId, bool $closeIt = false): PosSession
{
    $session = app(PosService::class)->openSession([
        'warehouse_id' => $warehouseId,
        'opening_cash' => 0,
    ]);

    // Only one open drawer per warehouse, so a test that needs a closed one closes it as it opens it.
    return $closeIt ? app(PosService::class)->closeSession($session, []) : $session;
}

/** One row exactly as a till would queue it: totals are not sent, the server computes them. */
function pofRow(Product $product, int $warehouseId, array $overrides = []): array
{
    return $overrides + [
        'client_ref' => (string) Str::uuid(),
        'warehouse_id' => $warehouseId,
        'payment_method' => 'cash',
        'items' => [[
            'product_id' => $product->id,
            'qty' => 2,
            'unit_price' => 100.00,
            'discount_pct' => 0,
            'vat_rate' => 0,
        ]],
    ];
}

/** @param  array<int, array<string, mixed>>  $rows */
function pofSync(array $rows): TestResponse
{
    return test()->postJson('/sales/pos/offline/sync', ['sales' => array_values($rows)]);
}

function pofAvailable(int $productId, int $warehouseId): float
{
    return (float) (Stock::where('product_id', $productId)->where('warehouse_id', $warehouseId)->value('qty_available') ?? 0);
}

/**
 * The field list the JS payload builder writes, read out of `payload.ts`.
 *
 * The offline rows and the live till both build their body there, and both are validated here — so if
 * someone adds `'loyalty_id' => [...]` to `rowRules()` and forgets the builder, or the reverse, the
 * sync starts 422-ing tickets in a rush three weeks later. This catches it at the commit instead.
 */
function pofPayloadFields(): array
{
    $source = file_get_contents(base_path('modules/SALES/Resources/js/offline/payload.ts'));
    $body = substr($source, strpos($source, 'const payload: SalePayload = {'));
    preg_match_all('/^\s{4}([a-z_]+):/m', $body, $matches);

    expect($matches[1])->not->toBeEmpty('the payload builder stopped assigning its fields literally; re-measure rather than delete this test');

    return $matches[1];
}

/** A user holding exactly one permission — the shape of a real, narrowly-scoped role. */
function pofUserWith(string $permission, int $salt): User
{
    $role = Role::create(['name' => "Pof Role {$salt}", 'guard_name' => 'web']);
    $role->givePermissionTo($permission);

    $user = User::create([
        'name' => "Pof Cashier {$salt}",
        'email' => "pof{$salt}@example.test",
        'password' => bcrypt('not-the-default'),
        'email_verified_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

/** Drop the identity `actingAs` installed, so the next request is a guest's. */
function pofAsGuest(): void
{
    test()->app('auth')->forgetGuards();
}

// ─────────────────────────────── the schema ───────────────────────────────

test('the two offline columns exist and the device key is enforced by the database', function () {
    expect(Schema::hasColumn('pos_sales', 'client_ref'))->toBeTrue()
        ->and(Schema::hasColumn('pos_sales', 'rang_up_at'))->toBeTrue();

    [$warehouse, $product] = pofTill();
    $ref = (string) Str::uuid();

    pofSync([pofRow($product, $warehouse->id, ['client_ref' => $ref])])->assertOk();

    // The catch in the service is only worth writing if the index that closes its race is real. A
    // unique *constraint*, not a unique *convention*: two tabs, one ticket.
    $threw = null;
    try {
        PosSale::create([
            'pos_no' => 'POS-DUP-'.random_int(1000, 9999),
            'client_ref' => $ref,
            'warehouse_id' => $warehouse->id,
            'sale_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'status' => 'completed',
        ]);
    } catch (QueryException $e) {
        $threw = $e;
    }

    expect($threw)->not->toBeNull('a duplicate client_ref was accepted, so a retry can double a sale');
});

// ───────────────────────────── posting a ticket ─────────────────────────────

test('a queued ticket posts through the same path as a live sale', function () {
    [$warehouse, $product] = pofTill();
    $session = pofSession($warehouse->id);
    $before = pofAvailable($product->id, $warehouse->id);

    $response = pofSync([pofRow($product, $warehouse->id, [
        'session_id' => $session->id,
        'rang_up_at' => now()->subMinutes(9)->toIso8601String(),
    ])]);

    $response->assertOk()
        ->assertJsonPath('synced', 1)
        ->assertJsonPath('rejected', 0)
        ->assertJsonPath('results.0.status', 'synced')
        ->assertJsonPath('results.0.total_amount', '200.00');

    $sale = PosSale::where('client_ref', $response->json('results.0.client_ref'))->firstOrFail();

    // The counter-clock time the till held on to, and the ledger's own date — which is today, because
    // the server decides what day money entered the books.
    expect($sale->rang_up_at->between(now()->subMinutes(10), now()->subMinutes(8)))->toBeTrue(
        'the counter-clock time was not carried across: it is what makes a replay auditable'
    )
        ->and($sale->created_at->greaterThan($sale->rang_up_at))->toBeTrue()
        ->and($sale->sale_date->toDateString())->toBe(now()->toDateString())
        // Stock left, the drawer counted it, and the money crossed the boundary as a string.
        ->and(pofAvailable($product->id, $warehouse->id))->toBe($before - 2.0)
        ->and((float) $session->fresh()->total_sales)->toBe(200.0)
        ->and((int) $session->fresh()->transactions_count)->toBe(1);
});

test('syncing writes no journal: the day still posts once, when the drawer closes', function () {
    [$warehouse, $product] = pofTill();
    $session = pofSession($warehouse->id);
    $journalsBefore = Journal::count();

    pofSync([pofRow($product, $warehouse->id, ['session_id' => $session->id])])->assertOk();

    expect(Journal::count())->toBe($journalsBefore)
        ->and($session->fresh()->journal_id)->toBeNull(
            'a sync that posted a journal would give the day two postings: its own, and the close’s'
        );
});

// ───────────────────────────── the replay ─────────────────────────────

test('replaying the same ticket writes one sale, not two', function () {
    [$warehouse, $product] = pofTill();
    $session = pofSession($warehouse->id);
    $row = pofRow($product, $warehouse->id, ['session_id' => $session->id]);

    $first = pofSync([$row])->assertOk()->assertJsonPath('synced', 1);
    $stockAfterFirst = pofAvailable($product->id, $warehouse->id);

    // Exactly what a phone does after a dropped connection: send the same batch again, unchanged.
    $second = pofSync([$row])->assertOk();

    expect($second->json('synced'))->toBe(0)
        ->and($second->json('duplicated'))->toBe(1)
        ->and($second->json('results.0.status'))->toBe('duplicate')
        ->and($second->json('results.0.reason'))->toBe('already-posted')
        // The duplicate answers with the sale it collided with, so the till can clear its queue and
        // print the receipt it never got.
        ->and($second->json('results.0.pos_no'))->toBe($first->json('results.0.pos_no'))
        ->and(PosSale::where('client_ref', $row['client_ref'])->count())->toBe(1)
        // The claim that matters at the end of the day: nothing moved twice.
        ->and(pofAvailable($product->id, $warehouse->id))->toBe($stockAfterFirst)
        ->and((float) $session->fresh()->total_sales)->toBe(200.0)
        ->and((int) $session->fresh()->transactions_count)->toBe(1);
});

test('a repeat of the same reference inside one batch posts once', function () {
    [$warehouse, $product] = pofTill();
    $row = pofRow($product, $warehouse->id);

    $response = pofSync([$row, $row])->assertOk();

    expect($response->json('synced'))->toBe(1)
        ->and($response->json('duplicated'))->toBe(1)
        ->and($response->json('results.1.reason'))->toBe('repeat-in-batch')
        ->and($response->json('results.1.status'))->toBe('duplicate')
        ->and(PosSale::where('client_ref', $row['client_ref'])->count())->toBe(1);
});

// ───────────────────────── per-row, not per-batch ─────────────────────────

test('one closed drawer rejects its own ticket and the rest of the batch lands', function () {
    [$warehouse, $product] = pofTill();
    $open = pofSession($warehouse->id);

    // A second warehouse, because the drawer that is closed has to have existed to be closed.
    [$other] = pofTill();
    $shut = pofSession($other->id, closeIt: true);

    $stamped = ['rang_up_at' => now()->subMinutes(4)->toIso8601String()];

    $response = pofSync([
        pofRow($product, $warehouse->id, ['session_id' => $open->id] + $stamped),
        pofRow($product, $warehouse->id, ['session_id' => $shut->id] + $stamped),
        pofRow($product, $warehouse->id, ['session_id' => $open->id] + $stamped),
    ])->assertOk();

    expect($response->json('synced'))->toBe(2)
        ->and($response->json('rejected'))->toBe(1)
        ->and($response->json('results.1.status'))->toBe('rejected')
        ->and($response->json('results.1.kind'))->toBe('business_rule')
        ->and($response->json('results.1.message'))->toContain('closed')
        ->and(PosSale::rungOnDevice()->count())->toBe(2)
        ->and((int) $open->fresh()->transactions_count)->toBe(2);
});

test('a ticket from yesterday is refused, and says which day it belongs to', function () {
    [$warehouse, $product] = pofTill();
    $row = pofRow($product, $warehouse->id, ['rang_up_at' => now()->subDay()->toIso8601String()]);

    $refused = pofSync([$row])->assertOk();

    expect($refused->json('rejected'))->toBe(1)
        ->and($refused->json('results.0.kind'))->toBe('stale_date')
        ->and($refused->json('results.0.message'))->toContain(now()->subDay()->toDateString())
        ->and(PosSale::where('client_ref', $row['client_ref'])->count())->toBe(0);

    // The escape valve is a human choice, not a device default: the same row with the flag posts.
    pofSync([$row + ['acknowledge_stale_date' => true]])->assertOk()->assertJsonPath('synced', 1);

    expect(PosSale::where('client_ref', $row['client_ref'])->count())->toBe(1)
        ->and(PosSale::where('client_ref', $row['client_ref'])->first()->sale_date->toDateString())
        ->toBe(now()->toDateString());
});

test('a row is validated by the till’s own rules, not a copy of them', function () {
    [$warehouse, $product] = pofTill();

    // `due` without a customer is the rule the till enforces at the field level; the service would
    // also refuse it, but the row has to come back with the field named so the ticket can be fixed.
    $response = pofSync([pofRow($product, $warehouse->id, ['payment_method' => 'due'])])->assertOk();

    expect($response->json('results.0.kind'))->toBe('validation')
        ->and($response->json('results.0.errors'))->toHaveKey('customer_id');

    // An invented method proves the shared `in:` rule is what refused it — a field the offline layer
    // has no opinion on, so the message is Laravel's default and not something this slice wrote.
    pofSync([pofRow($product, $warehouse->id, ['payment_method' => 'barter'])])
        ->assertOk()
        ->assertJsonPath('results.0.errors.payment_method.0', 'The selected payment method is invalid.');

    // And the *bounds* are shared too: `items` is capped at 50 by the till, so a 51-line ticket is
    // refused by that rule. Were the sync carrying its own list, this would have been accepted.
    $many = array_map(
        static fn (): array => ['product_id' => $product->id, 'qty' => 1, 'unit_price' => 1.00],
        range(1, 51)
    );
    pofSync([pofRow($product, $warehouse->id, ['items' => $many])])
        ->assertOk()
        ->assertJsonPath('results.0.status', 'rejected')
        ->assertJsonPath('results.0.errors.items.0', 'The items field must not have more than 50 items.');

    // Positive control on the same list: the till's rules are the ones in play.
    expect(PosSaleRequest::rowRules())->toHaveKey('items')
        ->and(PosSaleRequest::rowRules()['items'])->toContain('max:50');
});

test('a malformed batch is a 422 but a refused ticket is not', function () {
    [$warehouse, $product] = pofTill();

    // The envelope: a row with no device key is a broken till, not a broken sale.
    $noRef = pofSync([['payment_method' => 'cash']])->assertStatus(422);

    expect($noRef->json('errors'))->toHaveKey('sales.0.client_ref')
        ->and($noRef->json('errors')['sales.0.client_ref'][0])->toContain('device reference');

    // The limit is the batch's, and the message says so.
    $many = array_map(static fn (): array => pofRow($product, $warehouse->id), range(1, 26));
    pofSync($many)
        ->assertStatus(422)
        ->assertJsonPath('errors.sales.0', 'A sync may carry at most 25 tickets. The rest will go on the next flush.');

    // A ticket the ledger will not have is answered 200 with a verdict, so the queue can keep it.
    pofSync([pofRow($product, $warehouse->id, ['payment_method' => 'due'])])
        ->assertOk()
        ->assertJsonPath('results.0.status', 'rejected');
});

// ──────────────────────────── what the till saw ────────────────────────────

test('a price that moved while the device was offline is reported, not swallowed', function () {
    [$warehouse, $product] = pofTill();

    $response = pofSync([pofRow($product, $warehouse->id, [
        // The cached price list said 90.00 a unit; the ledger says 100.00.
        'expected_total' => 180.00,
    ])])->assertOk();

    expect($response->json('results.0.amount_drift'))->toBe('20.00')
        ->and($response->json('results.0.message'))->toContain('stale');

    // A till whose cache was current hears nothing about drift.
    $clean = pofSync([pofRow($product, $warehouse->id, ['expected_total' => 200.00])])->assertOk();

    expect($clean->json('results.0'))->not->toHaveKey('amount_drift');
});

// ───────────────────────────── who may do it ─────────────────────────────

test('the door is the till’s: no new permission, and no anonymous sync', function () {
    // Replay and desk carry exactly the terminal's permission, so a role that can work a till can
    // clear its queue. A `pos.offline.sync` grant would split one act in two.
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_contains($r->uri(), 'pos/offline'))
        ->all();

    expect($routes)->toHaveCount(2, 'the offline routes moved or multiplied')
        ->and(implode(' ', array_map(static fn ($r) => implode('|', $r->gatherMiddleware()), $routes)))
        ->not->toMatch('/permission:pos\.offline/');

    // A guest is not handed a JSON success.
    pofAsGuest();
    $this->postJson('/sales/pos/offline/sync', ['sales' => []])->assertStatus(401);

    // A user with an unrelated grant cannot push tickets into the books.
    $this->actingAs(pofUserWith('sales.invoices.view', 9101))
        ->postJson('/sales/pos/offline/sync', ['sales' => []])
        ->assertStatus(403);
});

test('a cashier who may sell may open the offline desk, and no one else', function () {
    $this->get('/sales/pos/offline')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('SALES::Pos/Offline')->has('posted'));

    $this->actingAs(pofUserWith('sales.invoices.view', 9102))->get('/sales/pos/offline')->assertForbidden();
});

test('the desk lists what the ledger accepted from an offline queue', function () {
    [$warehouse, $product] = pofTill();
    $row = pofRow($product, $warehouse->id, ['rang_up_at' => now()->subMinutes(3)->toIso8601String()]);
    $posted = pofSync([$row])->assertOk()->json('results.0');

    $props = $this->get('/sales/pos/offline')->assertOk()->viewData('page')['props'];

    $mine = collect($props['posted'])->firstWhere('client_ref', $row['client_ref']);

    expect($mine)->not->toBeNull()
        ->and($mine['pos_no'])->toBe($posted['pos_no'])
        ->and($mine['total_amount'])->toBe('200.00')
        ->and($mine['rang_up_at'])->not->toBeNull()
        ->and($props['maxBatch'])->toBe(25)
        // The desk is offered to the till because it is the till's; the prop is what the page gates on.
        ->and($props['canSell'])->toBeTrue()
        // The marker is the counter timestamp, not the key: a live double-tap carries a key too.
        ->and(PosSale::rungOnDevice()->count())->toBe(1);
});

// ───────────────────────────── the catalogue ─────────────────────────────

test('the catalogue snapshot answers the till with prices and stock', function () {
    [$warehouse, $product] = pofTill(extraProducts: 2);
    $active = Product::active()->count();

    $response = $this->getJson('/inventory/api/pos-catalog?warehouse_id='.$warehouse->id)->assertOk();

    expect($response->json('total'))->toBe($active)
        ->and($response->json('truncated'))->toBeFalse()
        ->and($response->json('items'))->toHaveCount($active)
        ->and($response->json('synced_at'))->not->toBeNull();

    $row = collect($response->json('items'))->firstWhere('id', $product->id);

    // Both key sets, because the till has two entry points and the offline path must feed the same
    // code as the live one rather than a translation layer of its own.
    expect($row['value'])->toBe($product->id)
        ->and($row['label'])->toBe($product->name)
        ->and($row['selling_price'])->toBe('100.00')
        ->and($row['price'])->toBe('100.00')
        ->and($row['barcode'])->toBe($product->barcode)
        ->and((float) $row['available'])->toBe(100.0)
        // Money as strings, and no margin: `cost_price` is not in this payload at all.
        ->and($row)->not->toHaveKey('cost_price');

    // A `limit` below the catalogue says so, rather than looking like a short shop.
    $tiny = $this->getJson('/inventory/api/pos-catalog?limit=1')->assertOk();

    expect($tiny->json('items'))->toHaveCount(1)->and($tiny->json('truncated'))->toBeTrue();
});

test('the catalogue refuses a user who may not see prices', function () {
    $this->actingAs(pofUserWith('accounts.journals.view', 9103))
        ->getJson('/inventory/api/pos-catalog')
        ->assertStatus(403)
        ->assertJsonPath('type', 'pos-catalog')
        ->assertJsonStructure(['requires_any_of']);

    // …and a guest gets nothing useful either.
    pofAsGuest();

    expect($this->getJson('/inventory/api/pos-catalog')->status())->not->toBe(200);
});

test('the payload the till builds is the payload the request validates', function () {
    $rules = array_keys(PosSaleRequest::rowRules());

    // Every field the builder writes must be a field the till validates …
    foreach (pofPayloadFields() as $field) {
        $this->assertContains($field, $rules, "payload.ts sends `{$field}`, which PosSaleRequest knows nothing about");
    }

    // … and the reference that makes a replay safe is validated on the live path too, so a
    // double-tapped Sell cannot become a double sale.
    $this->assertContains('client_ref', $rules,
        'the till no longer carries a device reference on the live path, so a slow response and a second tap is two sales');
});

test('a double-tapped Sell at the till is one sale, online or off', function () {
    [$warehouse, $product] = pofTill();
    $session = pofSession($warehouse->id);
    $ref = (string) Str::uuid();

    $payload = pofRow($product, $warehouse->id, ['client_ref' => $ref, 'session_id' => $session->id]);
    unset($payload['expected_total']);

    $first = $this->post('/sales/pos/sell', $payload);
    $first->assertRedirect();

    // Same body twice, as the till sends it when the response is slow: the second is answered with the
    // receipt of the sale that happened, and nothing else changes.
    $second = $this->post('/sales/pos/sell', $payload);

    $second->assertRedirect()->assertSessionHas('success');
    expect((string) session('success'))->toContain('already recorded');

    expect(PosSale::where('client_ref', $ref)->count())->toBe(1)
        ->and((int) $session->fresh()->transactions_count)->toBe(1)
        ->and(pofAvailable($product->id, $warehouse->id))->toBe(98.0)
        // A live sale is not a device-rung one: it has no counter timestamp, only a key.
        ->and(PosSale::rungOnDevice()->count())->toBe(0);
});

test('the offline door is reachable by name and never shadows a sale', function () {
    // `pos/{sale}` is the last route in the group; `pos/offline` before it is not a nicety, it is the
    // difference between a desk and a 404 on a model id that happens to spell "offline".
    expect(Route::has('sales.pos.offline'))->toBeTrue()
        ->and(Route::has('sales.pos.offline.sync'))->toBeTrue();

    $uris = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($r) => $r->uri())
        ->filter(fn (string $u) => str_starts_with($u, 'sales/pos/'))
        ->values()
        ->all();

    $offline = array_search('sales/pos/offline', $uris, true);
    $wildcard = array_search('sales/pos/{sale}', $uris, true);

    expect($offline)->not->toBeFalse()
        ->and($wildcard)->not->toBeFalse()
        ->and($offline)->toBeLessThan($wildcard);
});
