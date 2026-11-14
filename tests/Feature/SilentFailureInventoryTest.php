<?php

use Tests\Paths;

/**
 * An enforced inventory of silent failure.
 *
 * §4 P1.1 of the audit report has carried a count of empty `catch` blocks since the first pass,
 * and that count has been wrong at least twice — it was produced by grepping for one textual
 * spelling, `catch (\Throwable $e) {}`, which misses `catch(\Throwable $e){}` (no space) and
 * matches the pattern when it appears inside a comment or a docblock. Three of `AttendanceDaily
 * Repository`'s five sites were invisible to it, and two of the "hits" in `PosService` and in this
 * suite's own test files were prose.
 *
 * So the number now comes from PHP's tokenizer, and it is asserted rather than reported: adding an
 * empty catch anywhere in `modules/` or `app/` fails this test, and so does removing one without
 * updating the allowlist — the list cannot quietly drift away from the code.
 *
 * Migrations, seeders and factories are excluded deliberately. Their empty catches guard DDL that
 * may or may not already exist (`try { $table->index(...) } catch {}`), which is ordinary
 * idempotent-migration practice and not a swallowed application failure.
 */
const TOLERATED_EMPTY_CATCHES = [
    /*
     * All eight are best-effort housekeeping on a physical ZKTeco device: `$zk->enableDevice()` /
     * `disableDevice()`, several of them inside another catch block so the device is left usable
     * before the real error propagates. Throwing from that cleanup path would mask the original
     * failure, and there is nothing better to do if re-enabling the device itself fails. The
     * variable is named `$_`, which is the codebase's own signal for "deliberately ignored".
     */
    'modules/HRM/Services/Attendance/AttendanceSyncService.php' => 8,

    /*
     * Three `Carbon::parse($punch)->format('H:i')` calls on a punch string that may be malformed.
     * On failure the caller keeps the shift's expected time, which is a defensible fallback for one
     * bad row in a bulk daily recalculation. The two sibling catches in the same file that decided
     * late / early-out were NOT tolerated and now log: those silently resolved to "on time", which
     * fails in the employee's favour and skips a deduction.
     */
    'modules/HRM/Repositories/Attendance/AttendanceDailyRepository.php' => 3,
];

/**
 * Count catch blocks whose body holds no executable statement.
 *
 * Comments and docblocks are tokens the scanner skips, so a `catch (\Throwable $e) {}` quoted in
 * documentation — as this suite's own docblocks do — is not counted.
 */
function emptyCatchCount(string $file): int
{
    $tokens = token_get_all((string) file_get_contents($file));
    $n = count($tokens);
    $count = 0;

    for ($i = 0; $i < $n; $i++) {
        if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_CATCH) {
            continue;
        }

        // Step over the `catch (Type $e)` argument list.
        $j = $i + 1;
        $depth = 0;
        while ($j < $n) {
            if ($tokens[$j] === '(') {
                $depth++;
            } elseif ($tokens[$j] === ')') {
                $depth--;
                if ($depth === 0) {
                    $j++;
                    break;
                }
            }
            $j++;
        }

        $noise = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

        while ($j < $n && is_array($tokens[$j]) && in_array($tokens[$j][0], $noise, true)) {
            $j++;
        }

        if ($j >= $n || $tokens[$j] !== '{') {
            continue;
        }

        $k = $j + 1;
        $bodyDepth = 1;
        $hasStatement = false;

        while ($k < $n && $bodyDepth > 0) {
            $token = $tokens[$k];

            if ($token === '{') {
                $bodyDepth++;
            } elseif ($token === '}') {
                $bodyDepth--;
            } elseif ($bodyDepth === 1) {
                if (is_array($token)) {
                    if (! in_array($token[0], $noise, true)) {
                        $hasStatement = true;
                    }
                } elseif (trim($token) !== '' && $token !== ';') {
                    $hasStatement = true;
                }
            }

            $k++;
        }

        if (! $hasStatement) {
            $count++;
        }
    }

    return $count;
}

/**
 * Every production PHP file in scope, as `path => empty-catch count`.
 *
 * @return array<string, int>
 */
function silentFailureInventory(): array
{
    $root = dirname(__DIR__, 2);
    // Compared against a forward-slashed relative path, so this list works on every OS.
    $excluded = ['Database/Migrations', 'Database/Seeders', 'Database/Factories'];
    $inventory = [];
    $scanned = 0;

    foreach (['modules', 'app'] as $base) {
        $directory = $root.'/'.$base;

        if (! is_dir($directory)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file->isDir() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = Paths::relativePath($file->getPathname());

            foreach ($excluded as $skip) {
                if (str_contains($relative, $skip)) {
                    continue 2;
                }
            }

            $scanned++;
            $count = emptyCatchCount($file->getPathname());

            if ($count > 0) {
                $inventory[$relative] = $count;
            }
        }
    }

    // A wrong root makes the scan find nothing and every assertion below pass vacuously.
    expect($scanned)->toBeGreaterThan(500, "only {$scanned} PHP files were scanned — the root or the exclusions are wrong");

    return $inventory;
}

test('no empty catch block exists outside the documented allowlist', function () {
    $inventory = silentFailureInventory();

    $unexpected = [];

    foreach ($inventory as $file => $count) {
        $tolerated = TOLERATED_EMPTY_CATCHES[$file] ?? 0;

        if ($count > $tolerated) {
            $unexpected[] = "{$file}: {$count} empty catch block(s), {$tolerated} tolerated — ".
                'either handle the failure, log it, or add it to TOLERATED_EMPTY_CATCHES with a reason';
        }
    }

    expect($unexpected)->toBe([], implode("\n", $unexpected));
});

test('the allowlist has not drifted away from the code', function () {
    $inventory = silentFailureInventory();

    $stale = [];

    foreach (TOLERATED_EMPTY_CATCHES as $file => $tolerated) {
        $actual = $inventory[$file] ?? 0;

        if ($actual !== $tolerated) {
            $stale[] = "{$file}: allowlist says {$tolerated}, code has {$actual} — ".
                'update the entry (and its stated reason) to match';
        }
    }

    expect($stale)->toBe([], implode("\n", $stale));
});

test('the money paths that used to swallow their failures no longer do', function () {
    // Each of these was an empty catch that a following line then acted on. They now log or
    // rethrow; assert the replacement is present, so reverting one is caught here too.
    $expected = [
        'modules/PURCHASE/Services/VendorBillService.php' => 'left posted',
        'modules/PURCHASE/Services/GoodsReceiptService.php' => 'GRNI journal for',
        // Re-pinned at item 39, and the change is deliberate: a counter sale no longer creates its own
        // invoice and journal, so there is no invoice journal to reverse on void — the day-end posting
        // owns the ledger. The guard that survives the rewrite is item 26's restock wrap, which is the
        // one that used to leave a sale marked void with its stock still off the shelf.
        'modules/SALES/Services/PosService.php' => 'the stock could not be returned to the shelf',
        'modules/SALES/Services/OrderService.php' => 'Could not release the reserved stock',
        'modules/PAYROLL/Services/PayrollBankAdviceService.php' => 'could not update the slip for',
        /*
         * Both halves of finding 35, pinned separately because either one on its own is a regression.
         * `PayrollPaymentPostingService` used to wrap the whole posting in `catch (\Throwable)` and
         * log it, which meant a slip could be paid, its PF ledger written, and its bank journal simply
         * absent — for every slip after the first, because `unique(salary_sheet_id, posting_type)`
         * refused the second one. It now rethrows. `MarkPayrollSlipPaymentAction` still does not block
         * HR — that part of the old comment was right and stays — but it writes the reason onto the
         * slip, so the divergence is a row somebody can query instead of a log line nobody reads.
         * Reverting the service to a swallow, or the action to a bare Log::warning, fails here.
         */
        'modules/PAYROLL/Services/PayrollPaymentPostingService.php' => 'could not be posted',
        'modules/PAYROLL/Actions/MarkPayrollSlipPaymentAction.php' => 'payment_posting_error',
        'modules/PAYROLL/Actions/ProcessFinalSettlementAction.php' => 'deducting nothing',
        'modules/HRM/Services/MovementService.php' => 'mirrored into payroll',
        'modules/HRM/Repositories/Attendance/AttendanceDailyRepository.php' => 'Late detection skipped',
    ];

    $root = dirname(__DIR__, 2);
    $missing = [];

    foreach ($expected as $file => $needle) {
        $source = (string) file_get_contents($root.'/'.$file);

        if (! str_contains($source, $needle)) {
            $missing[] = "{$file} no longer carries '{$needle}'";
        }
    }

    expect($missing)->toBe([], implode("\n", $missing));
});
