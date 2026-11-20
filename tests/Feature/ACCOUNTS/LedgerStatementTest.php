<?php

/**
 * P39 — the two ways the ledger reports told the wrong story.
 *
 * Both bugs were found by driving the running system, not by reading it:
 *
 *   1. **A phantom posting.** `trialBalance()` summed `acc_account_ledger` without asking whether the
 *      journal behind each row still existed. A ledger row whose journal had been removed kept moving
 *      every report — 673,000.00 of debit that no human could find anywhere in the UI, because the
 *      journal it came from was gone. The fix joins the journal and requires `status = posted`, so an
 *      orphan row is excluded instead of silently counted.
 *
 *   2. **A credit balance read as a positive asset.** `profitAndLoss()` and `balanceSheet()` wrapped
 *      every balance in `abs()`. `balance` is already signed against the account's nature, so an
 *      overdrawn bank account (a negative asset) was reported as a positive one: the sheet claimed
 *      780,283.26 of assets that did not exist, and announced an imbalance of exactly twice the
 *      overdraft. Signed sums make the sheet balance when the ledger balances.
 */

use Modules\ACCOUNTS\Models\Account;
use Modules\ACCOUNTS\Models\AccountLedger;
use Modules\ACCOUNTS\Models\FiscalYear;
use Modules\ACCOUNTS\Models\Journal;
use Modules\ACCOUNTS\Services\JournalService;
use Modules\ACCOUNTS\Services\LedgerService;
use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\CORE\Models\User;

const LSR_START = '2026-07-01';
const LSR_END = '2027-06-30';
const LSR_TODAY = '2026-11-05';

function lsrAdmin(): User
{
    test()->seed(SuperAdminSeeder::class);
    $admin = User::where('email', config('core.super_admin.email', 'admin@example.com'))->first();
    expect($admin)->not->toBeNull('SuperAdminSeeder produced no admin user');
    test()->actingAs($admin);

    return $admin;
}

function lsrFiscalYear(): FiscalYear
{
    return FiscalYear::create([
        'name' => 'FY 2026-27', 'code' => 'FY2627-LSR',
        'start_date' => LSR_START, 'end_date' => LSR_END, 'status' => 'open',
    ]);
}

/** A leaf posting account with an explicit nature, so an overdrawn asset can be expressed. */
function lsrAccount(string $code, string $name, string $type, string $nature): Account
{
    return Account::create([
        'code' => $code, 'name' => $name, 'account_type' => $type, 'nature' => $nature,
        'level' => 1, 'is_group' => false, 'is_posting' => true, 'is_active' => true,
        'currency' => 'BDT', 'opening_balance' => 0,
    ]);
}

function lsrPost(array $lines, float $amount): Journal
{
    $svc = app(JournalService::class);
    $mapped = [];
    foreach ($lines as $i => $l) {
        $mapped[] = [
            'account_id' => $l['id'],
            'debit' => $i === 0 ? $amount : null,
            'credit' => $i === 0 ? null : $amount,
            'description' => 'LSR fixture',
        ];
    }

    $journal = $svc->create([
        'journal_date' => LSR_TODAY, 'type' => 'JV', 'narration' => 'LSR fixture',
        'lines' => $mapped,
    ]);

    // The repository returns the model it built, and `status` comes from the column default, so the
    // in-memory instance does not carry it. Re-read before asking whether it is a draft.
    $journal = $journal->fresh();

    return $journal->isDraft() ? $svc->post($journal) : $journal;
}

test('a ledger row is only counted while its journal is still posted', function () {
    lsrAdmin();
    lsrFiscalYear();
    $expense = lsrAccount('5210', 'Salary Expense', 'expense', 'debit');
    $payable = lsrAccount('2121', 'Salary Payable', 'liability', 'credit');

    $posted = lsrPost([$expense, $payable], 500.00);
    expect((float) app(LedgerService::class)->trialBalance(LSR_TODAY)->sum('total_debit'))->toBe(500.0);

    // The shape a half-removed posting leaves behind. A foreign key stops the worst case outright —
    // SQLite refuses a ledger row whose journal does not exist — but a journal that is still there
    // and has stopped being posted (draft after an edit, or voided) leaves its ledger rows in place,
    // and those rows used to keep moving every report. 673,000.00 of debit was found on this shape
    // in the running system, visible in no screen.
    Journal::whereKey($posted->id)->update(['status' => 'void']);
    expect(AccountLedger::where('journal_id', $posted->id)->count())->toBe(2);   // rows survive

    $tb = app(LedgerService::class)->trialBalance(LSR_TODAY);
    expect((float) $tb->sum('total_debit'))->toBe(0.0)                           // but are not counted
        ->and((float) $tb->sum('total_credit'))->toBe(0.0);

    // A second, live posting is still counted, so the join is not silently dropping everything.
    lsrPost([$expense, $payable], 250.00);
    expect((float) app(LedgerService::class)->trialBalance(LSR_TODAY)->sum('total_debit'))->toBe(250.0);
});

test('an overdrawn bank account reduces assets instead of inflating them', function () {
    lsrAdmin();
    lsrFiscalYear();
    $bank = lsrAccount('100201', 'Prime Bank — Main', 'asset', 'debit');
    $expense = lsrAccount('5210', 'Salary Expense', 'expense', 'debit');
    $payable = lsrAccount('2121', 'Salary Payable', 'liability', 'credit');

    // Salary accrual: Dr expense 1000 / Cr payable 1000
    lsrPost([$expense, $payable], 1000.00);

    // Pay the bank directly, more than it holds, so the asset goes credit-side (overdrawn).
    $svc = app(JournalService::class);
    $draft = $svc->create([
        'journal_date' => LSR_TODAY, 'type' => 'PV', 'narration' => 'Overdrawn payment',
        'lines' => [
            ['account_id' => $payable->id, 'debit' => 1400.00, 'credit' => null, 'description' => 'Settle'],
            ['account_id' => $bank->id, 'debit' => null, 'credit' => 1400.00, 'description' => 'Bank out'],
        ],
    ]);
    $svc->post($draft->fresh());

    $bs = app(LedgerService::class)->balanceSheet(LSR_TODAY);

    // The bank is 1,400 overdrawn, so assets are negative — abs() would have reported +1,400 and
    // hidden the overdraft behind a positive number that looks healthy.
    expect($bs['assets'])->toEqualWithDelta(-1400.0, 0.01)
        ->and($bs['assets'])->toBeLessThan(0.0)
        // The payable was settled 400 more than it was raised, so it holds a debit balance: a
        // negative liability, not a positive one.
        ->and($bs['liabilities'])->toEqualWithDelta(-400.0, 0.01)
        // A = L + E must still hold once the signs are kept (E is 0: no income, expense is 1,000).
        ->and($bs['assets'])->toEqualWithDelta($bs['liabilities'] + $bs['total_equity'], 0.01);
});

test('the balance sheet balances whenever the ledger balances', function () {
    lsrAdmin();
    lsrFiscalYear();
    $cash = lsrAccount('1001', 'Cash in Hand', 'asset', 'debit');
    $sales = lsrAccount('4001', 'Sales Revenue', 'income', 'credit');
    $cogs = lsrAccount('5001', 'Cost of Goods Sold', 'expense', 'debit');

    lsrPost([$cash, $sales], 2500.00);   // sale for cash
    lsrPost([$cogs, $cash], 900.00);     // cost of the goods

    $svc = app(LedgerService::class);
    $bs = $svc->balanceSheet(LSR_TODAY);
    $pl = $svc->profitAndLoss(null, LSR_TODAY);

    expect($pl['net_profit'])->toEqualWithDelta(1600.0, 0.01)
        ->and($bs['assets'])->toEqualWithDelta(1600.0, 0.01)
        ->and($bs['balanced'])->toBeTrue();
});
