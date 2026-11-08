<?php

/**
 * Demo data integrity — report P3 / §7 item 7, recorded as item 32.
 *
 * After `migrate:fresh --seed` the database had **167 tables, of which 129 were empty**. Every
 * transactional table in the ERP was bare: no journals, no journal lines, no ledger, no invoices, no
 * bills, no salary sheets, no slips, no attendance — despite 998 employees, 22 partners, 16 products
 * and a full chart of accounts being present. UAT, demos and screenshots therefore ran against empty
 * states everywhere except the employee list.
 *
 * `DemoDataSeeder` fills a coherent slice: one department's month of rostered attendance through to a
 * released salary sheet, its accrual journal and its paid slips, a purchase chain that stocks the
 * warehouse and posts payables, a sales chain that draws stock down and posts receivables, POS
 * sessions, and the inventory documents in between. It is driven through the **services**, not raw
 * inserts, so document numbers come from the repositories and the accounting effects are the real ones.
 *
 * These tests assert two different things, and the distinction matters:
 *
 *  - **Minimum counts** — designed targets, written before the seeder existed. They are the answer to
 *    "129 empty tables", and they are deliberately lower bounds so the dataset can grow without a
 *    rewrite.
 *  - **Invariants** — the properties that make the data *believable*: every journal balances, the
 *    ledger agrees with the journals, the stock cache agrees with the movements, every posted document
 *    carries its accounting reference, the sheet totals are the sum of their slips. A seeder that
 *    inserted plausible-looking rows without these would pass the counts and fail here, which is the
 *    point.
 *
 * Helpers are prefixed `dd` because every Feature file loads into one process.
 */

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Delivered minimum row counts: tables that were empty before item 32 and must not be afterwards.
 * Each bound sits below the measured value on purpose — these are floors to regress against, not a
 * description of the dataset.
 */
const DD_MINIMUMS = [
    // HRM — roster, attendance and leave
    // Measured: 2 shifts, 280 duties, 640 logs, 320 pairs, 323 dailies.
    'roster_shifts' => 2,
    'roster_duties' => 200,
    'attendance_logs' => 600,
    'attendance_pairs' => 250,
    'attendance_dailies' => 250,
    'leave_types' => 5,
    'leave_type_settings' => 1,
    'hrm_holidays' => 3,
    'leave_requests' => 4,
    'leave_request_workflows' => 1,
    'leave_approval_actions' => 1,
    'hrm_leave_balances' => 10,
    // PAYROLL
    'payroll_settings' => 1,
    'tax_configurations' => 1,
    'tax_slabs' => 3,
    'payroll_salary_sheets' => 1,
    'payroll_slips' => 10,
    'payroll_calculation_snapshots' => 1,
    'payroll_cutlists' => 1,
    'payroll_cutlist_items' => 10,
    'payroll_period_exclusions' => 900,
    'payroll_workflow_audits' => 1,
    // One sheet-level accrual plus one per paid slip. The floor follows the slips floor above
    // (1 accrual + 10 slips), not the count a particular seed happens to produce: the sheet is run
    // for real, so its size moves with the roster. Before item 40 this could only ever be 2 — the
    // accrual and the single slip that got through `uniq_sheet_posting` (finding 35).
    'payroll_journal_posts' => 11,
    'pf_policies' => 1,
    'pf_accounts' => 10,
    'pf_ledgers' => 20,
    'payroll_loans' => 1,
    'payroll_loan_approvals' => 2,
    'payroll_loan_installments' => 3,
    'payroll_loan_monthly_batches' => 1,
    'payroll_loan_monthly_allocations' => 1,
    'payroll_bank_advices' => 1,
    'payroll_bank_advice_items' => 10,
    // PURCHASE
    'purchase_orders' => 2,
    'purchase_order_items' => 4,
    'purchase_receipts' => 2,
    'purchase_receipt_items' => 4,
    'purchase_bills' => 2,
    'purchase_bill_items' => 4,
    'purchase_payments' => 1,
    // SALES
    'sales_quotations' => 2,
    'sales_quotation_items' => 2,
    'sales_orders' => 2,
    'sales_order_items' => 4,
    'sales_deliveries' => 1,
    'sales_delivery_items' => 2,
    'sales_invoices' => 3,
    'sales_invoice_items' => 6,
    'sales_payments' => 2,
    'pos_sessions' => 1,
    'pos_sales' => 4,
    'pos_sale_items' => 8,
    // INVENTORY
    'inventory_stock_moves' => 30,
    'inventory_stocks' => 16,
    'inventory_fifo_layers' => 1,
    'inventory_transfers' => 1,
    'inventory_transfer_items' => 2,
    'inventory_adjustments' => 1,
    'inventory_adjustment_items' => 2,
    'inventory_kits' => 1,
    'inventory_kit_items' => 2,
    // ACCOUNTS
    'acc_journals' => 8,
    'acc_journal_lines' => 16,
    'acc_account_ledger' => 16,
    'acc_receivables' => 2,
    'acc_payables' => 2,
    'acc_banks' => 1,
    'acc_bank_statements' => 1,
    'acc_bank_transactions' => 4,
];

/**
 * Tables the demo dataset was designed to fill but does not, yet.
 *
 * This is the executable form of the gap: each entry is asserted to still be empty, so the list
 * cannot silently drift, and promoting a table into DD_MINIMUMS is a deliberate act. Reasons:
 *
 *  - `roster_overtimes` is derived by `RosterOvertimeService::syncFromAttendance('Overtime')`, which
 *    only picks up dailies with `total_overtime_hours > 0.5`. Probed twice, including with two
 *    employees punching out at 19:45: the reconciler recorded no overtime hours against the rostered
 *    09:00-18:00 shift, so there was nothing to derive. Left uncovered rather than forced.
 *  - `payroll_settlements` is a leaver process, and no employee in the cohort leaves.
 *  - `payroll_journal_posts` used to appear here: the per-slip salary-payment journal could not be
 *    posted at all, because `unique(salary_sheet_id, posting_type)` refused the second slip of any
 *    sheet and `PayrollPaymentPostingService` swallowed it into a log entry (finding 35). Fixed by
 *    item 40 — the idempotence key now carries `posting_scope`, so every paid slip posts, and the
 *    table is measured in DD_MINIMUMS like any other.
 *  - The rest have no demo driver yet. Each needs its own service payloads, which is follow-up work
 *    rather than something to fake with raw inserts.
 */
const DD_UNCOVERED = [
    'hrm_employee_movements',
    'hrm_employee_probations',
    'hrm_employee_documents',
    'hrm_employee_assets',
    'hrm_checklist_templates',
    'hrm_checklist_items',
    'informed_requests',
    'roster_overtimes',
    'festival_bonus_policies',
    'gratuity_policies',
    'payroll_settlements',
    'inventory_stock_counts',
    'inventory_count_items',
    'inventory_locations',
    'inventory_product_suppliers',
    'price_lists',
    'price_list_items',
];

/**
 * The base seed only. Through `Artisan::call('db:seed')` rather than the `seed()` test helper,
 * because that helper exists only on the TestCase and these are plain functions — a bare `seed()`
 * call is a fatal "undefined function". Going through db:seed also matters for fidelity: it is the
 * path that wraps seeders in `Model::unguarded()`, which DemoDataSeeder has to undo in order to
 * behave like the running application.
 */
function ddSeedBase(): void
{
    ddRunSeeder(DatabaseSeeder::class);
}

/** The demo dataset, seeded on top of the base. */
function ddDemo(): void
{
    ddRunSeeder(DemoDataSeeder::class);
}

function ddRunSeeder(string $seeder): void
{
    $exit = Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);

    if ($exit !== 0) {
        throw new RuntimeException("db:seed --class=$seeder exited with $exit: ".Artisan::output());
    }
}

/** @return array<string, int> table => row count, for every table in DD_MINIMUMS */
function ddCounts(): array
{
    $counts = [];

    foreach (array_keys(DD_MINIMUMS) as $table) {
        $counts[$table] = DB::table($table)->count();
    }

    return $counts;
}

beforeEach(function () {
    ddSeedBase();
});

test('the demo dataset fills the tables that were empty', function () {
    ddDemo();

    $counts = ddCounts();
    $short = [];

    foreach (DD_MINIMUMS as $table => $minimum) {
        if ($counts[$table] < $minimum) {
            $short[] = "$table has {$counts[$table]}, needs at least $minimum";
        }
    }

    $this->assertSame([], $short, 'the demo dataset is short: '.implode('; ', $short));
});

test('the tables it fills were genuinely empty before', function () {
    // The twin that makes DD_MINIMUMS mean something. It runs against the base seed only
    // (beforeEach seeds DatabaseSeeder; ddDemo() is deliberately NOT called here), so these are the
    // counts a fresh `migrate:fresh --seed` actually leaves. Without it, a seeder that ran before
    // this test could have filled the tables and the assertion above would pass vacuously.
    //
    // An earlier version deleted six tables and re-ran the seeder inside a nested transaction. That
    // cannot work: the seeder's idempotence marker lives in `sales_orders.notes`, and `roster_duties`
    // has a unique (employee_id, roster_date) key, so a partial delete either makes run() a no-op or
    // makes it violate a constraint. Observing the untouched base state is simpler and stronger.
    $baseline = [];

    foreach ([
        'acc_journals', 'acc_journal_lines', 'acc_account_ledger', 'acc_receivables', 'acc_payables',
        'sales_invoices', 'sales_invoice_items', 'sales_payments', 'sales_orders', 'sales_deliveries',
        'purchase_bills', 'purchase_receipts', 'purchase_payments',
        'payroll_salary_sheets', 'payroll_slips', 'payroll_journal_posts', 'pf_accounts', 'pf_policies',
        'payroll_loans', 'payroll_bank_advices', 'payroll_settings', 'tax_configurations',
        'attendance_logs', 'attendance_pairs', 'attendance_dailies', 'roster_duties',
        'leave_requests', 'leave_types', 'hrm_holidays', 'hrm_leave_balances',
        'inventory_transfers', 'inventory_adjustments', 'inventory_kits', 'inventory_stock_moves',
        'pos_sale_items', 'acc_bank_statements', 'acc_bank_transactions',
    ] as $table) {
        $baseline[$table] = DB::table($table)->count();
    }

    // Tables the base seeders already partly fill, with the counts they leave behind. Everything
    // else must be exactly zero: if a base seeder starts writing journals or slips, this test says
    // so instead of quietly crediting the demo dataset for it.
    $expectedBaseline = ['inventory_stock_moves' => 16];

    $wrong = [];

    foreach ($baseline as $table => $count) {
        $expected = $expectedBaseline[$table] ?? 0;

        if ($count !== $expected) {
            $wrong[] = "$table had $count rows after the base seed, expected $expected";
        }
    }

    $this->assertSame(
        [],
        $wrong,
        'the base seed does not leave these tables in the state the demo dataset claims to fill: '.implode('; ', $wrong)
    );

    // And every table the twin checks must be one the demo promises, or the promise is untested.
    $this->assertSame(
        [],
        array_values(array_diff(array_keys($baseline), array_keys(DD_MINIMUMS))),
        'the twin checks tables that DD_MINIMUMS does not promise'
    );
});

test('every journal the demo data creates balances', function () {
    ddDemo();

    $journals = DB::table('acc_journals')->get();

    expect($journals)->not->toBeEmpty();

    $unbalanced = [];

    foreach ($journals as $journal) {
        $lines = DB::table('acc_journal_lines')->where('journal_id', $journal->id)->get();

        $debit = round((float) $lines->sum('debit'), 2);
        $credit = round((float) $lines->sum('credit'), 2);

        if (abs($debit - $credit) > 0.0001) {
            $unbalanced[] = "{$journal->journal_no}: lines debit $debit vs credit $credit";
        }

        // The header totals must agree with the lines, or every report reads a different number.
        if (abs(round((float) $journal->total_debit, 2) - $debit) > 0.0001) {
            $unbalanced[] = "{$journal->journal_no}: header debit {$journal->total_debit} vs lines $debit";
        }

        if ($lines->count() < 2) {
            $unbalanced[] = "{$journal->journal_no}: a journal with {$lines->count()} line(s) cannot be double-entry";
        }
    }

    $this->assertSame([], $unbalanced, implode('; ', $unbalanced));
});

test('the ledger agrees with the journals and the stock cache agrees with the movements', function () {
    ddDemo();

    // Ledger: every posted line is mirrored, and the book balances as a whole.
    $ledgerDebit = round((float) DB::table('acc_account_ledger')->sum('debit'), 2);
    $ledgerCredit = round((float) DB::table('acc_account_ledger')->sum('credit'), 2);

    expect(abs($ledgerDebit - $ledgerCredit))->toBeLessThan(0.0001, "the ledger does not balance: $ledgerDebit debit vs $ledgerCredit credit");

    $postedLineCount = DB::table('acc_journal_lines')
        ->whereIn('journal_id', DB::table('acc_journals')->where('status', 'posted')->pluck('id'))
        ->count();

    expect(DB::table('acc_account_ledger')->count())->toBeGreaterThanOrEqual(
        $postedLineCount,
        'posted journal lines are missing from the ledger, so account balances understate activity'
    );

    // Stock: the cache must equal the movement history, aggregated per product and warehouse.
    // The aggregation used to be a workaround — item 25 recorded that the cache was keyed on lot_no
    // while the issue path passed none, so a lot-level comparison would have failed for a reason that
    // was reported rather than fixed. Item 41 made the cache an aggregate per product/variant/
    // warehouse/location, so this comparison is now simply the right granularity for the cache row,
    // and the reason it is coarser than the key is that the demo holds no per-location stock.
    $moves = DB::table('inventory_stock_moves')
        ->selectRaw('product_id, warehouse_id, SUM(CASE WHEN LOWER(direction) = ? THEN qty ELSE -qty END) AS net', ['in'])
        ->groupBy('product_id', 'warehouse_id')
        ->get()
        ->keyBy(fn ($row) => $row->product_id.':'.$row->warehouse_id);

    $drift = [];

    foreach (DB::table('inventory_stocks')->get() as $stock) {
        $key = $stock->product_id.':'.$stock->warehouse_id;
        $expected = round((float) ($moves[$key]->net ?? 0), 4);
        $actual = round((float) $stock->qty_on_hand, 4);

        if (abs($expected - $actual) > 0.0001) {
            $drift[] = "product {$stock->product_id} in warehouse {$stock->warehouse_id}: cache $actual vs movements $expected";
        }
    }

    $this->assertSame([], array_slice($drift, 0, 10), 'the stock cache has drifted from its movement history: '.implode('; ', array_slice($drift, 0, 10)));
});

test('the payroll chain hangs together', function () {
    ddDemo();

    $sheet = DB::table('payroll_salary_sheets')->first();

    expect($sheet)->not->toBeNull();

    $slips = DB::table('payroll_slips')->where('salary_sheet_id', $sheet->id)->get();

    expect($slips)->not->toBeEmpty();

    foreach ($slips as $slip) {
        // net = gross + benefits - deductions, which is what the slip screen and the accrual both read.
        $expected = round((float) $slip->gross_salary + (float) $slip->total_benefits - (float) $slip->total_deductions, 2);

        expect(abs(round((float) $slip->net_salary, 2) - $expected))->toBeLessThan(0.01, "slip {$slip->id}: net {$slip->net_salary} but gross {$slip->gross_salary} + benefits {$slip->total_benefits} - deductions {$slip->total_deductions} = $expected");
    }

    // The sheet header must be the sum of its slips, or the two screens disagree.
    expect(round((float) $sheet->gross_total, 2))->toBe(round((float) $slips->sum('gross_salary'), 2), 'the sheet gross total is not the sum of its slips')
        ->and(round((float) $sheet->net_total, 2))->toBe(round((float) $slips->sum('net_salary'), 2), 'the sheet net total is not the sum of its slips')
        ->and((int) $sheet->employee_count)->toBe($slips->count(), 'the sheet employee_count disagrees with the number of slips');

    // The accrual must exist, be posted, and balance like any other journal.
    if ($sheet->accrual_journal_id !== null) {
        $journal = DB::table('acc_journals')->where('id', $sheet->accrual_journal_id)->first();

        expect($journal)->not->toBeNull('the sheet points at an accrual journal that does not exist')
            ->and($journal->status)->toBe('posted', 'the salary accrual journal was created but never posted');
    }

    // Provident fund: an account per employee in scope, each with at least one ledger entry.
    expect(DB::table('pf_accounts')->count())->toBeGreaterThan(0)
        ->and(DB::table('pf_ledgers')->count())->toBeGreaterThan(0);

    expect(DB::table('pf_accounts')->whereNotIn('id', DB::table('pf_ledgers')->distinct()->pluck('pf_account_id'))->count())
        ->toBe(0, 'a PF account exists with no ledger entries, so its balance screen shows nothing to explain it');
});

test('every posted document carries its accounting reference', function () {
    ddDemo();

    $missing = [];

    foreach (DB::table('sales_invoices')->where('status', 'posted')->get() as $invoice) {
        if ($invoice->journal_id === null) {
            $missing[] = "invoice {$invoice->invoice_no} is posted with no journal_id";
        } elseif (! DB::table('acc_journals')->where('id', $invoice->journal_id)->exists()) {
            $missing[] = "invoice {$invoice->invoice_no} points at journal {$invoice->journal_id}, which does not exist";
        }
    }

    // A posted invoice that has drawn stock must have moved it.
    $stockMoves = DB::table('inventory_stock_moves')->where('reference_type', 'like', '%invoice%')->count();

    expect($stockMoves)->toBeGreaterThan(0, 'invoices were posted but no stock movement references them');

    $this->assertSame([], $missing, implode('; ', $missing));

    // Receivables and payables must exist on both sides of the demo trading cycle.
    expect(DB::table('acc_receivables')->count())->toBeGreaterThan(0)
        ->and(DB::table('acc_payables')->count())->toBeGreaterThan(0);
});

test('running the demo seeder twice changes nothing', function () {
    ddDemo();

    $before = ddCounts();

    app(DemoDataSeeder::class)->run();

    $after = ddCounts();
    $changed = [];

    foreach ($before as $table => $count) {
        if ($after[$table] !== $count) {
            $changed[] = "$table went from $count to {$after[$table]}";
        }
    }

    $this->assertSame([], $changed, 'the demo seeder is not idempotent, so re-running it double-books the ledger: '.implode('; ', $changed));
});

test('the demo command refuses to run in production without --force', function () {
    ddDemo();

    // config(), never the container's env key: rebinding app('env') flips runningUnitTests() and
    // every subsequent request in the test dies with a 419 and no flashed errors.
    config(['app.env' => 'production']);

    $this->artisan('erp:demo-data')
        ->expectsOutputToContain('refus')
        ->assertExitCode(1);

    expect(DB::table('pos_sessions')->count())->toBe(
        ddCounts()['pos_sessions'],
        'the command wrote demo data into a production-configured database'
    );
});

test('the documented gap is exactly the tables still empty', function () {
    ddDemo();

    // Not a vacuous list check: this pins what the demo does NOT cover, so a future seeder change
    // that fills one of these tables must promote it into DD_MINIMUMS (and a change that stops
    // filling one shows up here rather than in a screenshot).
    $unexpected = [];

    foreach (DD_UNCOVERED as $table) {
        $count = DB::table($table)->count();

        if ($count > 0) {
            $unexpected[] = "$table now has $count rows — promote it into DD_MINIMUMS";
        }
    }

    $overlap = array_intersect(array_keys(DD_MINIMUMS), DD_UNCOVERED);

    $this->assertSame([], $unexpected, implode('; ', $unexpected));
    $this->assertSame([], array_values($overlap), 'a table cannot be both a promised minimum and a documented gap');
});
