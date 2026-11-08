<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POS day-end posting — report item 39.
 *
 * Before this, `PosService::sell()` created a `sales_invoices` row for every counter sale and posted
 * it immediately, so a 200-sale day wrote 200 invoices and 200 journals, and `closeSession()` — the
 * one event that actually counts the drawer — wrote nothing to the ledger at all. Two consequences
 * beyond the noise: `JournalService::syncPayableReceivable()` books `total_debit` as an open
 * receivable for any `SV` journal carrying a customer, so every instant-cash counter sale landed in
 * AR aging as money owed; and the day's cash, bank and credit takings could not be reconciled to a
 * single posting because there was no such posting.
 *
 * The model this migration makes possible is the standard Z-report: the sale is recorded and stock
 * leaves in real time, and the accounting happens once, when the session closes — one balanced
 * journal per session carrying cash, bank, receivables, revenue, VAT and COGS.
 *
 * Schema only. No row is written here: content belongs in seeders, which is item 38's rule and is
 * enforced by `tests/Feature/CORE/MigrationDataSeparationTest.php`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_sessions', function (Blueprint $t): void {
            if (! Schema::hasColumn('pos_sessions', 'journal_id')) {
                // The single day-end journal for everything sold in this session.
                $t->foreignId('journal_id')->nullable()->after('transactions_count')
                    ->constrained('acc_journals')->nullOnDelete();
                $t->timestamp('posted_at')->nullable()->after('journal_id');
            }
            if (! Schema::hasColumn('pos_sessions', 'net_sales_total')) {
                // What the close actually posted, kept on the session so the drawer can be
                // reconciled without re-deriving it from the sales, and so a report can show the
                // posting and the counted cash side by side.
                $t->decimal('discount_total', 15, 2)->default(0)->after('posted_at');
                $t->decimal('net_sales_total', 15, 2)->default(0)->after('discount_total');
                $t->decimal('vat_total', 15, 2)->default(0)->after('net_sales_total');
                $t->decimal('cash_total', 15, 2)->default(0)->after('vat_total');
                $t->decimal('bank_total', 15, 2)->default(0)->after('cash_total');
                $t->decimal('due_total', 15, 2)->default(0)->after('bank_total');
                $t->decimal('collected_total', 15, 2)->default(0)->after('due_total');
                $t->decimal('cogs_total', 15, 2)->default(0)->after('collected_total');
                $t->unsignedInteger('void_count')->default(0)->after('cogs_total');
            }
        });

        Schema::table('pos_sales', function (Blueprint $t): void {
            if (! Schema::hasColumn('pos_sales', 'due_amount')) {
                // A counter sale taken on credit. Collected later, at the till, into whichever
                // session is open — which is what makes the day's collection user-wise.
                $t->decimal('due_amount', 15, 2)->default(0)->after('paid_amount');
                $t->decimal('collected_amount', 15, 2)->default(0)->after('due_amount');
            }
            if (! Schema::hasColumn('pos_sales', 'cost_total')) {
                // FIFO / weighted-average cost snapshotted when the stock leaves, before the layers
                // move. The day-end COGS leg is the sum of these; re-deriving it at close would
                // value the day at whatever the cost happens to be then.
                $t->decimal('cost_total', 15, 2)->default(0)->after('bank_amount');
            }
        });

        // `payment_method` is a CHECK-constrained enum and 'due' is not in it. Guarded because the
        // column is rebuilt, and only worth rebuilding if the value is genuinely missing.
        if (Schema::hasTable('pos_sales') && ! $this->methodAllowsDue()) {
            Schema::table('pos_sales', function (Blueprint $t): void {
                $t->enum('payment_method', ['cash', 'bank', 'bkash', 'card', 'split', 'due'])
                    ->default('cash')
                    ->change();
            });
        }

        if (! Schema::hasTable('pos_collections')) {
            Schema::create('pos_collections', function (Blueprint $t): void {
                $t->id();
                $t->string('collection_no')->unique(); // POS-COL-00001
                // The session whose drawer took the money. A collection always belongs to an open
                // session, so the day-end journal that picks it up is unambiguous.
                $t->foreignId('session_id')->constrained('pos_sessions')->cascadeOnDelete();
                // The credit sale being settled, when it is one of ours. Null for a payment against
                // an invoice raised outside the till.
                $t->foreignId('pos_sale_id')->nullable()->constrained('pos_sales')->nullOnDelete();
                $t->foreignId('partner_id')->nullable()->constrained('partners')->nullOnDelete();
                $t->foreignId('receivable_id')->nullable()->constrained('acc_receivables')->nullOnDelete();
                $t->decimal('amount', 15, 2);
                $t->enum('method', ['cash', 'bank', 'bkash', 'card'])->default('cash');
                $t->foreignId('bank_id')->nullable()->constrained('acc_banks')->nullOnDelete();
                $t->foreignId('collected_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('collected_at')->useCurrent();
                $t->text('notes')->nullable();
                $t->timestamps();
                $t->index(['session_id', 'collected_at']);
                $t->index('partner_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_collections');

        Schema::table('pos_sales', function (Blueprint $t): void {
            foreach (['due_amount', 'collected_amount', 'cost_total'] as $column) {
                if (Schema::hasColumn('pos_sales', $column)) {
                    $t->dropColumn($column);
                }
            }
        });

        Schema::table('pos_sessions', function (Blueprint $t): void {
            if (Schema::hasColumn('pos_sessions', 'journal_id')) {
                $t->dropConstrainedForeignId('journal_id');
            }
            foreach ([
                'posted_at', 'discount_total', 'net_sales_total', 'vat_total', 'cash_total',
                'bank_total', 'due_total', 'collected_total', 'cogs_total', 'void_count',
            ] as $column) {
                if (Schema::hasColumn('pos_sessions', $column)) {
                    $t->dropColumn($column);
                }
            }
        });
    }

    /**
     * Does the stored CHECK constraint already permit 'due'?
     *
     * Read from the database rather than assumed, because on SQLite an enum is a varchar with a
     * CHECK and re-running the change is a full table rebuild for nothing.
     */
    private function methodAllowsDue(): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $sql = (string) (Schema::getConnection()->selectOne(
                "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'pos_sales'"
            )->sql ?? '');

            return str_contains($sql, "'due'");
        }

        $column = Schema::getConnection()->selectOne(
            "SELECT column_type FROM information_schema.columns
             WHERE table_schema = database() AND table_name = 'pos_sales' AND column_name = 'payment_method'"
        );

        return $column !== null && str_contains((string) $column->column_type, 'due');
    }
};
