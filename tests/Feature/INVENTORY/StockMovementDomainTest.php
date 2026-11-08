<?php

/**
 * P3.1 / §7 item 2 — the INVENTORY stock safety net.
 *
 * `StockService::move()` is the only writer of `inventory_stocks`, and it maintains two records of
 * the same fact in one transaction: an append-only `StockMove` ledger and a denormalised `Stock`
 * cache row. The audit recorded that stock movements had no test coverage at all, which matters more
 * here than anywhere else in the module because the two records can drift — the cache is rewritten
 * from itself (`qty_on_hand + delta`) rather than recomputed from the ledger, so a single wrong write
 * is permanent and every valuation report downstream inherits it.
 *
 * What this file pins:
 *   - the cache and the ledger agree after any sequence of movements;
 *   - weighted-average cost moves on a receipt and deliberately does not move on an issue;
 *   - FIFO consumes layers oldest-first and costs the issue out of the layers it consumed;
 *   - overselling is refused, and refused *cleanly* — `move()` writes the StockMove row before it
 *     discovers the shortfall, so the rollback is what keeps the ledger honest;
 *   - reservations move availability without moving quantity on hand;
 *   - a lot named by a caller reaches the movement ledger on the way out as well as the way in —
 *     which item 41 turned from a characterisation of a defect into a requirement, with the detail in
 *     `StockLotCacheTest`.
 *
 * Deliberate limitation, stated rather than hidden: `move()`, `reserve()` and `release()` all take
 * `lockForUpdate()` on the cache row, and SQLite silently ignores it. True parallel movement
 * therefore cannot be exercised on the test database. What is asserted instead is the invariant the
 * lock exists to protect — that movements against one row accumulate rather than overwrite — which is
 * the behaviour the lock produces on MySQL, where the CI matrix runs. Claiming concurrency coverage
 * here would be claiming something the suite cannot observe.
 *
 * Helpers are prefixed `smd`; Pest loads every Feature file into one process.
 */

use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\CORE\Models\User;
use Modules\INVENTORY\Actions\IssueStockAction;
use Modules\INVENTORY\Actions\ReceiveStockAction;
use Modules\INVENTORY\DTO\StockMoveData;
use Modules\INVENTORY\Models\FifoLayer;
use Modules\INVENTORY\Models\Product;
use Modules\INVENTORY\Models\Stock;
use Modules\INVENTORY\Models\StockMove;
use Modules\INVENTORY\Models\Warehouse;
use Modules\INVENTORY\Services\StockService;
use Modules\INVENTORY\Services\ValuationService;

function smdAdmin(): User
{
    test()->seed(SuperAdminSeeder::class);

    $admin = User::where('email', config('core.super_admin.email', 'admin@example.com'))->first();
    expect($admin)->not->toBeNull('SuperAdminSeeder produced no admin user');

    test()->actingAs($admin);

    return $admin;
}

function smdService(): StockService
{
    return app(StockService::class);
}

function smdWarehouse(bool $allowNegative = false): Warehouse
{
    return Warehouse::create([
        'name' => 'SMD Central Warehouse',
        'code' => 'SMD-WH-1',
        'type' => 'main',   // CHECK constraint: main|sub|transit|virtual
        'is_active' => true,
        'is_default' => true,
        'allow_negative_stock' => $allowNegative,
    ]);
}

function smdProduct(string $valuation = 'weighted', string $sku = 'SMD-PROD-1'): Product
{
    return Product::create([
        'name' => 'SMD Cotton Fabric',
        'sku' => $sku,
        'type' => 'stockable',
        'status' => 'active',
        'valuation_method' => $valuation,
        'cost_price' => 5.00,
        'selling_price' => 9.50,
    ]);
}

function smdMove(
    int $productId,
    int $warehouseId,
    string $direction,
    float $qty,
    float $unitCost = 0.0,
    ?string $lotNo = null,
): StockMove {
    return smdService()->move(new StockMoveData(
        productId: $productId,
        variantId: null,
        warehouseId: $warehouseId,
        locationId: null,
        qty: $qty,
        direction: $direction,
        uomId: null,
        unitCost: $unitCost,
        referenceType: 'domain_test',
        referenceNo: 'SMD-REF',
        lotNo: $lotNo,
    ));
}

/** The single cache row for a product/warehouse, or null when nothing has moved yet. */
function smdStock(int $productId, int $warehouseId): ?Stock
{
    return Stock::where('product_id', $productId)->where('warehouse_id', $warehouseId)->first();
}

// ─────────────────────────────────────────────────────────────────────────────
// The cache and the ledger agree
// ─────────────────────────────────────────────────────────────────────────────

test('receiving stock writes one ledger row and one cache row', function () {
    smdAdmin();
    $warehouse = smdWarehouse();
    $product = smdProduct();

    $move = smdMove($product->id, $warehouse->id, 'in', 10, 5.00);

    expect($move->move_no)->toStartWith('SM-')
        ->and($move->direction)->toBe('in')
        ->and((float) $move->qty)->toBe(10.0)
        ->and((float) $move->total_cost)->toBe(50.0)
        ->and($move->moved_by)->not->toBeNull();

    $stock = smdStock($product->id, $warehouse->id);

    expect($stock)->not->toBeNull()
        ->and((float) $stock->qty_on_hand)->toBe(10.0)
        ->and((float) $stock->qty_available)->toBe(10.0)
        ->and((float) $stock->qty_reserved)->toBe(0.0)
        ->and((float) $stock->avg_cost)->toBe(5.0)
        ->and((float) $stock->total_value)->toBe(50.0)
        ->and($stock->last_movement_at)->not->toBeNull();
});

test('the cache reconciles with the ledger after a sequence of movements', function () {
    smdAdmin();
    $warehouse = smdWarehouse();
    $product = smdProduct();

    smdMove($product->id, $warehouse->id, 'in', 10, 5.00);
    smdMove($product->id, $warehouse->id, 'out', 3, 5.00);
    smdMove($product->id, $warehouse->id, 'in', 5, 5.00);
    smdMove($product->id, $warehouse->id, 'out', 2, 5.00);

    $ledger = StockMove::where('product_id', $product->id)->get();

    expect($ledger)->toHaveCount(4, 'the move ledger is append-only; a movement must never rewrite one');

    $in = (float) $ledger->where('direction', 'in')->sum('qty');
    $out = (float) $ledger->where('direction', 'out')->sum('qty');

    // The cache is rewritten from itself rather than recomputed from the ledger, so this equality is
    // the only thing standing between a wrong write and a permanently wrong stock figure.
    expect($in - $out)->toBe(10.0)
        ->and((float) smdStock($product->id, $warehouse->id)->qty_on_hand)->toBe(10.0);

    // And the nullable keys in the cache lookup must keep matching: variant_id, location_id and
    // lot_no are all null here, so a `= null` comparison instead of IS NULL would have created a
    // second cache row on the second movement instead of updating the first.
    expect(Stock::where('product_id', $product->id)->count())->toBe(1);
});

// ─────────────────────────────────────────────────────────────────────────────
// Weighted average
// ─────────────────────────────────────────────────────────────────────────────

test('an issue leaves the weighted average cost alone', function () {
    smdAdmin();
    $warehouse = smdWarehouse();
    $product = smdProduct();

    smdMove($product->id, $warehouse->id, 'in', 10, 5.00);
    smdMove($product->id, $warehouse->id, 'out', 4, 5.00);

    $stock = smdStock($product->id, $warehouse->id);

    // Selling stock does not change what the remaining stock cost.
    expect((float) $stock->qty_on_hand)->toBe(6.0)
        ->and((float) $stock->avg_cost)->toBe(5.0)
        ->and((float) $stock->total_value)->toBe(30.0);
});

test('a receipt at a different price re-weights the average', function () {
    smdAdmin();
    $warehouse = smdWarehouse();
    $product = smdProduct();

    smdMove($product->id, $warehouse->id, 'in', 10, 5.00);
    smdMove($product->id, $warehouse->id, 'in', 10, 7.00);

    $stock = smdStock($product->id, $warehouse->id);

    expect((float) $stock->qty_on_hand)->toBe(20.0)
        ->and((float) $stock->avg_cost)->toBe(6.0)
        ->and((float) $stock->total_value)->toBe(120.0);
});

test('a weighted issue is re-weighted correctly by the next receipt', function () {
    smdAdmin();
    $warehouse = smdWarehouse();
    $product = smdProduct();

    smdMove($product->id, $warehouse->id, 'in', 10, 5.00);
    smdMove($product->id, $warehouse->id, 'out', 6, 5.00);
    smdMove($product->id, $warehouse->id, 'in', 6, 8.00);

    $stock = smdStock($product->id, $warehouse->id);

    // 4 left at 5.00 plus 6 at 8.00 = 68.00 over 10 units.
    expect((float) $stock->qty_on_hand)->toBe(10.0)
        ->and((float) $stock->avg_cost)->toBe(6.8)
        ->and((float) $stock->total_value)->toBe(68.0);
});

// ─────────────────────────────────────────────────────────────────────────────
// Overselling
// ─────────────────────────────────────────────────────────────────────────────

test('overselling is refused and leaves no trace in the ledger', function () {
    smdAdmin();
    $warehouse = smdWarehouse();
    $product = smdProduct();

    smdMove($product->id, $warehouse->id, 'in', 5, 5.00);

    // move() creates the StockMove row before it discovers the shortfall, so the rollback is what
    // keeps a refused movement out of the ledger. Without it every rejected sale would leave a
    // phantom issue behind and the reconciliation above would drift.
    expect(fn () => smdMove($product->id, $warehouse->id, 'out', 6, 5.00))
        ->toThrow(DomainException::class, 'Insufficient stock');

    expect(StockMove::where('product_id', $product->id)->count())->toBe(1)
        ->and((float) smdStock($product->id, $warehouse->id)->qty_on_hand)->toBe(5.0);
});

test('issuing from a warehouse that has never held the product is refused', function () {
    smdAdmin();
    $warehouse = smdWarehouse();
    $product = smdProduct();

    expect(fn () => smdMove($product->id, $warehouse->id, 'out', 1, 5.00))
        ->toThrow(DomainException::class, 'On-hand: 0');

    expect(Stock::where('product_id', $product->id)->count())->toBe(0)
        ->and(StockMove::count())->toBe(0);
});

test('a warehouse that allows negative stock permits the issue', function () {
    smdAdmin();
    $warehouse = smdWarehouse(allowNegative: true);
    $product = smdProduct();

    smdMove($product->id, $warehouse->id, 'in', 5, 5.00);
    smdMove($product->id, $warehouse->id, 'out', 8, 5.00);

    expect((float) smdStock($product->id, $warehouse->id)->qty_on_hand)->toBe(-3.0);
});

test('the configuration switch allows negative stock everywhere', function () {
    smdAdmin();
    config(['inventory.valuation.allow_negative_stock' => true]);

    $warehouse = smdWarehouse();
    $product = smdProduct();

    smdMove($product->id, $warehouse->id, 'out', 4, 5.00);

    expect((float) smdStock($product->id, $warehouse->id)->qty_on_hand)->toBe(-4.0);
});

// ─────────────────────────────────────────────────────────────────────────────
// FIFO
// ─────────────────────────────────────────────────────────────────────────────

test('fifo consumes the oldest layer first and costs the issue out of the layers', function () {
    smdAdmin();
    $warehouse = smdWarehouse();
    $product = smdProduct(valuation: 'fifo', sku: 'SMD-FIFO-1');

    smdMove($product->id, $warehouse->id, 'in', 10, 5.00);
    smdMove($product->id, $warehouse->id, 'in', 10, 8.00);

    expect(FifoLayer::where('product_id', $product->id)->count())->toBe(2);

    $move = smdMove($product->id, $warehouse->id, 'out', 12, 0.0);

    // 10 units at 5.00 from the older layer, then 2 at 8.00 from the newer one = 66.00 over 12.
    expect((float) $move->total_cost)->toBe(66.0)
        ->and((float) $move->unit_cost)->toBe(5.5);

    $layers = FifoLayer::where('product_id', $product->id)->orderBy('id')->get();

    expect((float) $layers[0]->qty_remaining)->toBe(0.0, 'the oldest layer must be consumed first')
        ->and((float) $layers[1]->qty_remaining)->toBe(8.0)
        ->and((float) smdStock($product->id, $warehouse->id)->qty_on_hand)->toBe(8.0)
        ->and((float) smdStock($product->id, $warehouse->id)->total_value)->toBe(64.0);
});

test('fifo refuses an issue larger than the remaining layers', function () {
    smdAdmin();
    $warehouse = smdWarehouse();
    $product = smdProduct(valuation: 'fifo', sku: 'SMD-FIFO-2');

    smdMove($product->id, $warehouse->id, 'in', 5, 5.00);

    expect(fn () => smdMove($product->id, $warehouse->id, 'out', 6, 0.0))
        ->toThrow(DomainException::class, 'FIFO insufficient stock');

    expect(StockMove::where('direction', 'out')->count())->toBe(0)
        ->and((float) FifoLayer::where('product_id', $product->id)->first()->qty_remaining)->toBe(5.0);
});

// ─────────────────────────────────────────────────────────────────────────────
// Reservations
// ─────────────────────────────────────────────────────────────────────────────

test('reserving and releasing moves availability without moving quantity on hand', function () {
    smdAdmin();
    $warehouse = smdWarehouse();
    $product = smdProduct();

    smdMove($product->id, $warehouse->id, 'in', 10, 5.00);

    smdService()->reserve($product->id, null, $warehouse->id, 4);

    $stock = smdStock($product->id, $warehouse->id);

    expect((float) $stock->qty_on_hand)->toBe(10.0)
        ->and((float) $stock->qty_reserved)->toBe(4.0)
        ->and((float) $stock->qty_available)->toBe(6.0);

    smdService()->release($product->id, null, $warehouse->id, 4);

    $stock = smdStock($product->id, $warehouse->id)->fresh();

    expect((float) $stock->qty_reserved)->toBe(0.0)
        ->and((float) $stock->qty_available)->toBe(10.0);
});

test('reserving more than is available is refused', function () {
    smdAdmin();
    $warehouse = smdWarehouse();
    $product = smdProduct();

    smdMove($product->id, $warehouse->id, 'in', 10, 5.00);

    expect(fn () => smdService()->reserve($product->id, null, $warehouse->id, 11))
        ->toThrow(DomainException::class, 'Insufficient available stock');

    $stock = smdStock($product->id, $warehouse->id);

    expect((float) $stock->qty_reserved)->toBe(0.0)
        ->and((float) $stock->qty_available)->toBe(10.0);
});

test('releasing more than was reserved cannot drive reservations negative', function () {
    smdAdmin();
    $warehouse = smdWarehouse();
    $product = smdProduct();

    smdMove($product->id, $warehouse->id, 'in', 10, 5.00);
    smdService()->reserve($product->id, null, $warehouse->id, 3);

    smdService()->release($product->id, null, $warehouse->id, 9);

    $stock = smdStock($product->id, $warehouse->id)->fresh();

    expect((float) $stock->qty_reserved)->toBe(0.0)
        ->and((float) $stock->qty_available)->toBe(10.0);
});

// ─────────────────────────────────────────────────────────────────────────────
// Valuation snapshots
// ─────────────────────────────────────────────────────────────────────────────

test('the valuation snapshot agrees with the cache rows', function () {
    smdAdmin();
    $warehouse = smdWarehouse();
    $product = smdProduct();

    smdMove($product->id, $warehouse->id, 'in', 10, 5.00);
    smdMove($product->id, $warehouse->id, 'in', 10, 7.00);

    $valuation = app(ValuationService::class)->getValuation($product->id, $warehouse->id);

    expect($valuation)->not->toBeNull()
        ->and((float) $valuation->qtyOnHand)->toBe(20.0)
        ->and((float) $valuation->totalValue)->toBe(120.0)
        ->and((float) $valuation->avgCost)->toBe(6.0)
        ->and(app(ValuationService::class)->getUnitCost($product->id, null, $warehouse->id))->toBe(6.0);
});

test('an issue records the lot the caller supplies', function () {
    smdAdmin();
    $warehouse = smdWarehouse();
    $product = smdProduct();

    smdMove($product->id, $warehouse->id, 'in', 10, 5.00);

    // This was a characterisation test, and item 41 inverted it. It used to assert that the lot was
    // NULL, because IssueStockAction, InventoryStockProvider::deductStock() and ::restock() all built
    // their StockMoveData without a lotNo argument — so a lot that StoreDeliveryRequest validated and
    // the delivery item stored never reached the movement ledger. It pinned that on purpose, to make
    // the coupling visible: forwarding the lot without also fixing the cache key would have turned a
    // lost lot number into a refused sale, which is what the next test demonstrated.
    //
    // Both halves are fixed together, so the assertion flips and the test below runs. The detail —
    // honouring a named lot ahead of older layers, refusing a lot that holds nothing, and merging the
    // lot-keyed cache rows a deployment may already have — lives in StockLotCacheTest.
    app(IssueStockAction::class)->execute([
        ['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'qty' => 2, 'unit_cost' => 5.00, 'lot_no' => 'LOT-FROM-DELIVERY'],
    ], 'sale', 'SMD-DEL-1');

    $move = StockMove::where('direction', 'out')->first();

    expect($move)->not->toBeNull()
        ->and($move->lot_no)->toBe('LOT-FROM-DELIVERY', 'IssueStockAction is dropping the caller lot_no again')
        ->and((float) smdStock($product->id, $warehouse->id)->qty_on_hand)->toBe(8.0);
});

test('a lot received on a goods receipt is still sellable without naming the lot', function () {
    smdAdmin();
    $warehouse = smdWarehouse();
    $product = smdProduct();

    // This was the skipped reproduction of P1.25, and it runs now. ReceiveStockAction was the one
    // caller that forwarded lot_no, and move() put it into the cache key, while every issue path
    // looked the cache up with lot_no IS NULL — so quantity received against a lot was invisible to a
    // sale and the sale was refused with "On-hand: 0". It was skipped rather than deleted because the
    // fix was a data-model decision, not a patch: whether inventory_stocks is lot-keyed at all, and
    // what happens to the lot-keyed rows a deployment already has.
    //
    // Item 41 decided it. The cache is an aggregate per product/variant/warehouse/location — which is
    // what every reader of the table already assumed, reserve() and release() included — lot identity
    // stays in inventory_lots and fifo_layers, and a migration merges any lot-keyed row into the
    // aggregate instead of stranding it.
    app(ReceiveStockAction::class)->execute([
        ['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'qty' => 10, 'unit_cost' => 5.00, 'lot_no' => 'LOT-RECEIVED'],
    ], 'opening', 'SMD-OPEN-1');

    expect((float) smdStock($product->id, $warehouse->id)->qty_on_hand)->toBe(10.0);

    smdMove($product->id, $warehouse->id, 'out', 4, 5.00);

    expect((float) smdStock($product->id, $warehouse->id)->qty_on_hand)->toBe(6.0);
});

test('a fifo valuation is the sum of the remaining layers, not the cache average', function () {
    smdAdmin();
    $warehouse = smdWarehouse();
    $product = smdProduct(valuation: 'fifo', sku: 'SMD-FIFO-3');

    smdMove($product->id, $warehouse->id, 'in', 10, 5.00);
    smdMove($product->id, $warehouse->id, 'in', 10, 8.00);
    smdMove($product->id, $warehouse->id, 'out', 10, 0.0);

    $valuation = app(ValuationService::class)->getValuation($product->id, $warehouse->id);

    // Ten units remain, all from the 8.00 layer.
    expect((float) $valuation->qtyOnHand)->toBe(10.0)
        ->and((float) $valuation->fifoCost)->toBe(80.0)
        ->and((float) $valuation->totalValue)->toBe(80.0);
});
