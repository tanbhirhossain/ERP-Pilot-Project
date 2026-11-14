<?php

/**
 * P1.2 phase 1 — money must be declared decimal at the model layer.
 *
 * The audit's finding was that the ledger *columns* are correctly decimal while the PHP layer is not.
 * Measured rather than estimated, that gap is concrete: of the numeric money columns in the schema,
 * 186 already carry a `decimal:N` cast and **41 did not**, across 15 models — 33 of them in SALES
 * alone, covering essentially the whole sales-document money surface (`Invoice`, `InvoiceItem`,
 * `Order`, `OrderItem`, `PosSale`, `PosSaleItem`, `Quotation`, `QuotationItem`).
 *
 * The visible consequence, measured in Tinker before the fix: on one `Invoice` instance,
 * `total_amount` (cast) returned `string(7) "1234.50"` while `subtotal` (uncasted) returned
 * `float(1234.5)`. Two money fields of the same document, serialised into the same Inertia payload,
 * in two different shapes — one of them a binary float. That is how `Reports/Index.vue` came to
 * render `48213.770000000004` next to "BDT" (P0-F), and it is why the API contract for a sales
 * document could not be stated in one sentence.
 *
 * This file is the guard that keeps the count at zero. It walks the *live* schema, maps every table
 * to its model, and fails if any numeric money column is handed back as anything other than a
 * decimal string. It is deliberately a discovery test: it finds the columns itself rather than
 * listing them, so a new model or a new migration is covered on the day it lands.
 *
 * Two methodology traps this test is written to avoid, both of which were hit while measuring the
 * figure above:
 *   - A one-level `glob()` over the modules' Models directories is FLAT. `Models/Employee/`,
 *     `Models/Leave/` and `Models/Attendance/` hold real models, and a flat glob silently reports
 *     their columns as
 *     belonging to no model at all — which reads as "nothing to fix" instead of "looked in the wrong
 *     place". The scan here recurses, and asserts it found the models it expects.
 *   - A discovery test whose scan finds nothing passes vacuously. Every count below is asserted.
 *
 * Helpers are prefixed `mcc`; Pest loads every Feature file into one process.
 */

use Tests\Paths;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\SALES\Models\Invoice;
use Modules\SALES\Models\InvoiceItem;

/** SQL types that mean "this column holds a number", across the drivers CI runs. */
const MCC_NUMERIC_TYPES = ['numeric', 'decimal', 'float', 'double', 'real'];

/**
 * Column-name fragments that mean "this number is money, or a rate/divisor/quantity that is stored
 * in a decimal column and therefore has the same rounding contract".
 */
const MCC_MONEY_PATTERN = '/(amount|price|total|value|balance|rate|cost|paid|due|salary|wage|fee|tax|vat|discount|debit|credit|opening|advance|arrears|net|gross|deduction|earning|commission|interest|principal|mrp|limit|divisor|penalty|hours|days)/i';

/**
 * Columns that match the pattern but are deliberately not held to the decimal contract, each with
 * the reason. Keep this short: an entry here is a decision, not a convenience.
 *
 * @var array<string, string> "table.column" => reason
 */
const MCC_ALLOWLIST = [];

/**
 * Map every table to the model that owns it.
 *
 * @return array<string, array{file: string, class: string, casts: string}>
 */
function mccModelsByTable(): array
{
    $root = dirname(__DIR__, 3);
    expect(is_dir($root.'/modules'))->toBeTrue('the scan root is wrong, so this test would pass vacuously');

    $tables = mccTableNames();
    $models = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/modules'));

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php' || ! str_contains(Paths::normalise($file->getPathname()), '/Models/')) {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (! preg_match('/\bclass\s+(\w+)\s+extends\b/', $source, $class)) {
            continue;
        }

        // An explicit $table wins; otherwise Laravel's convention applies.
        $table = preg_match("/protected\s+\\\$table\s*=\s*'([^']+)'/", $source, $m)
            ? $m[1]
            : Str::snake(Str::pluralStudly($class[1]));

        if (! in_array($table, $tables, true)) {
            continue;
        }

        preg_match('/protected\s+\$casts\s*=\s*\[(.*?)\];/s', $source, $casts);

        $relative = Paths::relativePath($file->getPathname());

        // More than one class can map to the same table: HRM keeps five one-line aliases
        // (Modules\HRM\Models\RosterOvertime extends Models\Roster\RosterOvertime, and so on)
        // which declare no casts of their own. Letting an alias overwrite the real model reported
        // `roster_overtimes.hours` as uncasted when the model that owns the table casts it
        // `decimal:2`. Concatenating every block for the table makes the alias contribute nothing
        // and the real model decide.
        if (isset($models[$table])) {
            $models[$table]['casts'] .= ', '.($casts[1] ?? '');

            if (trim($casts[1] ?? '') !== '') {
                $models[$table]['file'] = $relative;
                $models[$table]['class'] = $class[1];
            }

            continue;
        }

        $models[$table] = [
            'file' => $relative,
            'class' => $class[1],
            'casts' => $casts[1] ?? '',
        ];
    }

    return $models;
}

/**
 * Every table in the live schema, on whichever driver is running.
 *
 * @return list<string>
 */
function mccTableNames(): array
{
    $connection = Schema::getConnection();

    $rows = $connection->getDriverName() === 'sqlite'
        ? $connection->select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")
        : $connection->select('SHOW TABLES');

    return array_map(static function (object $row): string {
        $values = array_values((array) $row);

        return (string) reset($values);
    }, $rows);
}

/**
 * Every numeric column that looks like money, on a table a model owns.
 *
 * @return list<array{table: string, column: string, type: string, model: string}>
 */
function mccMoneyColumns(): array
{
    $models = mccModelsByTable();
    $found = [];

    foreach (array_keys($models) as $table) {
        foreach (Schema::getColumnListing($table) as $column) {
            if (! preg_match(MCC_MONEY_PATTERN, $column)) {
                continue;
            }

            $type = strtolower((string) Schema::getColumnType($table, $column));

            if (! in_array($type, MCC_NUMERIC_TYPES, true)) {
                continue;
            }

            $found[] = ['table' => $table, 'column' => $column, 'type' => $type, 'model' => $models[$table]['file']];
        }
    }

    return $found;
}

/**
 * The source with every comment removed, so a scan matches code and not prose about code.
 */
function mccCodeOnly(string $source): string
{
    $code = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= $token[1];

            continue;
        }

        $code .= $token;
    }

    return $code;
}

/** The `decimal:N` a model declares for a column, or null when it declares none. */
function mccDeclaredDecimal(string $castsBlock, string $column): ?string
{
    return preg_match("/'".preg_quote($column, '/')."'\s*=>\s*'(decimal:\d+)'/", $castsBlock, $m)
        ? $m[1]
        : null;
}

// ─────────────────────────────────────────────────────────────────────────────

test('the model scan recurses into subdirectories and finds the models it should', function () {
    $models = mccModelsByTable();

    // A flat glob over modules/*/Models/*.php finds about 136; the nested Employee, Leave and
    // Attendance directories take the real figure past 150. Asserting the floor is what stops this
    // test from quietly degrading into the flat-glob version and reporting "nothing to fix".
    // 156 and 242 since item 39: `PosCollection` is a money-bearing model, and the day-end
    // migration adds twelve `decimal(15,2)` columns across `pos_sessions` and `pos_sales`.
    // 157 since item 42: `CoreSetting` (the company colour scheme) joins the scan. It holds no
    // decimal columns, so it moves the census and nothing else — which is why the census and the
    // money-column count below are separate assertions.
    // 160 since Phase 1: `ApprovalPolicy`, `ApprovalRequest` and `OpeningBalanceBatch` join the
    // scan, each carrying one `decimal(15,2)` column (min_amount / amount / total_amount).
    // 162 since Phase 2: `SavedView` and `Attachment` join the scan. Neither holds a decimal
    // column, so they move the census and not the money-column count below.
    // 165 since Phase 3: the three tax-withholding models (`WithholdingRule`, `TaxDeposit`,
    // `Withholding`) join the scan for the Bangladesh TDS/VDS + Mushak 9.1 pack.
    // 169 since F15: the fixed-asset models (`AssetCategory`, `FixedAsset`, `DepreciationRun`,
    // `AssetDepreciation`) join the scan.
    // 171 since F14: `Budget` and `BudgetLine` join the scan. `Budget` holds only a currency string
    // and status, `BudgetLine` holds the planned amount — which is why the money census moves by one.
    // 178 since F18: the seven requisition/RFQ models join it. Five of them own one of the six new money
    // columns below; the two that do not (`PurchaseRfq`, `PurchaseRfqLine`) store no money at all — a
    // round has one total per vendor, not one total, and its lines only ask for a quantity. Every decimal
    // on those seven tables is `decimal:2` cast regardless, quantities included.
    // 179 since SSO: `SsoIdentity` joins it. It owns no money column and must not acquire one — the table
    // records which external subject may sign in as whom, and a "quota" or "credit limit" column added to
    // it later would be the kind of thing that silently stops being poisha-canonical because nobody wrote a
    // cast for a model nobody thought about.
    expect($models)->toHaveCount(179)
        ->and($models)->toHaveKey('sales_invoices')
        ->and($models)->toHaveKey('acc_journals')
        ->and($models['sales_invoices']['class'])->toBe('Invoice');
});

test('the classifier actually finds money columns to judge', function () {
    $columns = mccMoneyColumns();

    // Pinned exactly, like the model count above. If this moves, either a migration added a money
    // column — in which case the next test judges it — or the classifier's pattern drifted, in which
    // case it may have stopped matching anything at all. Both are worth noticing out loud.
    // 245 since Phase 1: approval_policies.min_amount, approval_requests.amount and
    // opening_balance_batches.total_amount add three money columns to the census.
    // 250 since Phase 3: the withholding pack adds five — rules.rate, deposits.amount, and
    // withholdings' base_amount / rate / amount. (The rules' `threshold` is a decimal too, but
    // its name is outside MCC_MONEY_PATTERN, so it stays out of the money census by design.)
    // 255 since F15: the fixed-asset pack adds five more — assets' cost / salvage_value /
    // net_book_value, runs.total_amount, and the line's opening_nbv. (accumulated_dep, closing_nbv,
    // charge, disposal_proceeds and the category defaults are decimals too but sit outside the name
    // pattern — and every one is still `decimal:2` cast regardless, so the next test passes on them.)
    // 256 since F14: `acc_budget_lines.amount` — one, because `acc_budgets` deliberately stores no money:
    // actuals are never persisted, they are read live from the posted ledger, so there is nothing to drift.
    // 262 since F18: six on the requisition/quote tables — `purchase_requisitions.estimated_amount`,
    // `purchase_requisition_items.expected_unit_price`, `purchase_rfq_quotes.total_amount`,
    // `purchase_rfq_quote_items.unit_price` + `.line_total`, `purchase_rfq_awards.unit_price`. The other
    // decimals those tables add (`line_estimate`, `qty`, `qty_ordered`, `awarded_qty`) are measures or
    // fall outside MCC_MONEY_PATTERN, and are cast all the same. `purchase_rfqs` gains no money column on
    // purpose: a round has one total per vendor, and a stored "round total" is how a header ends up
    // disagreeing with the lines under it.
    expect($columns)->toHaveCount(262, 'the classifier found a different number of money columns than the last audit pass');

    // And it is looking at numeric columns, not at varchar columns that happen to be named "rate".
    foreach ($columns as $c) {
        expect(MCC_NUMERIC_TYPES)->toContain($c['type']);
    }
});

test('every money column is handed back as a decimal string, not a float', function () {
    $models = mccModelsByTable();
    $offenders = [];

    foreach (mccMoneyColumns() as $c) {
        $key = $c['table'].'.'.$c['column'];

        if (isset(MCC_ALLOWLIST[$key])) {
            continue;
        }

        if (mccDeclaredDecimal($models[$c['table']]['casts'], $c['column']) === null) {
            $offenders[] = $key.' ('.$c['model'].')';
        }
    }

    sort($offenders);

    expect($offenders)->toBe([], implode("\n", $offenders));
});

test('a sales document reports all of its money in one shape', function () {
    // No persistence needed: a cast is applied on attribute access, so this measures the contract
    // the Inertia payload is built from.
    $invoice = new Invoice;
    $invoice->subtotal = 1234.5;
    $invoice->discount_amount = 0;
    $invoice->vat_amount = 12.25;
    $invoice->total_amount = 1246.75;

    expect($invoice->subtotal)->toBe('1234.50')
        ->and($invoice->discount_amount)->toBe('0.00')
        ->and($invoice->vat_amount)->toBe('12.25')
        ->and($invoice->total_amount)->toBe('1246.75');

    // The reason this matters: before the casts were added, subtotal came back float(1234.5) while
    // its neighbour total_amount came back string(7) "1234.50" — two shapes in one payload.
    foreach (['subtotal', 'discount_amount', 'vat_amount', 'total_amount'] as $attribute) {
        expect($invoice->{$attribute})->toBeString("{$attribute} is not a decimal string")
            ->and($invoice->{$attribute})->toMatch('/^-?\d+\.\d{2}$/');
    }
});

test('a document line reports its unit money and its rates as decimals too', function () {
    $line = new InvoiceItem;
    $line->unit_price = 99.9;
    $line->discount_pct = 5;
    $line->vat_rate = 15;
    $line->discount_amount = 5.0;
    $line->vat_amount = 14.23;

    expect((string) $line->unit_price)->toMatch('/^-?\d+\.\d+$/')
        ->and((string) $line->vat_amount)->toMatch('/^-?\d+\.\d{2}$/');

    foreach (['unit_price', 'discount_pct', 'discount_amount', 'vat_rate', 'vat_amount'] as $attribute) {
        expect($line->{$attribute})->toBeString("InvoiceItem::{$attribute} is still a float");
    }
});

test('no repository concatenates a float into raw SQL', function () {
    $root = dirname(__DIR__, 3);

    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/modules'));

    foreach ($iterator as $file) {
        if ($file->getExtension() === 'php' && str_contains(Paths::normalise($file->getPathname()), '/Repositories/')) {
            $files[] = $file->getPathname();
        }
    }

    expect($files)->not->toBeEmpty('the repository scan found nothing');

    $offenders = [];

    foreach ($files as $file) {
        // Comments are stripped before matching, so the comment that explains why a pattern is
        // banned can never itself be the thing that trips the check.
        $source = mccCodeOnly((string) file_get_contents($file));

        // `DB::raw('total_sales + ' . (float) $x)` renders a float into SQL text. Injection is not
        // the risk — the cast sees to that — it is that the rendering depends on the `precision`
        // ini setting: at the default 14 significant digits a large money value silently loses its
        // cents, and at the extremes PHP switches to scientific notation. A bound parameter has
        // neither problem.
        if (preg_match('/DB::raw\(\s*[\'"][^\'"]*[\'"]\s*\.\s*\(float\)/', $source)) {
            $offenders[] = Paths::relativePath($file);
        }
    }

    expect($offenders)->toBe([]);
});

test('the raw-SQL scanner still sees code once comments are stripped', function () {
    // Guards the guard: a comment-stripping scanner that stripped too much would pass forever.
    $sample = <<<'PHP'
    <?php
    // DB::raw('x + ' . (float) $amount) is only prose here and must be ignored.
    $q = DB::raw('total + ' . (float) $amount);
    PHP;

    expect(mccCodeOnly($sample))->not->toContain('only prose here')
        ->and(preg_match('/DB::raw\(\s*[\'"][^\'"]*[\'"]\s*\.\s*\(float\)/', mccCodeOnly($sample)))->toBe(1);
});
