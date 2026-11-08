<?php

/**
 * Per-slip idempotence for payroll postings — report item 40, finding 35.
 *
 * `payroll_journal_posts` carried `unique(salary_sheet_id, posting_type)`, added with the comment
 * `// idempotent`. That key is right for `salary_accrual` and `settlement`, which post one row per
 * sheet, and wrong for `salary_payment`, which `PayrollJournalService::postSalaryPayment()` writes
 * once **per slip**. Its own guard looked the slip up through `meta->slip_id`, a JSON path the index
 * knows nothing about, so the second slip of any sheet violated the constraint, the surrounding
 * transaction rolled the journal back with it, and two `catch (\Throwable) { Log::… }` layers meant
 * nothing surfaced. Every slip was marked paid; N−1 of N payments never reached the books.
 *
 * The key therefore gains the slip. Two shapes were considered:
 *
 *  - a nullable `payroll_slip_id` inside the unique index — rejected: SQLite and MySQL both treat
 *    NULLs as distinct, so the sheet-level rows (accruals, settlements) would have lost their
 *    idempotence entirely, trading one defect for a worse one;
 *  - `posting_scope`, a NOT NULL string — taken. `'sheet'` for sheet-level postings, `'slip:<id>'`
 *    for per-slip ones. Nothing is ever NULL, so the index constrains every row, and the scope is
 *    readable in the row itself rather than only in a JSON blob.
 *
 * `payroll_slip_id` is added too, as a real column with a real foreign key: it is what makes a
 * paid-but-unposted slip queryable with a join instead of a JSON lookup, and a JSON path cannot be
 * indexed portably. It is nullable because sheet-level postings have no slip.
 *
 * The backfill of existing `salary_payment` rows is listed in `MigrationDataSeparationTest`'s
 * `MDS_UP_ALLOWLIST`: it copies data the migration itself just made room for, which is a column
 * backfill, not content provisioning. No seeder can do it, because it must run on the rows that
 * already exist in a deployed database before the old index is dropped.
 *
 * `payroll_slips.payment_posting_error` is the other half of the fix. "Never blocks HR" stays true —
 * a payroll officer can still mark a slip paid when the chart of accounts cannot take the journal —
 * but the reason now lands on the slip, so `where('payment_status','paid')->whereNotNull(
 * 'payment_posting_error')` is a work queue instead of a log line nobody reads.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_journal_posts', function (Blueprint $table): void {
            $table->string('posting_scope', 40)->default('sheet')->after('posting_type');
            $table->foreignId('payroll_slip_id')->nullable()->after('posting_scope')
                ->constrained('payroll_slips')->nullOnDelete();
        });

        // Rows written before this migration are sheet-scoped by the default above, except the
        // per-slip payments, which have to be re-keyed before the new index can be built: two slips
        // of one sheet would otherwise collide on ('sheet', 'salary_payment', 'sheet') immediately.
        foreach (DB::table('payroll_journal_posts')->where('posting_type', 'salary_payment')->get(['id', 'meta']) as $row) {
            $meta = json_decode((string) $row->meta, true);
            $slipId = is_array($meta) ? ($meta['slip_id'] ?? null) : null;

            if ($slipId === null) {
                continue;
            }

            DB::table('payroll_journal_posts')->where('id', $row->id)->update([
                'posting_scope' => 'slip:'.$slipId,
                'payroll_slip_id' => $slipId,
            ]);
        }

        Schema::table('payroll_journal_posts', function (Blueprint $table): void {
            $table->dropUnique('uniq_sheet_posting');
            $table->unique(['salary_sheet_id', 'posting_type', 'posting_scope'], 'uniq_sheet_posting_scope');
        });

        Schema::table('payroll_slips', function (Blueprint $table): void {
            $table->text('payment_posting_error')->nullable()->after('payment_journal_id');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_slips', function (Blueprint $table): void {
            $table->dropColumn('payment_posting_error');
        });

        Schema::table('payroll_journal_posts', function (Blueprint $table): void {
            $table->dropUnique('uniq_sheet_posting_scope');
        });

        // Per-slip payment rows cannot survive the narrower key — they were the defect — so they go.
        DB::table('payroll_journal_posts')
            ->where('posting_type', 'salary_payment')
            ->where('posting_scope', '!=', 'sheet')
            ->delete();

        Schema::table('payroll_journal_posts', function (Blueprint $table): void {
            $table->unique(['salary_sheet_id', 'posting_type'], 'uniq_sheet_posting');
            $table->dropConstrainedForeignId('payroll_slip_id');
            $table->dropColumn('posting_scope');
        });
    }
};
