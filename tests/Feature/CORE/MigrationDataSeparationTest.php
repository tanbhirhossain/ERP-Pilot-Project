<?php

/**
 * Migration/data separation — report §7 item 8, recorded as item 38.
 *
 * P0-B was the general ledger being silently emptied. The chain ran through a *migration*:
 * `2026_11_03_000075_add_grni_to_receipts.php` (a PURCHASE migration) created ACCOUNTS' chart-of-accounts
 * row `2115` in `up()`. That single row made `AccountsDemoSeeder`'s opening guard —
 * `if (Account::exists()) return;` — true, so the seeder that owned the whole chart of accounts never ran,
 * so `accountIdByCode()` returned null for every other code, so every posting path took its null branch and
 * wrote documents marked `posted` with `journal_id = null`. The migration was not wrong to want the account;
 * it was wrong to *create content*, because content created by a migration is invisible to the seeders that
 * own it and can disable them.
 *
 * This file pins the policy that follows from that: **migrations change schema; seeders create content.**
 * It is a scanner, so it is held to the standard this report applies to every scanner — the count it
 * publishes must be a count of defects, not of regex hits, and it must be shown to be able to see one.
 *
 * Two classes of data write are *not* violations, and both are allowlisted by name with a reason, so the
 * allowlist is the thing under review rather than a blind spot:
 *
 *  - a **backfill** in `up()`: populating a column the same migration just added, from rows that already
 *    exist. That is part of making the schema change coherent, not content provisioning.
 *  - a **rollback conversion** in `down()`: undoing data that only exists because of the columns being
 *    dropped, or converting values so a narrowed enum can be restored. `down()` is allowed to delete what
 *    a seeder created; `up()` is not allowed to create it.
 *
 * Helpers are prefixed `mds` because every Feature file loads into one process.
 */

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\ACCOUNTS\Database\Seeders\AccountsDemoSeeder;
use Modules\HRM\Database\Seeders\RosterFallbackShiftSeeder;
use Modules\PURCHASE\Services\GoodsReceiptService;
use Modules\PURCHASE\Services\PurchaseOrderService;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

/**
 * Migrations whose `up()` writes data, with the reason that is allowed.
 * Anything not in this list that writes data in `up()` fails the test.
 */
const MDS_UP_ALLOWLIST = [
    // Adds employees.biometric_id and populates it from device_users for rows that already
    // exist. A column backfill travels with the column; it is not content provisioning.
    '2026_10_28_000002_add_biometric_id_to_employees.php' => 'backfills the biometric_id column it adds, from existing device_users rows',
    // Adds payroll_journal_posts.posting_scope and .payroll_slip_id, then re-keys the per-slip
    // salary_payment rows that already exist from their `meta->slip_id` JSON path into those columns.
    // It has to happen inside this migration and before `uniq_sheet_posting` is replaced: two slips of
    // one sheet would otherwise collide on the new (sheet, type, scope) key the moment it is built, on
    // every database that already ran a payroll. A seeder cannot do it — seeders do not run on a
    // deployed database, and the old rows are exactly the ones that need it.
    '2026_11_07_000002_payroll_payment_posting_scope.php' => 'backfills the posting_scope and payroll_slip_id columns it adds, from the meta JSON those rows already carried',
    // Folds the lot-keyed `inventory_stocks` rows a deployment may already hold into the aggregate
    // row the same key now produces, summing quantities and value rather than stranding them. It is
    // DML-only with no DDL, which is what makes it re-runnable, and `StockLotCacheTest` runs it twice
    // against rows the test inserts — the only way a merge of rows the code can no longer produce can
    // be exercised at all. A seeder cannot do it: seeders do not run on a deployed database, and these
    // are exactly the rows a deployed database has.
    '2026_11_07_000003_merge_lot_keyed_stock_cache_rows.php' => 'merges the lot-keyed stock cache rows that already exist into the aggregate key the same item introduces',
];

/**
 * Migrations whose `down()` writes data, with the reason. Kept visible on purpose: a `down()`
 * that mutates data is legitimate but should never be accidental.
 */
const MDS_DOWN_ALLOWLIST = [
    // Drops is_fallback/buffer_before/buffer_after and removes the shift that only makes sense
    // with them. The row is now created by RosterFallbackShiftSeeder, so down() deletes what a
    // seeder made — allowed, and the reason it is allowed is that the columns are going away.
    '2026_10_30_110000_add_buffer_and_fallback_to_shifts_and_policies.php' => 'removes the fallback shift whose columns it is dropping',
    // Converts Off Day back to Absent before restoring the narrower enum, or the enum change
    // itself would fail on rows it can no longer represent.
    '2026_11_01_000001_add_off_day_status_to_attendance_dailies.php' => 'converts Off Day rows back to Absent before narrowing the status enum',
    // Rolling back restores `unique(salary_sheet_id, posting_type)`, which cannot hold two
    // salary_payment rows for one sheet. The per-slip rows are the thing being un-fixed, so they are
    // deleted before the narrow index is rebuilt; the sheet-level accrual row survives. Kept visible
    // because a down() that deletes rows is exactly the kind that should never be accidental.
    '2026_11_07_000002_payroll_payment_posting_scope.php' => 'deletes the per-slip payment posts that the restored narrower unique index cannot hold',
];

/** Every migration file in the project, from both locations. */
function mdsMigrationFiles(): array
{
    $files = array_merge(
        glob(base_path('modules/*/Database/Migrations/*.php')) ?: [],
        glob(base_path('database/migrations/*.php')) ?: [],
    );

    sort($files);

    return $files;
}

/** The file's PHP with every comment removed, so prose cannot be counted as code. */
function mdsCodeOnly(string $path): string
{
    $code = '';

    foreach (token_get_all(file_get_contents($path)) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
}

/**
 * The body of one method, brace-matched.
 *
 * Substring search is not enough: `down()` appears in prose and in `dropColumn`, and "everything
 * after `function up()`" would include the rest of the class. Braces are counted over tokens, so a
 * `{` inside a string literal cannot shift the match.
 */
function mdsMethodBody(string $code, string $method): string
{
    $pattern = '/function\s+'.$method.'\s*\(/';

    if (! preg_match($pattern, $code, $match, PREG_OFFSET_CAPTURE)) {
        return '';
    }

    $from = $match[0][1] + strlen($match[0][0]);
    $open = strpos($code, '{', $from);

    if ($open === false) {
        return '';
    }

    $depth = 0;
    $length = strlen($code);

    for ($i = $open; $i < $length; $i++) {
        if ($code[$i] === '{') {
            $depth++;
        } elseif ($code[$i] === '}') {
            $depth--;

            if ($depth === 0) {
                return substr($code, $open + 1, $i - $open - 1);
            }
        }
    }

    return '';
}

/**
 * Data-writing calls in a method body.
 *
 * `Schema::` and `Blueprint` are DDL and are excluded by name; `DB::schema*` likewise. What counts
 * is a write to rows: a query-builder insert/update/delete, a raw statement, or an Eloquent model
 * being created, found or written.
 */
function mdsDataWrites(string $body): array
{
    $hits = [];

    if (preg_match_all('/DB::table\s*\((?:[^()]|\([^()]*\))*\)\s*(?:->\s*[A-Za-z]+\s*\((?:[^()]|\([^()]*\))*\)\s*)*->\s*(insert|insertOrIgnore|update|upsert|delete|increment|decrement)\s*\(/', $body, $m)) {
        foreach (array_unique($m[1]) as $verb) {
            $hits[] = 'DB::table()->'.$verb.'()';
        }
    }

    if (preg_match_all('/DB::(insert|update|delete|statement|unprepared)\s*\(/', $body, $m)) {
        foreach (array_unique($m[1]) as $verb) {
            $hits[] = 'DB::'.$verb.'()';
        }
    }

    // Eloquent access: a capitalised class that is not one of the schema/DDL/helper facades.
    $notModels = ['Schema', 'Blueprint', 'DB', 'Str', 'Arr', 'Carbon', 'Migration', 'Class', 'PDO', 'Log', 'Config', 'Artisan', 'Illuminate'];

    if (preg_match_all('/\b([A-Z][A-Za-z_]+)::(create|insert|updateOrCreate|firstOrCreate|firstOrNew|where|findOrFail|find|query)\s*\(/', $body, $m, PREG_SET_ORDER)) {
        $seen = [];

        foreach ($m as $match) {
            if (in_array($match[1], $notModels, true)) {
                continue;
            }

            $seen[$match[1].'::'.$match[2].'()'] = true;
        }

        foreach (array_keys($seen) as $call) {
            $hits[] = $call;
        }
    }

    sort($hits);

    return array_values(array_unique($hits));
}

test('no migration provisions content in up()', function () {
    $files = mdsMigrationFiles();

    $this->assertNotEmpty($files, 'no migrations were found, so the scanner proved nothing');

    $violations = [];
    $allowlisted = [];

    foreach ($files as $path) {
        $name = basename($path);
        $up = mdsMethodBody(mdsCodeOnly($path), 'up');
        $writes = mdsDataWrites($up);

        if ($writes === []) {
            continue;
        }

        if (array_key_exists($name, MDS_UP_ALLOWLIST)) {
            $allowlisted[$name] = $writes;

            continue;
        }

        $violations[$name] = $writes;
    }

    $messages = [];

    foreach ($violations as $name => $writes) {
        $messages[] = "$name writes data in up(): ".implode(', ', $writes).' — move it to a seeder';
    }

    // The allowlist must not rot: an entry whose migration no longer writes data in up() is stale,
    // and a stale allowlist is how a scanner goes blind without anyone noticing.
    foreach (MDS_UP_ALLOWLIST as $name => $reason) {
        if (! isset($allowlisted[$name])) {
            $messages[] = "$name is allowlisted for up() data writes but no longer makes any — remove it from MDS_UP_ALLOWLIST";
        }
    }

    $this->assertSame([], $messages, implode("\n", $messages));
});

test('down() data writes are enumerated, not merely permitted', function () {
    $found = [];

    foreach (mdsMigrationFiles() as $path) {
        $name = basename($path);
        $writes = mdsDataWrites(mdsMethodBody(mdsCodeOnly($path), 'down'));

        if ($writes !== []) {
            $found[$name] = $writes;
        }
    }

    $unexpected = array_diff(array_keys($found), array_keys(MDS_DOWN_ALLOWLIST));
    $stale = array_diff(array_keys(MDS_DOWN_ALLOWLIST), array_keys($found));

    $messages = [];

    foreach ($unexpected as $name) {
        $messages[] = "$name writes data in down(): ".implode(', ', $found[$name]).' — add it to MDS_DOWN_ALLOWLIST with a reason, or remove the write';
    }

    foreach ($stale as $name) {
        $messages[] = "$name is allowlisted for down() data writes but no longer makes any — remove it from MDS_DOWN_ALLOWLIST";
    }

    $this->assertSame([], $messages, implode("\n", $messages));
});

test('the scanner can see a violation, and can see that DDL is not one', function () {
    // Sensitivity probe. Without this the test above could pass because the scanner is blind:
    // an empty result and a clean result look identical from the outside.
    $provisioning = [
        "DB::table('roster_shifts')->insert(['shift_name' => 'X']);",
        "Account::create(['code' => '2115']);",
        "Account::where('code', '2115')->first();",
        "DB::statement('INSERT INTO acc_accounts (code) VALUES (?)', ['2115']);",
        "DB::table('acc_accounts')->where('code', '1')->delete();",
        "LeaveType::firstOrCreate(['name' => 'CL']);",
    ];

    foreach ($provisioning as $snippet) {
        $this->assertNotEmpty(mdsDataWrites($snippet), "the scanner did not flag: $snippet");
    }

    $ddl = [
        "Schema::create('acc_accounts', function (Blueprint \$t) { \$t->id(); \$t->string('code')->unique(); });",
        "Schema::table('purchase_receipts', function (Blueprint \$t) { \$t->foreignId('grni_journal_id')->nullable(); });",
        "if (! Schema::hasColumn('roster_shifts', 'is_fallback')) { \$table->boolean('is_fallback')->default(false); }",
        "Schema::dropIfExists('acc_journals');",
        "\$table->dropForeign(['grni_journal_id']); \$table->dropColumn('grni_journal_id');",
    ];

    foreach ($ddl as $snippet) {
        $this->assertSame([], mdsDataWrites($snippet), 'the scanner flagged pure DDL as a data write: '.$snippet.' → '.implode(', ', mdsDataWrites($snippet)));
    }

    // And the comment stripper has to be load-bearing: this migration talks about inserting in
    // prose only. If comments were not stripped, it would be flagged and the count would be a
    // regex-hit count rather than a defect count.
    $tmp = tempnam(sys_get_temp_dir(), 'mds').'.php';
    file_put_contents($tmp, "<?php\n// DB::table('acc_accounts')->insert(['code' => '9999']);\n/* Account::create(['code' => '9999']); */\nreturn new class { public function up(): void { Schema::create('x', function (\$t) { \$t->id(); }); } };\n");

    try {
        $this->assertSame(
            [],
            mdsDataWrites(mdsMethodBody(mdsCodeOnly($tmp), 'up')),
            'a data write mentioned only in a comment was counted as code'
        );
    } finally {
        @unlink($tmp);
    }
});

test('the two migrations that caused P0-B no longer provision content', function () {
    $purchase = base_path('modules/PURCHASE/Database/Migrations/2026_11_03_000075_add_grni_to_receipts.php');
    $roster = base_path('modules/HRM/Database/Migrations/2026_10_30_110000_add_buffer_and_fallback_to_shifts_and_policies.php');

    foreach ([$purchase, $roster] as $path) {
        expect(file_exists($path))->toBeTrue("expected migration is missing: $path");
    }

    $grniUp = mdsMethodBody(mdsCodeOnly($purchase), 'up');

    $this->assertStringNotContainsString('Account::create', $grniUp, 'the GRNI migration still creates the 2115 account itself');
    $this->assertStringNotContainsString('Account::where', $grniUp, 'the GRNI migration still reads the chart of accounts');
    // The DDL must survive the extraction — only the content write moves.
    $this->assertStringContainsString('grni_journal_id', $grniUp, 'the column the migration exists to add is gone, so the DDL was removed with the DML');

    $rosterUp = mdsMethodBody(mdsCodeOnly($roster), 'up');

    $this->assertStringNotContainsString('FLEXI / Ad-Hoc', $rosterUp, 'the roster migration still creates the fallback shift itself');
    $this->assertStringContainsString('is_fallback', $rosterUp, 'the columns the migration exists to add are gone');
});

test('the chart of accounts owns 2115, and gives it back when it is taken away', function () {
    // Attribution: the row must come from the seeder, not from a migration. Deleting it and
    // re-running the seeder is what proves that — a migration cannot run twice.
    $account = DB::table('acc_accounts')->where('code', '2115')->first();

    $this->assertNotNull($account, 'the chart of accounts has no 2115 after seeding');

    DB::table('acc_accounts')->where('code', '2115')->delete();

    expect(DB::table('acc_accounts')->where('code', '2115')->exists())->toBeFalse();

    app(AccountsDemoSeeder::class)->run();

    $restored = DB::table('acc_accounts')->where('code', '2115')->first();

    $this->assertNotNull($restored, 'AccountsDemoSeeder did not restore 2115, so the migration was still its only source');
    $this->assertSame('liability', $restored->account_type);
    $this->assertSame(1, (int) $restored->is_posting);
});

test('the fallback shift is seeded, idempotently, with the attributes the migration used to write', function () {
    $shift = DB::table('roster_shifts')->where('is_fallback', true)->first();

    $this->assertNotNull($shift, 'no fallback shift exists after seeding');

    // Extracting a row must not change the row. These are the values the migration wrote.
    expect($shift->shift_name)->toBe('FLEXI / Ad-Hoc')
        ->and($shift->start_time)->toStartWith('00:00:00')
        ->and($shift->end_time)->toStartWith('23:59:00')
        ->and((int) $shift->crosses_midnight)->toBe(0)
        ->and((int) $shift->max_duration_hours)->toBe(24)
        ->and((int) $shift->buffer_before)->toBe(0)
        ->and((int) $shift->buffer_after)->toBe(0);

    $this->assertSame(1, DB::table('roster_shifts')->where('is_fallback', true)->count(), 'more than one fallback shift exists');

    // The seeder owns the row now, so re-running it must not duplicate it.
    app(RosterFallbackShiftSeeder::class)->run();
    app(RosterFallbackShiftSeeder::class)->run();

    $this->assertSame(1, DB::table('roster_shifts')->where('is_fallback', true)->count(), 'RosterFallbackShiftSeeder is not idempotent');

    // And it must put the row back if it is taken away, which is the point of extracting it.
    DB::table('roster_shifts')->where('is_fallback', true)->delete();

    app(RosterFallbackShiftSeeder::class)->run();

    $this->assertSame(1, DB::table('roster_shifts')->where('is_fallback', true)->count(), 'the seeder did not restore the fallback shift');
});

test('a missing GRNI account stops a goods receipt loudly instead of posting it empty', function () {
    // This is the P0-B regression test. The defect was never the missing account; it was that a
    // missing account produced a document marked `posted` with no journal and no error.
    $warehouse = DB::table('inventory_warehouses')->first();
    $vendor = DB::table('partners')->where('type', 'vendor')->first();
    $product = DB::table('inventory_products')->first();

    expect($warehouse)->not->toBeNull()->and($vendor)->not->toBeNull()->and($product)->not->toBeNull();

    $purchaseOrders = app(PurchaseOrderService::class);
    $receipts = app(GoodsReceiptService::class);

    $order = $purchaseOrders->create([
        'vendor_id' => $vendor->id,
        'warehouse_id' => $warehouse->id,
        'order_date' => now()->toDateString(),
        'items' => [['product_id' => $product->id, 'qty' => 3, 'unit_price' => 250, 'vat_rate' => 0]],
    ]);
    $purchaseOrders->confirm($order);
    $order->refresh();

    $line = $order->items->first();

    $items = [[
        'product_id' => $line->product_id,
        'order_item_id' => $line->id,
        'qty' => (float) $line->qty,
        'unit_price' => (float) $line->unit_price,
        'vat_rate' => 0,
    ]];

    $receipt = $receipts->create([
        'order_id' => $order->id,
        'vendor_id' => $vendor->id,
        'warehouse_id' => $warehouse->id,
        'receipt_date' => now()->toDateString(),
        'items' => $items,
    ]);

    $grniCode = config('accounts.defaults.grni_code', '2115');
    $journalsBefore = DB::table('acc_journals')->count();
    $stockBefore = (float) DB::table('inventory_stocks')
        ->where('product_id', $product->id)
        ->where('warehouse_id', $warehouse->id)
        ->value('qty_on_hand');

    // The account is removed BEFORE anything references it. SQLite enforces foreign keys here
    // (database.connections.sqlite.foreign_key_constraints defaults to true), so deleting it
    // after a successful posting would fail on acc_journal_lines.account_id and the test would
    // be measuring the constraint instead of the refusal.
    DB::table('acc_accounts')->where('code', $grniCode)->delete();

    $thrown = null;

    try {
        $receipts->post($receipt);
    } catch (DomainException $e) {
        $thrown = $e;
    }

    $this->assertNotNull($thrown, 'posting a goods receipt with no GRNI account succeeded silently — this is exactly P0-B');
    $this->assertStringContainsString($grniCode, $thrown->getMessage(), 'the refusal does not name the account that is missing');

    $receipt->refresh();

    $this->assertNotSame('posted', $receipt->status, 'the receipt was marked posted despite the refused journal');
    // grni_journal_id, not journal_id: GoodsReceiptService::post() only ever writes the former.
    // Asserting on journal_id here would be vacuous — it is null whether or not the posting ran.
    $this->assertNull($receipt->grni_journal_id, 'the receipt carries a journal id for a journal that was never written');
    $this->assertSame($journalsBefore, DB::table('acc_journals')->count(), 'a journal was written for a receipt whose posting was refused');

    $stockAfter = (float) DB::table('inventory_stocks')
        ->where('product_id', $product->id)
        ->where('warehouse_id', $warehouse->id)
        ->value('qty_on_hand');

    // post() restocks BEFORE it resolves the accounts, so this is the assertion that proves the
    // whole thing runs inside one transaction: a refusal has to take the stock back with it.
    $this->assertSame($stockBefore, $stockAfter, 'the refused posting left stock behind, so it did not roll back');

    // And recovery goes through the seeder that owns the chart, not through a migration — which
    // is the whole point of the extraction, and the reason a migration cannot be re-run here.
    app(AccountsDemoSeeder::class)->run();

    $this->assertTrue(DB::table('acc_accounts')->where('code', $grniCode)->exists(), 'AccountsDemoSeeder did not restore the GRNI account');

    $receipts->post($receipt->refresh());
    $receipt->refresh();

    expect($receipt->status)->toBe('posted')
        ->and($receipt->grni_journal_id)->not->toBeNull('the receipt posted but carries no GRNI journal')
        ->and(DB::table('acc_journals')->count())->toBe($journalsBefore + 1);
});

test('the accounts support class no longer documents a migration as the source of 2115', function () {
    // ChartOfAccounts.php explained P0-B by naming the migration that provisioned the account.
    // After the extraction that sentence is false, and a false comment in the file whose whole
    // purpose is to stop silent posting failures is its own defect.
    $source = file_get_contents(base_path('modules/ACCOUNTS/Support/ChartOfAccounts.php'));

    $this->assertStringNotContainsString('the only account any *migration* provisions is 2115', $source);
    $this->assertStringContainsString('no migration provisions', $source, 'the docblock does not state the new invariant');
});

test('the extraction preserved what the migration used to write about 2115', function () {
    // The migration's row and the seeder's row were not equivalent, so extracting the write is
    // not enough: the semantics have to travel with it. `is_posting` is safe on its own, because
    // Account's saving hook derives it as `! is_group`. `is_system` defaults to 0 and the
    // migration set it to 1, so without this the extraction would silently make the GRNI account
    // deletable from the chart-of-accounts screen.
    $account = DB::table('acc_accounts')->where('code', '2115')->first();

    expect($account)->not->toBeNull();

    $this->assertSame(1, (int) $account->is_posting, '2115 cannot accept postings');
    $this->assertSame(1, (int) $account->is_system, '2115 lost is_system in the extraction, so an admin can now delete the account GRNI accruals post to');
    $this->assertSame(0, (int) $account->is_group);
    $this->assertSame('liability', $account->account_type);
    $this->assertSame('credit', $account->nature);
    $this->assertSame(1, (int) $account->is_active);

    $parent = DB::table('acc_accounts')->where('code', '2000')->first();

    $this->assertSame((int) $parent->id, (int) $account->parent_id, '2115 is not parented under Liabilities');
    $this->assertSame((int) $parent->level + 1, (int) $account->level, '2115 is not one level below Liabilities');
});
