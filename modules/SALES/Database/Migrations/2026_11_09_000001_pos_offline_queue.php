<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F22 — offline POS: the two columns that make a replayed ticket safe.
 *
 * The problem this slice solves is not "queue sales in the browser". Anyone can put a ticket in
 * localStorage. The problem is what happens when the network comes back: the queue is replayed, and
 * a replay is *by definition* a request the till cannot be sure was never answered. A cashier whose
 * connection drops one second after the sale is committed has a phone that says "failed" about a
 * sale the ledger already has. Retrying it without a key writes a second sale, deducts the stock
 * twice, and puts twice the money in the drawer — and the day-end journal would then balance against
 * a drawer count that nobody can explain.
 *
 * So `client_ref` is a UUID the *device* mints when the ticket is queued, and it is the primary key
 * of the sync from the server's side: unique, so a replay of the same ticket is a lookup, not an
 * insert. It is nullable because the overwhelming majority of sales are rung up with the network
 * present and need no such key.
 *
 * `rang_up_at` carries the time the ticket was actually taken at the counter. Without it a sale
 * queued at 23:58 and synced at 00:04 is dated tomorrow, its money lands in the wrong drawer day,
 * and the only trace of that is a `created_at` nobody looks at. With it, the sync can *refuse* the
 * ticket and hand the cashier an explicit choice instead of quietly restating yesterday's takings.
 *
 * Schema only. No row is written here: content belongs in seeders, which is item 38's rule and is
 * enforced by `tests/Feature/CORE/MigrationDataSeparationTest.php`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_sales', function (Blueprint $t): void {
            if (! Schema::hasColumn('pos_sales', 'client_ref')) {
                $t->string('client_ref', 36)->nullable()->after('pos_no')->unique();
            }

            if (! Schema::hasColumn('pos_sales', 'rang_up_at')) {
                $t->timestamp('rang_up_at')->nullable()->after('sale_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_sales', function (Blueprint $t): void {
            // Drop the unique index before the column, or MySQL leaves an orphan behind and the
            // re-run of `up()` fails on a duplicate index name.
            if (Schema::hasColumn('pos_sales', 'client_ref')) {
                $t->dropUnique(['client_ref']);
            }

            foreach (['rang_up_at', 'client_ref'] as $column) {
                if (Schema::hasColumn('pos_sales', $column)) {
                    $t->dropColumn($column);
                }
            }
        });
    }
};
