<?php

/**
 * Fold lot-keyed stock cache rows into the aggregate — report item 41, P1.25.
 *
 * `inventory_stocks` is a denormalised cache of one fact the ledger already holds: how much of a
 * product is in a warehouse. `StockService::move()` used to key it on `lot_no` as well, while every
 * reader of the table — `reserve()`, `release()`, `ValuationService`, `PosLookupController`,
 * `InventoryStockProvider::available()`, `StockRepository`, `LandedCostService` — looked rows up
 * without one. Item 41 makes the writer agree with the readers: the cache is an aggregate per
 * product/variant/warehouse/location, and lot identity stays in `inventory_lots` and `fifo_layers`,
 * which is where it was already maintained.
 *
 * A deployment that ran the old code can hold rows keyed by lot, and changing the key underneath them
 * would strand every unit on such a row — invisible to a sale, absent from valuation, still counted by
 * anything that summed the table. So the rows are merged rather than deleted, and the merge keeps the
 * invariants `move()` maintains:
 *
 *   qty_on_hand   = the sum of the rows it replaces
 *   qty_reserved  = the sum of the rows it replaces
 *   qty_available = qty_on_hand - qty_reserved
 *   total_value   = the sum of the rows it replaces
 *   avg_cost      = total_value / qty_on_hand
 *
 * That last rule is one rule rather than two because it is already what both valuation methods mean:
 * for a weighted-average row `total_value` is `qty_on_hand * avg_cost`, so the division returns the
 * quantity-weighted average of the rows being merged, and for a FIFO row `avg_cost` is *defined* by
 * `move()` as `total_value / qty_on_hand`. `last_movement_at` takes the newest of the merged rows.
 *
 * This migration is **DML only, with no DDL**, for two reasons. It is listed in
 * `MigrationDataSeparationTest`'s `MDS_UP_ALLOWLIST` because a merge of existing rows cannot be done
 * by a seeder — seeders do not run on a deployed database, and these are exactly the rows a deployed
 * database has. And being pure data, it can be run again: `StockLotCacheTest` inserts rows of the old
 * shape and calls `up()` directly, twice, which is the only way a merge like this can be exercised at
 * all once the code that produced those rows is gone.
 *
 * `down()` is deliberately empty. The per-lot split of a cache row is not recoverable from the cache —
 * it was never the record of it. `stock_moves`, `inventory_lots` and `fifo_layers` all keep lot
 * identity and are untouched here, so rolling the application back does not lose the data; it only
 * leaves the cache aggregated, which the old code reads as one lot-less row per product and warehouse
 * and therefore reads correctly.
 *
 * The `lot_no` column stays. `StockController` already sends it to the stock screen, and after this
 * migration it reads NULL for every row, which is the truth: the cache does not track lots.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $lotKeyed = DB::table('inventory_stocks')->whereNotNull('lot_no')->orderBy('id')->get();

        foreach ($lotKeyed as $row) {
            $aggregate = $this->aggregateRowFor($row);

            if ($aggregate === null) {
                // Nothing to merge into: this row becomes the aggregate. Everything keyed the same
                // way after it merges into this one.
                DB::table('inventory_stocks')->where('id', $row->id)->update(['lot_no' => null]);

                continue;
            }

            $onHand = (float) $aggregate->qty_on_hand + (float) $row->qty_on_hand;
            $reserved = (float) $aggregate->qty_reserved + (float) $row->qty_reserved;
            $totalValue = (float) $aggregate->total_value + (float) $row->total_value;

            DB::table('inventory_stocks')->where('id', $aggregate->id)->update([
                'qty_on_hand' => $onHand,
                'qty_reserved' => $reserved,
                'qty_available' => $onHand - $reserved,
                'total_value' => $totalValue,
                'avg_cost' => $onHand > 0 ? $totalValue / $onHand : (float) $aggregate->avg_cost,
                'last_movement_at' => $this->newest($aggregate->last_movement_at, $row->last_movement_at),
                'updated_at' => now(),
            ]);

            DB::table('inventory_stocks')->where('id', $row->id)->delete();
        }
    }

    public function down(): void
    {
        // Not reversible, and stated rather than left to be discovered: a merged cache row no longer
        // carries the split. Lot identity was never the cache's to keep — it lives in stock_moves,
        // inventory_lots and fifo_layers, none of which this migration touches.
    }

    /** The lot-less row covering the same product, variant, warehouse and location, if there is one. */
    private function aggregateRowFor(object $row): ?object
    {
        return DB::table('inventory_stocks')
            ->where('product_id', $row->product_id)
            ->where('warehouse_id', $row->warehouse_id)
            ->whereNull('lot_no')
            ->where(fn ($q) => $row->variant_id === null
                ? $q->whereNull('variant_id')
                : $q->where('variant_id', $row->variant_id))
            ->where(fn ($q) => $row->location_id === null
                ? $q->whereNull('location_id')
                : $q->where('location_id', $row->location_id))
            ->orderBy('id')
            ->first();
    }

    /**
     * The newer of two nullable timestamps, compared as strings.
     *
     * Both SQLite and MySQL hand back the same `Y-m-d H:i:s` shape for a timestamp column through the
     * query builder, so a string comparison is the portable one; parsing would need a format neither
     * driver guarantees.
     */
    private function newest(?string $a, ?string $b): ?string
    {
        if ($a === null) {
            return $b;
        }

        if ($b === null) {
            return $a;
        }

        return $a > $b ? $a : $b;
    }
};
