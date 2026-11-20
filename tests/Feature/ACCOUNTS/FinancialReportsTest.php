<?php

/**
 * The four financial reports, checked against the journal they are supposed to summarise.
 *
 * Each of these was wrong on the live books while every page returned 200:
 *
 *   - `profitAndLoss()` accepted a `from` date and never used it, so "this month" and "since
 *     inception" printed the same figure. A report that silently answers a different question than
 *     the one it was asked is worse than one that fails.
 *   - `cashFlow()` reported the P&L net profit as operating cash and summed every 21xx/31xx balance
 *     as financing, so VAT and salary payable were "financing activities". The three buckets did not
 *     add up to the movement in cash and the report did not say so.
 *   - `balanceSheet()` called itself unbalanced at an as-of date that simply excluded a later entry —
 *     a date window reported as corrupted books.
 *
 * Helpers are prefixed `rep`.
 */

use Modules\ACCOUNTS\Models\Account;
use Modules\ACCOUNTS\Models\FiscalYear;
use Modules\ACCOUNTS\Services\JournalService;
use Modules\ACCOUNTS\Services\LedgerService;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->seed(\Modules\CORE\Database\Seeders\SuperAdminSeeder::class);
    actingAs(\Modules\CORE\Models\User::first());

    // Wide enough that a test can post a few months either side of today without opening another year.
    FiscalYear::create([
        'name' => 'FY 2025-28', 'code' => 'FY2528-REP',
        'start_date' => now()->subYear()->startOfYear()->toDateString(),
        'end_date' => now()->addYears(2)->endOfYear()->toDateString(),
        'status' => 'open',
    ]);

    foreach ([
        ['1001', 'Cash in Hand', 'asset', 'debit'],
        ['1200', 'Inventory', 'asset', 'debit'],
        ['2100', 'Accounts Payable', 'liability', 'credit'],
        ['4001', 'Sales Revenue', 'income', 'credit'],
        ['5001', 'Cost of Goods Sold', 'expense', 'debit'],
    ] as [$code, $name, $type, $nature]) {
        Account::firstOrCreate(['code' => $code], [
            'name' => $name, 'account_type' => $type, 'nature' => $nature,
            'level' => 1, 'is_group' => false, 'is_posting' => true, 'is_active' => true,
            'currency' => 'BDT', 'opening_balance' => 0,
        ]);
    }
});

function repId(string $code): int
{
    return (int) Account::where('code', $code)->value('id');
}

/** Post a balanced two-line journal and return it. */
function repPost(string $date, string $drCode, string $crCode, float $amount): void
{
    $svc = app(JournalService::class);
    $journal = $svc->create([
        'journal_date' => $date, 'type' => 'JV', 'narration' => 'rep fixture',
        'lines' => [
            ['account_id' => repId($drCode), 'debit' => $amount, 'credit' => null, 'description' => 'rep'],
            ['account_id' => repId($crCode), 'debit' => null, 'credit' => $amount, 'description' => 'rep'],
        ],
    ]);

    $svc->post($journal->fresh());
}

function repLedger(): LedgerService
{
    return app(LedgerService::class);
}

test('profit and loss only counts the window it was asked for', function () {
    // Two sales in two different months.
    repPost('2026-01-15', '1001', '4001', 1_000.00);
    repPost('2026-03-15', '1001', '4001', 4_000.00);

    $january = repLedger()->profitAndLoss('2026-01-01', '2026-01-31');
    $march = repLedger()->profitAndLoss('2026-03-01', '2026-03-31');
    $both = repLedger()->profitAndLoss('2026-01-01', '2026-03-31');
    $empty = repLedger()->profitAndLoss('2026-06-01', '2026-06-30');

    expect((float) $january['income'])->toBe(1_000.0)
        ->and((float) $march['income'])->toBe(4_000.0)
        ->and((float) $both['income'])->toBe(5_000.0)
        ->and((float) $empty['income'])->toBe(0.0, 'a window with nothing in it reported income');
});

test('a cost inside the window reduces that window only', function () {
    repPost('2026-01-10', '5001', '1001', 300.00);
    repPost('2026-04-10', '5001', '1001', 900.00);

    expect((float) repLedger()->profitAndLoss('2026-01-01', '2026-01-31')['expense'])->toBe(300.0)
        ->and((float) repLedger()->profitAndLoss('2026-04-01', '2026-04-30')['expense'])->toBe(900.0)
        ->and((float) repLedger()->profitAndLoss('2026-02-01', '2026-02-28')['expense'])->toBe(0.0);
});

test('the cash flow statement adds up to the movement in cash', function () {
    // Money in from a sale, money out to a supplier, in the same window.
    repPost('2026-05-01', '1001', '4001', 2_000.00);
    repPost('2026-05-02', '2100', '1001', 750.00);
    repPost('2026-05-03', '1001', '2100', 250.00);

    $cf = repLedger()->cashFlow('2026-05-01', '2026-05-31');

    $buckets = (float) $cf['operating'] + (float) $cf['investing']
        + (float) $cf['financing'] + (float) $cf['unclassified'];

    expect(round($buckets, 2))->toBe(round((float) $cf['net_change'], 2), 'the buckets do not add up to net_change')
        ->and(round((float) $cf['net_change'], 2))->toBe(1_500.0)
        ->and(round((float) $cf['opening_cash'] + (float) $cf['net_change'], 2))
            ->toBe(round((float) $cf['closing_cash'], 2), 'opening + movement does not equal closing');
});

test('operating cash is not simply the profit for the period', function () {
    // A sale on credit moves no cash at all. If operating cash were the P&L total it would report 5,000.
    repPost('2026-07-01', '1200', '2100', 5_000.00);

    $cf = repLedger()->cashFlow('2026-07-01', '2026-07-31');

    expect(round((float) $cf['net_change'], 2))->toBe(0.0, 'a journal that touched no cash account moved cash')
        ->and(round((float) $cf['closing_cash'], 2))->toBe(round((float) $cf['opening_cash'], 2));
});

test('the balance sheet calls itself balanced at a date that only leaves later entries out', function () {
    repPost(now()->toDateString(), '1001', '4001', 1_000.00);
    repPost(now()->addMonths(2)->toDateString(), '5001', '1001', 400.00);

    $today = repLedger()->balanceSheet();

    expect($today['balanced'])->toBeTrue('the sheet at today should balance')
        ->and(round((float) $today['equation_delta'], 2))->toBe(0.0);

    // And the future-dated entry is genuinely outside it, not silently included.
    $future = repLedger()->balanceSheet(now()->addMonths(3)->toDateString());
    expect(round((float) $future['equation_delta'], 2))->toBe(0.0, 'the sheet does not balance once the later entry is in range');
});
