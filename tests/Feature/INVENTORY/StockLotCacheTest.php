<?php

/**
 * The stock cache is an aggregate, and a lot named on the way out is honoured — report item 41, P1.25.
 *
 * The defect this file exists to keep fixed, in the two halves the audit report said must be fixed
 * together:
 *
 *  1. **The cache was keyed by lot on the way in and looked up lot-less on the way out.**
 *     `StockService::move()` included `lot_no` in the `inventory_stocks` lookup for both directions,
 *     but only `ReceiveStockAction` ever forwarded one — `IssueStockAction`,
 *     `InventoryStockProvider::deductStock()` and `::restock()` constructed their `StockMoveData`
 *     without a `lotNo` argument at all. So receive 10 against `LOT-RECEIVED` and then sell 4: the
 *     cache row was keyed `(product, warehouse, lot_no = 'LOT-RECEIVED')`, the sale looked for
 *     `(product, warehouse, lot_no IS NULL)`, found nothing, and threw `Insufficient stock … On-hand:
 *     0`. **Quantity received against a lot was unsellable.** Item 24 measured this by removing the
 *     skip from its own reproduction test; that test now runs, here and there.
 *
 *  2. **A lot the user typed disappeared before the ledger.** `DeliveryService::ship()` and
 *     `SalesReturnService` both put `lot_no` (and deliveries put `serial_no`) into the item arrays
 *     they hand to the stock layer, and `StoreDeliveryRequest` validates `items.*.lot_no` — so a lot
 *     number was validated, stored on the delivery item, and then dropped by the path that writes the
 *     movement. A recall could not be answered from the stock ledger for a delivery that named its lot.
 *
 * Fixing only the second half would have converted a lost lot number into a refused sale, which is
 * why the report coupled them. What this file pins is the shape that was chosen:
 *
 *  - `inventory_stocks` is an **aggregate** per product/variant/warehouse/location. That is not a new
 *    idea imposed on the module — it is what every other reader already assumed: `reserve()`,
 *    `release()`, `ValuationService`, `PosLookupController`, `InventoryStockProvider::available()`,
 *    `StockRepository` and `LandedCostService` all look the cache up without a lot. `move()` was the
 *    only writer that disagreed with them. Lot identity stays where it already lives and is already
 *    maintained: `inventory_lots` and `fifo_layers`.
 *  - The `lot_no` **column stays** on the cache and reads NULL, because `StockController` already
 *    sends it to the stock screen and a deployment's existing rows are merged rather than stranded.
 *  - A lot named on an issue is **recorded and honoured**: it reaches `stock_moves.lot_no`, and for a
 *    product whose layers are tracked the named lot is consumed before older ones, with FIFO order for
 *    any remainder. Recording without honouring would leave `Lot.qty_remaining` describing a different
 *    history from the delivery items — and a recall reads both.
 *  - A lot-less issue is **unchanged**: layers are consumed oldest-first, exactly as before. Tests 6
 *    and 11 exist so that the new behaviour cannot leak into the path every current caller uses.
 *
 * The merge of pre-existing lot-keyed rows is its own migration, deliberately pure DML so that it can
 * be run again inside a test against rows the test invented — test 10 does exactly that, because a
 * merge that cannot be exercised is a merge nobody can trust on the deployment that needs it.
 *
 * Helpers are prefixed `slc`; Pest loads every Feature file into one process.
 */

use Illuminate\Support\Facades\DB;
use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\CORE\Models\User;
use Modules\INVENTORY\Actions\IssueStockAction;
use Modules\INVENTORY\Actions\ReceiveStockAction;
use Modules\INVENTORY\Models\FifoLayer;
use Modules\INVENTORY\Models\Location;
use Modules\INVENTORY\Models\Lot;
use Modules\INVENTORY\Models\Product;
use Modules\INVENTORY\Models\Stock;
use Modules\INVENTORY\Models\StockMove;
use Modules\INVENTORY\Models\Warehouse;
use Modules\INVENTORY\Services\InventoryStockProvider;
use Modules\INVENTORY\Services\StockService;

function slcAdmin(): User
{
    test()->seed(SuperAdminSeeder::class);

    $admin = User::where('email', config('core.super_admin.email', 'admin@example.com'))->first();
    expect($admin)->not->toBeNull('SuperAdminSeeder produced no admin user');

    test()->actingAs($admin);

    return $admin;
}

function slcWarehouse(string $code = 'SLC-WH-1'): Warehouse
{
    return Warehouse::create([
        'name' => 'SLC Warehouse',
        'code' => $code,
        'type' => 'main',   // CHECK constraint: main|sub|transit|virtual
        'is_active' => true,
        'is_default' => true,
        'allow_negative_stock' => false,
    ]);
}

function slcProduct(string $valuation = 'fifo', string $sku = 'SLC-PROD-1', bool $batchTracked = false): Product
{
    return Product::create([
        'name' => 'SLC Cotton Fabric',
        'sku' => $sku,
        'type' => 'stockable',
        'status' => 'active',
        'valuation_method' => $valuation,
        'cost_price' => 5.00,
        'selling_price' => 9.50,
        'is_batch_tracked' => $batchTracked,
    ]);
}

function slcReceive(int $productId, int $warehouseId, float $qty, float $unitCost, ?string $lotNo = null, string $ref = 'SLC-RCV'): StockMove
{
    $moves = app(ReceiveStockAction::class)->execute([
        ['product_id' => $productId, 'warehouse_id' => $warehouseId, 'qty' => $qty, 'unit_cost' => $unitCost, 'lot_no' => $lotNo],
    ], 'opening', $ref);

    return $moves[0];
}

function slcIssue(int $productId, int $warehouseId, float $qty, float $unitCost, ?string $lotNo = null, ?string $serialNo = null, string $ref = 'SLC-ISS'): StockMove
{
    $moves = app(IssueStockAction::class)->execute([
        [
            'product_id' => $productId, 'warehouse_id' => $warehouseId, 'qty' => $qty,
            'unit_cost' => $unitCost, 'lot_no' => $lotNo, 'serial_no' => $serialNo,
        ],
    ], 'sale', $ref);

    return $moves[0];
}

/** The one cache row for a product/warehouse. Under the aggregate key there is never more than one. */
function slcStock(int $productId, int $warehouseId): ?Stock
{
    return Stock::where('product_id', $productId)->where('warehouse_id', $warehouseId)->first();
}

function slcLot(string $lotNo): ?Lot
{
    return Lot::where('lot_no', $lotNo)->first();
}

// ─────────────────────────────────────────────────────────────────────────────
// Half 1: quantity received against a lot is sellable
// ─────────────────────────────────────────────────────────────────────────────

test('quantity received against a lot is sellable without naming the lot', function () {
    slcAdmin();
    $warehouse = slcWarehouse();
    $product = slcProduct('weighted');

    slcReceive($product->id, $warehouse->id, 10, 5.00, 'LOT-RECEIVED');

    // Before the fix this was 10 on a lot-keyed row and nothing on the row an issue looks at.
    $stock = slcStock($product->id, $warehouse->id);
    expect($stock)->not->toBeNull()
        ->and((float) $stock->qty_on_hand)->toBe(10.0)
        ->and($stock->lot_no)->toBeNull('the cache is an aggregate; lot identity lives in inventory_lots');

    // The sale that used to throw "Insufficient stock … On-hand: 0".
    slcIssue($product->id, $warehouse->id, 4, 5.00);

    expect((float) slcStock($product->id, $warehouse->id)->qty_on_hand)->toBe(6.0);
});

test('the cache holds one aggregate row per product and warehouse, whatever lots arrive', function () {
    slcAdmin();
    $warehouse = slcWarehouse();
    $product = slcProduct('weighted');

    slcReceive($product->id, $warehouse->id, 10, 5.00, 'LOT-A');
    slcReceive($product->id, $warehouse->id, 5, 7.00, 'LOT-B');
    slcReceive($product->id, $warehouse->id, 3, 6.00, null);

    $rows = Stock::where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->get();

    expect($rows)->toHaveCount(1, 'three receipts against two lots produced more than one cache row')
        ->and((float) $rows->first()->qty_on_hand)->toBe(18.0)
        ->and((float) $rows->first()->qty_available)->toBe(18.0)
        ->and($rows->first()->lot_no)->toBeNull();

    // Weighted average across all three receipts: (50 + 35 + 18) / 18.
    $this->assertEqualsWithDelta(103.0 / 18.0, (float) $rows->first()->avg_cost, 0.0001);
});

test('lot identity is kept in the lot table rather than lost', function () {
    slcAdmin();
    $warehouse = slcWarehouse();
    $product = slcProduct('fifo');

    slcReceive($product->id, $warehouse->id, 6, 5.00, 'LOT-OLD');
    slcReceive($product->id, $warehouse->id, 6, 7.00, 'LOT-NEW');

    // Dropping the lot from the cache key must not drop the lot. Both are still there, with their
    // own quantities and costs, which is what the Lots & Expiry report and a recall both read.
    expect(slcLot('LOT-OLD'))->not->toBeNull()
        ->and((float) slcLot('LOT-OLD')->qty_received)->toBe(6.0)
        ->and((float) slcLot('LOT-OLD')->qty_remaining)->toBe(6.0)
        ->and((float) slcLot('LOT-NEW')->qty_remaining)->toBe(6.0)
        ->and(FifoLayer::where('product_id', $product->id)->count())->toBe(2)
        ->and((float) slcStock($product->id, $warehouse->id)->qty_on_hand)->toBe(12.0);
});

// ─────────────────────────────────────────────────────────────────────────────
// Half 2: a lot named on the way out is recorded, and honoured
// ─────────────────────────────────────────────────────────────────────────────

test('a lot named on an issue reaches the movement ledger', function () {
    slcAdmin();
    $warehouse = slcWarehouse();
    $product = slcProduct('fifo');

    slcReceive($product->id, $warehouse->id, 10, 5.00, 'LOT-A');

    $move = slcIssue($product->id, $warehouse->id, 2, 5.00, 'LOT-A');

    // This is the assertion StockMovementDomainTest used to make in the negative, as a
    // characterisation test: IssueStockAction built its StockMoveData without a lotNo argument,
    // so a lot typed on a delivery was validated, stored on the delivery item, and then dropped.
    expect($move->lot_no)->toBe('LOT-A', 'IssueStockAction is still dropping the caller lot_no')
        ->and(StockMove::where('direction', 'out')->count())->toBe(1);
});

test('a serial named on an issue reaches the movement ledger too', function () {
    slcAdmin();
    $warehouse = slcWarehouse();
    $product = slcProduct('weighted', 'SLC-SERIAL-1');

    slcReceive($product->id, $warehouse->id, 5, 12.00);

    $move = slcIssue($product->id, $warehouse->id, 1, 12.00, null, 'SN-0001');

    expect($move->serial_no)->toBe('SN-0001', 'DeliveryService passes serial_no and the path drops it');
});

test('a lot named on an issue is consumed before older layers', function () {
    slcAdmin();
    $warehouse = slcWarehouse();
    $product = slcProduct('fifo');

    slcReceive($product->id, $warehouse->id, 6, 5.00, 'LOT-OLD');
    slcReceive($product->id, $warehouse->id, 6, 7.00, 'LOT-NEW');

    $move = slcIssue($product->id, $warehouse->id, 4, 5.00, 'LOT-NEW');

    // Honouring the lot is the difference between a label and a fact: the named lot's quantity goes
    // down, and the issue is costed out of the layer that actually shipped.
    expect((float) slcLot('LOT-NEW')->qty_remaining)->toBe(2.0)
        ->and((float) slcLot('LOT-OLD')->qty_remaining)->toBe(6.0, 'an older layer was consumed for a shipment that named a newer lot')
        ->and((float) $move->unit_cost)->toBe(7.0)
        ->and((float) $move->total_cost)->toBe(28.0)
        ->and((float) slcStock($product->id, $warehouse->id)->qty_on_hand)->toBe(8.0);
});

test('a lot-less issue still consumes layers oldest-first', function () {
    slcAdmin();
    $warehouse = slcWarehouse();
    $product = slcProduct('fifo');

    slcReceive($product->id, $warehouse->id, 6, 5.00, 'LOT-OLD');
    slcReceive($product->id, $warehouse->id, 6, 7.00, 'LOT-NEW');

    $move = slcIssue($product->id, $warehouse->id, 4, 5.00);

    // The regression guard on the other side: every caller in the tree today issues without a lot,
    // so honouring a named lot must not disturb FIFO order when none is named.
    expect((float) slcLot('LOT-OLD')->qty_remaining)->toBe(2.0)
        ->and((float) slcLot('LOT-NEW')->qty_remaining)->toBe(6.0)
        ->and((float) $move->unit_cost)->toBe(5.0)
        ->and((float) $move->total_cost)->toBe(20.0);
});

test('a named lot short of the request falls back to FIFO for the remainder', function () {
    slcAdmin();
    $warehouse = slcWarehouse();
    $product = slcProduct('fifo');

    slcReceive($product->id, $warehouse->id, 6, 5.00, 'LOT-OLD');
    slcReceive($product->id, $warehouse->id, 6, 7.00, 'LOT-NEW');

    // Eight units against a lot holding six: the named lot empties, the oldest layer covers the rest.
    $move = slcIssue($product->id, $warehouse->id, 8, 5.00, 'LOT-NEW');

    expect((float) slcLot('LOT-NEW')->qty_remaining)->toBe(0.0)
        ->and((float) slcLot('LOT-OLD')->qty_remaining)->toBe(4.0)
        ->and((float) $move->unit_cost)->toBe(6.5, 'weighted: (6 x 7.00 + 2 x 5.00) / 8')
        ->and((float) $move->total_cost)->toBe(52.0);
});

test('a lot named on an issue that does not exist is still refused cleanly', function () {
    slcAdmin();
    $warehouse = slcWarehouse();
    $product = slcProduct('fifo');

    slcReceive($product->id, $warehouse->id, 6, 5.00, 'LOT-OLD');

    // Naming a lot that was never received must not silently ship something else: there are 6 units
    // of a different lot on hand, and the named lot accounts for none of them.
    expect(fn () => slcIssue($product->id, $warehouse->id, 2, 5.00, 'LOT-GHOST'))
        ->toThrow(DomainException::class, 'LOT-GHOST');

    // And the refused movement left nothing behind — the ledger row move() writes before it
    // discovers the shortfall is rolled back with the transaction.
    expect(StockMove::where('direction', 'out')->count())->toBe(0)
        ->and((float) slcLot('LOT-OLD')->qty_remaining)->toBe(6.0)
        ->and((float) slcStock($product->id, $warehouse->id)->qty_on_hand)->toBe(6.0);
});

test('a sales return restocks against the lot it names', function () {
    slcAdmin();
    $warehouse = slcWarehouse();
    $product = slcProduct('fifo');

    slcReceive($product->id, $warehouse->id, 10, 5.00, 'LOT-A');
    slcIssue($product->id, $warehouse->id, 4, 5.00, 'LOT-A');

    // SalesReturnService hands restock() an array carrying lot_no, exactly as DeliveryService does
    // for deductStock(); both were dropped before item 41.
    app(InventoryStockProvider::class)->restock([
        ['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'qty' => 2, 'unit_cost' => 5.00, 'lot_no' => 'LOT-A'],
    ], 'SLC-RET-1', 'sales_return');

    $move = StockMove::where('reference_no', 'SLC-RET-1')->first();

    expect($move)->not->toBeNull()
        ->and($move->lot_no)->toBe('LOT-A', 'restock() is still dropping the caller lot_no')
        ->and((float) slcStock($product->id, $warehouse->id)->qty_on_hand)->toBe(8.0);
});

test('a batch-tracked receipt keeps its generated lot out of the cache key', function () {
    slcAdmin();
    $warehouse = slcWarehouse();
    $product = slcProduct('weighted', 'SLC-BATCH-1', batchTracked: true);

    $move = slcReceive($product->id, $warehouse->id, 5, 4.00);

    // move() generates a lot for a batch-tracked receipt that names none, and writes it to the move
    // and the layer. The cache row stays aggregate, which is why a lot-less issue can still sell it.
    // (That a batch-tracked *weighted* product never decrements Lot.qty_remaining on the way out is
    // a separate drift, reported as a finding rather than fixed here.)
    expect($move->lot_no)->toStartWith('LOT-')
        ->and(slcStock($product->id, $warehouse->id)->lot_no)->toBeNull()
        ->and(Stock::where('product_id', $product->id)->count())->toBe(1);

    slcIssue($product->id, $warehouse->id, 2, 4.00);

    expect((float) slcStock($product->id, $warehouse->id)->qty_on_hand)->toBe(3.0);
});

// ─────────────────────────────────────────────────────────────────────────────
// The merge: a deployment's lot-keyed rows are folded in, not stranded
// ─────────────────────────────────────────────────────────────────────────────

test('the merge migration folds lot-keyed cache rows into the aggregate', function () {
    slcAdmin();
    $warehouse = slcWarehouse();
    $product = slcProduct('weighted');

    // Rows of the shape the old key produced: same product and warehouse, one row per lot, plus the
    // lot-less row an issue would have found. Inserted directly because no current code path can
    // create them any more, and a deployment that ran the old code has exactly these.
    $rows = [
        ['lot_no' => null, 'qty_on_hand' => 4.0, 'qty_reserved' => 1.0, 'avg_cost' => 5.00, 'total_value' => 20.00, 'last_movement_at' => '2026-10-01 10:00:00'],
        ['lot_no' => 'LOT-A', 'qty_on_hand' => 10.0, 'qty_reserved' => 2.0, 'avg_cost' => 6.00, 'total_value' => 60.00, 'last_movement_at' => '2026-10-05 10:00:00'],
        ['lot_no' => 'LOT-B', 'qty_on_hand' => 6.0, 'qty_reserved' => 0.0, 'avg_cost' => 8.00, 'total_value' => 48.00, 'last_movement_at' => '2026-10-03 10:00:00'],
    ];

    foreach ($rows as $row) {
        DB::table('inventory_stocks')->insert(array_merge([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'qty_available' => $row['qty_on_hand'] - $row['qty_reserved'],
            'created_at' => now(),
            'updated_at' => now(),
        ], $row));
    }

    expect(Stock::where('product_id', $product->id)->count())->toBe(3);

    // Pure DML on purpose, so it can be run again here against rows this test invented. A merge that
    // cannot be exercised is a merge nobody can trust on the one deployment that needs it.
    $migration = require base_path('modules/INVENTORY/Database/Migrations/2026_11_07_000003_merge_lot_keyed_stock_cache_rows.php');
    $migration->up();

    $merged = Stock::where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->get();

    expect($merged)->toHaveCount(1, 'the lot-keyed rows were not folded into the aggregate')
        ->and($merged->first()->lot_no)->toBeNull()
        ->and((float) $merged->first()->qty_on_hand)->toBe(20.0)
        ->and((float) $merged->first()->qty_reserved)->toBe(3.0)
        ->and((float) $merged->first()->qty_available)->toBe(17.0, 'available must stay on_hand - reserved after a merge')
        ->and((float) $merged->first()->total_value)->toBe(128.00)
        // One rule for both valuation methods: total_value / qty_on_hand. For a weighted row that is
        // the quantity-weighted average of the rows it replaced; for a FIFO row it is the definition
        // move() itself maintains.
        ->and((float) $merged->first()->avg_cost)->toBe(6.4)
        ->and($merged->first()->last_movement_at->toDateTimeString())->toBe('2026-10-05 10:00:00', 'the newest movement time was lost');

    // Running it again changes nothing, which is what makes it safe to deploy twice.
    $migration->up();

    expect(Stock::where('product_id', $product->id)->count())->toBe(1)
        ->and((float) Stock::where('product_id', $product->id)->first()->qty_on_hand)->toBe(20.0);
});

test('the merge leaves an aggregate that was never lot-keyed alone', function () {
    slcAdmin();
    $warehouse = slcWarehouse();
    $product = slcProduct('weighted');

    slcReceive($product->id, $warehouse->id, 7, 5.00);

    $before = slcStock($product->id, $warehouse->id);
    $onHand = (float) $before->qty_on_hand;
    $avgCost = (float) $before->avg_cost;

    $migration = require base_path('modules/INVENTORY/Database/Migrations/2026_11_07_000003_merge_lot_keyed_stock_cache_rows.php');
    $migration->up();

    $after = slcStock($product->id, $warehouse->id);

    expect((float) $after->qty_on_hand)->toBe($onHand)
        ->and((float) $after->avg_cost)->toBe($avgCost)
        ->and((int) $after->id)->toBe((int) $before->id, 'a row that needed no merge was rewritten anyway');
});

// ─────────────────────────────────────────────────────────────────────────────
// Adjacent defects found while fixing this one — characterised, not fixed
//
// Each of these is published as a finding in §4 and pinned here in the shape the report uses for a
// defect it is not yet willing to fix: the test asserts what the code *does*, says so in its name and
// its comment, and therefore fails loudly the day somebody fixes it — at which point the assertion is
// inverted deliberately, the way `StockMovementDomainTest`'s lot test was at item 41. None of them is
// skipped, because a skipped test is a defect with the lights off, which is what P1.25 itself was.
// ─────────────────────────────────────────────────────────────────────────────

test('a reservation against stock held in two locations lands on one arbitrary row', function () {
    slcAdmin();
    $warehouse = slcWarehouse();
    $product = slcProduct('weighted', 'SLC-LOC-1');

    $a = Location::create(['warehouse_id' => $warehouse->id, 'name' => 'SLC Aisle A', 'code' => 'SLC-A', 'type' => 'storage', 'is_active' => true]);
    $b = Location::create(['warehouse_id' => $warehouse->id, 'name' => 'SLC Aisle B', 'code' => 'SLC-B', 'type' => 'storage', 'is_active' => true]);

    app(ReceiveStockAction::class)->execute([
        ['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'location_id' => $a->id, 'qty' => 10, 'unit_cost' => 5.00],
    ], 'opening', 'SLC-LOC-A');
    app(ReceiveStockAction::class)->execute([
        ['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'location_id' => $b->id, 'qty' => 10, 'unit_cost' => 5.00],
    ], 'opening', 'SLC-LOC-B');

    // Finding 47. `move()` keys the cache on location_id as well, so two rows exist; `reserve()` and
    // `release()` key on (product, variant, warehouse) only and take `->first()`, with no ORDER BY, so
    // one row absorbs the whole reservation and the other keeps advertising everything it holds.
    // Not reachable today — no caller passes a location, and no screen captures one — which is why it
    // is reported rather than fixed: making reservations location-aware is a second data-model
    // decision, about whether a reservation belongs to a bin or to the warehouse.
    expect(Stock::where('product_id', $product->id)->count())->toBe(2);

    app(StockService::class)->reserve($product->id, null, $warehouse->id, 5);

    $rows = Stock::where('product_id', $product->id)->orderBy('id')->get();

    expect($rows->filter(fn ($r) => (float) $r->qty_reserved > 0)->count())->toBe(1, 'the reservation was spread across locations after all')
        ->and((float) $rows->sum('qty_on_hand'))->toBe(20.0)
        ->and((float) $rows->sum('qty_reserved'))->toBe(5.0)
        // 15 available against 20 on hand, while one of the two rows still claims all 10 of its units.
        ->and((float) $rows->sum('qty_available'))->toBe(15.0)
        ->and((float) $rows[1]->qty_available)->toBe(10.0, 'both rows answered the reservation');
});

test('a batch-tracked weighted product never gives lot quantity back on the way out', function () {
    slcAdmin();
    $warehouse = slcWarehouse();
    $product = slcProduct('weighted', 'SLC-BATCH-2', batchTracked: true);

    $received = slcReceive($product->id, $warehouse->id, 5, 4.00);
    $lot = slcLot($received->lot_no);

    expect($lot)->not->toBeNull()
        ->and((float) $lot->qty_remaining)->toBe(5.0);

    slcIssue($product->id, $warehouse->id, 2, 4.00);

    // Finding 48. The cache is right — 3 on hand — but `move()` consumes layers only inside
    // `if ($sign < 0 && $isFifo)`, so a batch-tracked product valued at weighted average keeps every
    // unit it ever received as "remaining" forever. `Lot` and `FifoLayer` are what the Lots & Expiry
    // report reads and what a recall would be answered from, so the quantity that shipped is missing
    // from both. Reported rather than fixed: closing it means deciding whether a lot-less issue should
    // consume lots oldest-first for products that are not valued by FIFO, which is the lot-consumption
    // engine this item deliberately did not build.
    expect((float) slcStock($product->id, $warehouse->id)->qty_on_hand)->toBe(3.0)
        ->and((float) slcLot($received->lot_no)->qty_remaining)->toBe(5.0, 'lot quantity was given back on the way out')
        ->and((float) FifoLayer::where('lot_no', $received->lot_no)->first()->qty_remaining)->toBe(5.0);
});

test('a lot number reused by a second product is attributed to the first', function () {
    slcAdmin();
    $warehouse = slcWarehouse();
    $first = slcProduct('fifo', 'SLC-SHARED-A');
    $second = slcProduct('fifo', 'SLC-SHARED-B');

    slcReceive($first->id, $warehouse->id, 6, 5.00, 'LOT-SHARED');
    slcReceive($second->id, $warehouse->id, 4, 9.00, 'LOT-SHARED');

    // Finding 49. `inventory_lots.lot_no` is unique on its own — not per product — and `move()` creates
    // the lot with `Lot::firstOrCreate(['lot_no' => $lotNo], ['product_id' => …])`, so the second
    // product's receipt finds the first product's lot and increments *that*: one row, attributed to the
    // wrong product, holding both quantities at the first product's cost. Two suppliers using the same
    // lot stamp for different goods is ordinary, so this is reachable the moment lots are used.
    $lots = Lot::where('lot_no', 'LOT-SHARED')->get();

    expect($lots)->toHaveCount(1, 'lot_no is unique per product after all')
        ->and((int) $lots->first()->product_id)->toBe((int) $first->id, 'the second product got its own lot row')
        ->and((float) $lots->first()->qty_received)->toBe(10.0)
        ->and((float) $lots->first()->unit_cost)->toBe(5.0, 'the second receipt re-costed the first product lot')
        // Both cache rows are still correct, which is the point: the aggregate does not depend on the
        // lot table, so this defect is confined to lot reporting rather than to stock quantities.
        ->and((float) slcStock($first->id, $warehouse->id)->qty_on_hand)->toBe(6.0)
        ->and((float) slcStock($second->id, $warehouse->id)->qty_on_hand)->toBe(4.0);
});
