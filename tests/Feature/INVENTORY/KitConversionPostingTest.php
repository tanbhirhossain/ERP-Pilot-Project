<?php

/**
 * Report item 44, P1.20 — a kit build must not journalise a transfer the chart cannot see.
 *
 * The defect, exactly as the audit measured it: `KitService::complete()` consumed the components,
 * produced the finished kit, and then posted a JV whose **two legs both resolved to
 * `accounts.defaults.inventory_code`** — Dr Inventory 20.00, Cr Inventory 20.00. The entry balanced
 * (which is why nothing complained), netted to zero, and recorded no value transfer, because there
 * was no value transfer to record: both stocks sit in the same account. What it did do was **gross up
 * both sides of the inventory ledger** by the component cost on every build, so a reader of account
 * 1200 saw movement that never happened, and the running balance walked through numbers no
 * transaction explains. The kit gained nothing from it either: `inventory_kits` has no `journal_id`
 * column, so the entry was not even a link back to the build.
 *
 * The fix has two halves, and the report is explicit about why the second one is a config key rather
 * than a new account. Inventing a WIP or finished-goods account **would be a change to somebody's
 * accounting policy rather than a bug fix** — it would put a line on a balance sheet the chart's owner
 * never asked for. So:
 *
 *  1. When the chart keeps component and finished stock in one account, `complete()` posts **nothing**.
 *     The conversion is inventory-internal, and the stock ledger already carries both legs against the
 *     kit number — `kit_component` out and `kit_production` in, both in `reference_type`, both with
 *     the kit number in `reference_no` — so skipping the journal loses no linkage and no audit trail.
 *     Tests 1 and 2 pin that the money side is quiet *and* the stock side still moves, because the
 *     cheap way to "fix" a zero-net journal is to break the build that needed it.
 *  2. When `accounts.defaults.kit_output_code` names a real account, the conversion posts for real:
 *     Dr kit output, Cr inventory. Test 3 pins the balanced transfer; tests 4 and 5 pin that a
 *     degenerate configuration — the inventory code itself, or an empty string arriving from a `.env`
 *     key set to nothing — cannot resurrect the zero-net entry; test 6 pins that a configured code the
 *     chart does not have refuses the build and rolls it back whole, the components having already
 *     been consumed by the time the journal is written.
 *
 * Test 7 is the second half of P1.20. Three services resolved their accounts from bare literals —
 * `LandedCostService` credited `'6000'` for every landed-cost line whose own `account_id` was null,
 * and `InvoiceService` and `SalesReturnService` both wrote `$cogsCode = '5001'` beside five config
 * lookups on the neighbouring lines. All three now read `accounts.defaults`, and `landed_cost_code`
 * exists because the literal it replaced carried a comment saying no such key did. The defaults are
 * the same numbers the literals were, so no chart and no posted amount changes; what changes is that a
 * deployment numbering its freight or COGS accounts differently can configure its way out instead of
 * editing PHP. The test scans for the *form* rather than for those three files, because a scanner that
 * only knows about the sites already found cannot catch the fourth one. Note that the seeded chart has
 * no account 6000 at all, which is why a landed-cost allocation with unnamed lines used to throw.
 *
 * Test 8 is the scanner's own proof. A census test reporting zero is indistinguishable from one whose
 * regex has gone blind, so the fixture asserts the scan both ways: two violations are caught with
 * file, line and code, while the legitimate `config('accounts.defaults.cogs_code', '5001')` form on
 * the line below them is not — that fallback literal is where the number is supposed to live — and
 * neither is a code quoted in a comment.
 *
 * Helpers are prefixed `kcp`; Pest loads every Feature file into one process. `RefreshDatabase` comes
 * from tests/Pest.php, and the chart and fiscal year are built here rather than seeded whole, so a kit
 * build is tested against exactly the accounts the test says it should use.
 */

use Modules\ACCOUNTS\Models\Account;
use Modules\ACCOUNTS\Models\AccountLedger;
use Modules\ACCOUNTS\Models\FiscalYear;
use Modules\ACCOUNTS\Models\Journal;
use Modules\ACCOUNTS\Models\JournalLine;
use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\CORE\Models\User;
use Modules\INVENTORY\Actions\ReceiveStockAction;
use Modules\INVENTORY\Models\Kit;
use Modules\INVENTORY\Models\Product;
use Modules\INVENTORY\Models\Stock;
use Modules\INVENTORY\Models\StockMove;
use Modules\INVENTORY\Models\Warehouse;
use Modules\INVENTORY\Services\KitService;

/** The fixture year covers today, because the journal is dated `now()`. */
const KCP_START = '2026-07-01';
const KCP_END = '2027-06-30';

/** 10 received at 5.00, 4 consumed per build, 2 kits produced → 20.00 total, 10.00 per kit. */
const KCP_UNIT_COST = 5.00;
const KCP_RECEIVED = 10.0;
const KCP_REQUIRED_QTY = 4.0;
const KCP_TO_PRODUCE = 2.0;
const KCP_TOTAL_COST = 20.00;

function kcpAdmin(): User
{
    test()->seed(SuperAdminSeeder::class);

    $admin = User::where('email', config('core.super_admin.email', 'admin@example.com'))->first();
    expect($admin)->not->toBeNull('SuperAdminSeeder produced no admin user');

    test()->actingAs($admin);

    return $admin;
}

/** A leaf posting account, active and not a group header — posting to a group is refused. */
function kcpAccount(string $code, string $name, string $nature = 'debit'): Account
{
    return Account::create([
        'code' => $code,
        'name' => $name,
        'account_type' => $nature === 'debit' ? 'asset' : 'expense',
        'nature' => $nature,
        'level' => 1,
        'is_group' => false,
        'is_posting' => true,
        'is_active' => true,
        'currency' => 'BDT',
        'opening_balance' => 0,
    ]);
}

function kcpFiscalYear(): FiscalYear
{
    return FiscalYear::create([
        'name' => 'FY 2026-27',
        'code' => 'FY2627-KCP',
        'start_date' => KCP_START,
        'end_date' => KCP_END,
        'status' => 'open',
    ]);
}

function kcpWarehouse(string $code = 'KCP-WH-1'): Warehouse
{
    return Warehouse::create([
        'name' => 'KCP Warehouse',
        'code' => $code,
        'type' => 'main',   // CHECK constraint: main|sub|transit|virtual
        'is_active' => true,
        'is_default' => true,
        'allow_negative_stock' => false,
    ]);
}

function kcpProduct(string $sku, string $name): Product
{
    return Product::create([
        'name' => $name,
        'sku' => $sku,
        'type' => 'stockable',   // CHECK constraint: stockable|bundle
        'status' => 'active',
        'valuation_method' => 'weighted',
        'cost_price' => KCP_UNIT_COST,
        'selling_price' => 9.50,
        'is_batch_tracked' => false,
    ]);
}

/** Stock must exist before the kit is created: `create()` costs the components from valuation then. */
function kcpReceive(int $productId, int $warehouseId, float $qty, float $unitCost): StockMove
{
    $moves = app(ReceiveStockAction::class)->execute([
        ['product_id' => $productId, 'warehouse_id' => $warehouseId, 'qty' => $qty, 'unit_cost' => $unitCost, 'lot_no' => null],
    ], 'opening', 'KCP-RCV');

    return $moves[0];
}

function kcpStockQty(int $productId, int $warehouseId): float
{
    return round((float) Stock::where('product_id', $productId)->where('warehouse_id', $warehouseId)->value('qty_on_hand'), 2);
}

/** Total quantity moved for one leg of a build. `deductStock($items, $ref, $type)` — the kit's legs
 *  are identified by `reference_type`, with the kit number in `reference_no`. */
function kcpMovedQty(string $referenceType, string $referenceNo): float
{
    return round((float) StockMove::where('reference_type', $referenceType)
        ->where('reference_no', $referenceNo)
        ->sum('qty'), 2);
}

/**
 * A draft kit with its component already in stock.
 *
 * Pass an output code to configure `accounts.defaults.kit_output_code` and create that account; the
 * default null leaves the key empty, which is how every deployment ships today.
 *
 * @return array{0: Kit, 1: Product, 2: Product, 3: Warehouse}
 */
function kcpDraftKit(?string $outputCode = null): array
{
    kcpAdmin();
    kcpFiscalYear();
    $warehouse = kcpWarehouse();
    $component = kcpProduct('KCP-COMP-1', 'KCP Cotton Fabric');
    $finished = kcpProduct('KCP-KIT-1', 'KCP Shirt Kit');
    kcpReceive($component->id, $warehouse->id, KCP_RECEIVED, KCP_UNIT_COST);

    kcpAccount('1200', 'Inventory');
    if ($outputCode !== null) {
        kcpAccount($outputCode, 'Kit Output / WIP');
        config(['accounts.defaults.kit_output_code' => $outputCode]);
    }

    $kit = app(KitService::class)->create([
        'product_id' => $finished->id,
        'warehouse_id' => $warehouse->id,
        'qty_to_produce' => KCP_TO_PRODUCE,
        'notes' => 'KCP fixture build',
        'items' => [
            ['component_product_id' => $component->id, 'required_qty' => KCP_REQUIRED_QTY],
        ],
    ]);

    return [$kit->fresh(['items']), $component, $finished, $warehouse];
}

/** Running-balance rows on an account — this is where the zero-net journal used to show up. */
function kcpLedgerRows(int $accountId): int
{
    return AccountLedger::where('account_id', $accountId)->count();
}

/**
 * The two literal forms P1.20 bans: an account code quoted straight into a resolver, and one assigned
 * to a `*Code` variable.
 */
function kcpLiteralPatterns(): array
{
    return [
        'resolver' => "/(?:requireId|accountIdByCode|accountCodeToId)\\s*\\(\\s*[^,()]*,\\s*'(\\d{4})'/",
        'assignment' => "/\\$\\w*[Cc]ode\\w*\\s*=\\s*'(\\d{4})'\\s*;/",
    ];
}

/**
 * Every non-comment PHP line under $dirs that resolves an account from a bare numeric literal.
 *
 * Only whole-line comments are removed, so a code line's trailing prose cannot be mistaken for a
 * violation and a scanner that strips too much cannot hide one either. A seeded chart and a config
 * file legitimately contain codes, so `Database/` and `Config/` are excluded: the ban is on a *service*
 * choosing its own account number instead of asking the deployment.
 *
 * @return list<string> "path:line code (kind)"
 */
function kcpAccountLiteralHits(array $dirs): array
{
    $hits = [];
    $excluded = ['Database', 'Config', 'Resources', 'Tests', 'tests'];

    foreach ($dirs as $dir) {
        if (! is_dir($dir)) {
            continue;
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            $path = $file->getPathname();
            if (! str_ends_with($path, '.php')) {
                continue;
            }
            if (array_intersect(explode(DIRECTORY_SEPARATOR, $path), $excluded) !== []) {
                continue;
            }
            foreach (file($path) ?: [] as $i => $line) {
                $trimmed = ltrim($line);
                if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')
                    || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '#')) {
                    continue;
                }
                foreach (kcpLiteralPatterns() as $kind => $pattern) {
                    if (preg_match($pattern, $line, $m) === 1) {
                        $hits[] = $path.':'.($i + 1).' '.$m[1].' ('.$kind.')';
                    }
                }
            }
        }
    }

    sort($hits);

    return $hits;
}

// ─────────────────────────────────────────────────────────────────────────────
// Half 1: no output account, no journal — and the build still happens
// ─────────────────────────────────────────────────────────────────────────────

test('a kit build posts no journal when the chart keeps both stocks in one account', function () {
    [$kit, $component, $finished, $warehouse] = kcpDraftKit();

    $this->assertNull(
        config('accounts.defaults.kit_output_code'),
        'the key ships empty: inventing a WIP account would be a policy change, not a bug fix'
    );

    $completed = app(KitService::class)->complete($kit);
    $inventory = Account::where('code', '1200')->firstOrFail();

    $this->assertSame(0, Journal::where('source_type', 'kit')->count(), 'nothing is journalised');
    $this->assertSame(
        0,
        JournalLine::where('account_id', $inventory->id)->count(),
        'the inventory ledger is not grossed up by a Dr and Cr of the same amount'
    );
    $this->assertSame(
        0,
        kcpLedgerRows($inventory->id),
        'no running balance was walked by an entry that netted to zero'
    );

    expect($completed->status)->toBe('completed')
        ->and(round((float) $completed->total_component_cost, 2))->toBe(KCP_TOTAL_COST)
        ->and(round((float) $completed->unit_cost, 2))->toBe(10.00)
        ->and(round((float) $completed->qty_produced, 2))->toBe(KCP_TO_PRODUCE);
});

test('the stock ledger still carries both legs of a build that posts no journal', function () {
    [$kit, $component, $finished, $warehouse] = kcpDraftKit();

    app(KitService::class)->complete($kit);

    // Components consumed, finished kit produced. Skipping the journal must not skip the build — and
    // these two rows are the audit trail the skipped journal used to pretend to be.
    expect(kcpStockQty($component->id, $warehouse->id))->toBe(6.0)
        ->and(kcpStockQty($finished->id, $warehouse->id))->toBe(KCP_TO_PRODUCE)
        ->and(kcpMovedQty('kit_component', $kit->kit_no))->toBe(KCP_REQUIRED_QTY)
        ->and(kcpMovedQty('kit_production', $kit->kit_no))->toBe(KCP_TO_PRODUCE)
        ->and(StockMove::where('reference_type', 'kit_component')->value('direction'))->toBe('out')
        ->and(StockMove::where('reference_type', 'kit_production')->value('direction'))->toBe('in');
});

// ─────────────────────────────────────────────────────────────────────────────
// Half 2: a configured output account turns the conversion into a real entry
// ─────────────────────────────────────────────────────────────────────────────

test('a configured output account posts Dr kit output and Cr inventory for the component cost', function () {
    [$kit, $component, $finished, $warehouse] = kcpDraftKit('1201');

    app(KitService::class)->complete($kit);

    $journal = Journal::where('source_type', 'kit')->where('source_id', $kit->id)->first();
    expect($journal)->not->toBeNull('with an output account configured the conversion is a real transfer and must post');

    $inventory = Account::where('code', '1200')->firstOrFail();
    $output = Account::where('code', '1201')->firstOrFail();
    $lines = JournalLine::where('journal_id', $journal->id)->get();
    $debitSide = $lines->where('account_id', $output->id);
    $creditSide = $lines->where('account_id', $inventory->id);

    expect($journal->status)->toBe('posted')
        ->and(round((float) $journal->total_debit, 2))->toBe(KCP_TOTAL_COST)
        ->and(round((float) $journal->total_credit, 2))->toBe(KCP_TOTAL_COST)
        ->and($journal->reference_no)->toBe($kit->kit_no)
        ->and($lines)->toHaveCount(2)
        ->and(round((float) $debitSide->sum('debit'), 2))->toBe(KCP_TOTAL_COST)
        ->and(round((float) $creditSide->sum('credit'), 2))->toBe(KCP_TOTAL_COST)
        // The two lines below are the defect in reverse: an account carrying both sides of one entry
        // is what netted to zero before, so neither leg may appear on the other account.
        ->and(round((float) $debitSide->sum('credit'), 2))->toBe(0.0)
        ->and(round((float) $creditSide->sum('debit'), 2))->toBe(0.0)
        ->and(kcpLedgerRows($output->id))->toBe(1)
        ->and(kcpLedgerRows($inventory->id))->toBe(1);
});

test('configuring the inventory code as the output code cannot resurrect the zero-net entry', function () {
    [$kit, $component, $finished, $warehouse] = kcpDraftKit();
    config(['accounts.defaults.kit_output_code' => '1200']);

    app(KitService::class)->complete($kit);

    $inventory = Account::where('code', '1200')->firstOrFail();

    $this->assertSame(0, Journal::where('source_type', 'kit')->count(), 'both legs would resolve to the same account again');
    $this->assertSame(0, kcpLedgerRows($inventory->id));

    expect($kit->fresh()->status)->toBe('completed')
        ->and(kcpMovedQty('kit_component', $kit->kit_no))->toBe(KCP_REQUIRED_QTY);
});

test('an empty output code is treated as unconfigured', function () {
    [$kit, $component, $finished, $warehouse] = kcpDraftKit();
    // A key present but set to nothing in a deployment's .env arrives as ''. A bare null check would
    // have handed '' to requireId and refused the whole build over a typo in an env file.
    config(['accounts.defaults.kit_output_code' => '']);

    app(KitService::class)->complete($kit);

    expect(Journal::where('source_type', 'kit')->count())->toBe(0)
        ->and($kit->fresh()->status)->toBe('completed')
        ->and(kcpStockQty($component->id, $warehouse->id))->toBe(6.0);
});

test('a configured output account the chart does not have refuses the build and rolls it back whole', function () {
    [$kit, $component, $finished, $warehouse] = kcpDraftKit();
    config(['accounts.defaults.kit_output_code' => '1299']);

    // The components are consumed before the journal is written, so a missing account has to unwind
    // the whole transaction rather than leave a completed kit with no entry behind it.
    $thrown = null;
    try {
        app(KitService::class)->complete($kit);
    } catch (DomainException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull('requireId must refuse rather than post against a guessed account');
    $this->assertStringContainsString('1299', $thrown->getMessage());
    $this->assertStringContainsString(
        'point accounts.defaults at the code you use',
        $thrown->getMessage(),
        'the refusal tells the operator which knob to turn — and that knob now exists'
    );

    expect($kit->fresh()->status)->toBe('draft')
        ->and(kcpStockQty($component->id, $warehouse->id))->toBe(KCP_RECEIVED)
        ->and(StockMove::where('reference_type', 'kit_component')->count())->toBe(0)
        ->and(StockMove::where('reference_type', 'kit_production')->count())->toBe(0)
        ->and(Journal::where('source_type', 'kit')->count())->toBe(0);
});

// ─────────────────────────────────────────────────────────────────────────────
// The other half of P1.20: no service picks its own account number
// ─────────────────────────────────────────────────────────────────────────────

test('no service resolves an account from a bare numeric literal', function () {
    $dirs = [base_path('app')];
    foreach (glob(base_path('modules/*'), GLOB_ONLYDIR) ?: [] as $module) {
        $dirs[] = $module;
    }

    $hits = kcpAccountLiteralHits($dirs);

    // Zero, measured rather than assumed. The census when this was written was three sites —
    // LandedCostService's '6000' and a '5001' each in InvoiceService and SalesReturnService — and all
    // three now read accounts.defaults.landed_cost_code and accounts.defaults.cogs_code.
    $this->assertSame([], $hits, "services choosing their own account codes:\n".implode("\n", $hits));
});

test('the literal scanner catches a violation and leaves the config form alone', function () {
    $dir = storage_path('framework/testing/kcp-fixture');
    @mkdir($dir, 0777, true);
    $fixture = $dir.'/LandedCostLike.php';
    file_put_contents($fixture, <<<'PHP'
<?php

// A fixture for the census test. This comment quotes '9003' and must not be counted.
$acctId = ChartOfAccounts::requireId($posting, '9001', $purpose);
$cogsCode = '9002';
$okCode = config('accounts.defaults.cogs_code', '5001');
PHP);

    try {
        $hits = kcpAccountLiteralHits([$dir]);
        $joined = implode("\n", $hits);

        expect($hits)->toHaveCount(2, 'the resolver form and the assignment form, and nothing else')
            ->and($hits[0])->toContain('LandedCostLike.php:4')
            ->and($hits[0])->toContain('9001')
            ->and($hits[0])->toContain('(resolver)')
            ->and($hits[1])->toContain('9002')
            ->and($hits[1])->toContain('(assignment)');

        $this->assertStringNotContainsString('5001', $joined, 'the config fallback is where a default code belongs');
        $this->assertStringNotContainsString('9003', $joined, 'a code quoted in a comment is not a violation');
    } finally {
        @unlink($fixture);
        @rmdir($dir);
    }
});

test('accounts.defaults publishes every code the posting services read', function () {
    // These keys are the contract a deployment configures against, so their presence and their
    // defaults are pinned: the defaults are the literals they replaced, which is why no chart and no
    // posted amount changes as a result of item 44.
    expect(config('accounts.defaults.inventory_code'))->toBe('1200')
        ->and(config('accounts.defaults.cogs_code'))->toBe('5001')
        ->and(config('accounts.defaults.landed_cost_code'))->toBe('6000')
        ->and(config('accounts.defaults.sales_code'))->toBe('4001')
        ->and(config('accounts.defaults.receivable_code'))->toBe('1100');

    $this->assertNull(config('accounts.defaults.kit_output_code'), 'empty until a chart separates kit output');
});
