<?php

/**
 * P3.1 / §7 item 2 — the ACCOUNTS posting safety net.
 *
 * The audit recorded that ACCOUNTS posting had no test coverage beyond one end-to-end pass, and put
 * "add the missing domain tests" ahead of the float→decimal migration for exactly that reason: money
 * types cannot be changed safely while nothing pins what the money is supposed to do. This file is
 * that net. It covers the three invariants the whole ledger rests on:
 *
 *   1. **A journal is balanced.** Three places in the code decided what that means — the service's
 *      `assertBalanced()`, `StoreJournalRequest::withValidator()` and `Journal::isBalanced()` — and
 *      they did not agree, which is what tests 4 and 6 are about.
 *   2. **The ledger is a running balance.** `post()` writes `balance_after` per line from the previous
 *      row, so a second posting against the same account must continue from the first, and a void must
 *      unwind exactly.
 *   3. **Nothing posts into a closed period.** `assertFiscalOpen()` is called from create, update,
 *      post and createFromDto — test 9 asks whether it is called from every path that writes.
 *
 * Everything here drives `JournalService` directly rather than through HTTP, because the service is
 * the boundary other modules post through (`AccountsPostingProvider`) and the controller adds its own
 * recomputation on top, which would mask exactly the gaps being probed.
 *
 * Helper names are prefixed `jpd` because Pest loads every Feature file into one process, and
 * `journalAdmin()` / `journalAccount()` / `journalFiscalYear()` are already taken by
 * JournalDraftGuardTest.
 *
 * Deliberate limitation: SQLite ignores `lockForUpdate()`, so `post()`'s account lock and the
 * concurrency it exists for cannot be exercised here. Test 2 pins the invariant the lock protects —
 * sequential postings accumulate — which is what the lock guarantees on MySQL, and the CI matrix runs
 * MySQL. True parallel-posting coverage needs a driver that honours row locks and is not claimed here.
 */

use Modules\ACCOUNTS\Models\Account;
use Modules\ACCOUNTS\Models\AccountLedger;
use Modules\ACCOUNTS\Models\FiscalPeriod;
use Modules\ACCOUNTS\Models\FiscalYear;
use Modules\ACCOUNTS\Models\Journal;
use Modules\ACCOUNTS\Models\Payable;
use Modules\ACCOUNTS\Models\Receivable;
use Modules\ACCOUNTS\Services\JournalService;
use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\CORE\Models\User;
use Modules\PARTNER\Models\Partner;

/** The fixture year covers today, because `void()` dates its reversal at now(). */
const JPD_START = '2026-07-01';
const JPD_END = '2027-06-30';
const JPD_TODAY = '2026-11-05';

function jpdAdmin(): User
{
    test()->seed(SuperAdminSeeder::class);

    $admin = User::where('email', config('core.super_admin.email', 'admin@example.com'))->first();
    expect($admin)->not->toBeNull('SuperAdminSeeder produced no admin user');

    test()->actingAs($admin);

    return $admin;
}

function jpdService(): JournalService
{
    return app(JournalService::class);
}

/** A leaf posting account, active and not a group header, per assertAccountsPosting(). */
function jpdAccount(string $code, string $name, string $nature): Account
{
    return Account::create([
        'code' => $code,
        'name' => $name,
        'account_type' => $nature === 'debit' ? 'asset' : 'income',
        'nature' => $nature,
        'level' => 1,
        'is_group' => false,
        'is_posting' => true,
        'is_active' => true,
        'currency' => 'BDT',
        'opening_balance' => 0,
    ]);
}

/** The two accounts every fixture journal posts between: cash (debit) and sales (credit). */
function jpdPair(): array
{
    return [jpdAccount('1001', 'Cash in Hand', 'debit'), jpdAccount('4001', 'Sales Revenue', 'credit')];
}

function jpdFiscalYear(string $status = 'open'): FiscalYear
{
    return FiscalYear::create([
        'name' => 'FY 2026-27',
        'code' => 'FY2627-JPD',
        'start_date' => JPD_START,
        'end_date' => JPD_END,
        'status' => $status,
    ]);
}

/** Two lines: $debit on the cash side, $credit on the sales side, one side null as the UI sends it. */
function jpdLines(array $pair, float $debit, float $credit): array
{
    return [
        ['account_id' => $pair[0]->id, 'debit' => $debit, 'credit' => null, 'description' => 'Cash side'],
        ['account_id' => $pair[1]->id, 'debit' => null, 'credit' => $credit, 'description' => 'Sales side'],
    ];
}

/** Header totals derived from lines the way JournalController@store does. */
function jpdPayload(array $pair, float $debit, float $credit, string $date = JPD_TODAY, string $type = 'JV'): array
{
    $lines = jpdLines($pair, $debit, $credit);

    return [
        'journal_date' => $date,
        'type' => $type,
        'reference_no' => 'JPD-REF',
        'narration' => 'Domain safety net fixture',
        'total_debit' => array_sum(array_map(fn ($l) => (float) ($l['debit'] ?? 0), $lines)),
        'total_credit' => array_sum(array_map(fn ($l) => (float) ($l['credit'] ?? 0), $lines)),
        'status' => 'draft',
        'lines' => $lines,
    ];
}

/**
 * Create a balanced draft and post it, returning the posted journal.
 *
 * $pair is passed in rather than built here whenever the test also needs the account ids: `code` is
 * UNIQUE on acc_accounts, so a second jpdPair() inside one test is a constraint violation, not a
 * second fixture.
 */
function jpdPosted(float $amount = 250.50, ?array $pair = null): Journal
{
    $pair ??= jpdPair();

    return jpdService()->post(jpdService()->create(jpdPayload($pair, $amount, $amount)));
}

function jpdLedgerFor(Journal $journal, int $accountId): ?AccountLedger
{
    return AccountLedger::where('journal_id', $journal->id)
        ->where('account_id', $accountId)
        ->orderByDesc('id')
        ->first();
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. The ledger is a running balance
// ─────────────────────────────────────────────────────────────────────────────

test('a balanced journal posts and writes one ledger row per line', function () {
    jpdAdmin();
    jpdFiscalYear();
    [$cash, $sales] = jpdPair();

    $posted = jpdPosted(250.50, [$cash, $sales]);

    expect($posted->status)->toBe('posted')
        ->and($posted->posted_at)->not->toBeNull()
        ->and(AccountLedger::where('journal_id', $posted->id)->count())->toBe(2);

    // Normal balance: cash is debit-nature so a debit raises it; sales is credit-nature so a credit raises it.
    expect((float) jpdLedgerFor($posted, $cash->id)->balance_after)->toBe(250.5)
        ->and((float) jpdLedgerFor($posted, $sales->id)->balance_after)->toBe(250.5);
});

test('a second posting against the same account continues the running balance', function () {
    jpdAdmin();
    jpdFiscalYear();
    [$cash, $sales] = jpdPair();

    $first = jpdPosted(100.00, [$cash, $sales]);
    $second = jpdPosted(50.25, [$cash, $sales]);

    // The invariant Account::lockForUpdate() exists to protect: balances accumulate rather than
    // both postings starting from zero.
    expect((float) jpdLedgerFor($second, $cash->id)->balance_after)->toBe(150.25)
        ->and((float) jpdLedgerFor($second, $sales->id)->balance_after)->toBe(150.25)
        ->and((float) jpdLedgerFor($first, $cash->id)->balance_after)->toBe(100.0);
});

test('the journal number is generated per type and increments', function () {
    jpdAdmin();
    jpdFiscalYear();
    $pair = jpdPair();

    $one = jpdService()->create(jpdPayload($pair, 10, 10, JPD_TODAY, 'JV'));
    $two = jpdService()->create(jpdPayload($pair, 10, 10, JPD_TODAY, 'JV'));
    $three = jpdService()->create(jpdPayload($pair, 10, 10, JPD_TODAY, 'BV'));

    expect($one->journal_no)->toStartWith('JV-')
        ->and($two->journal_no)->not->toBe($one->journal_no)
        ->and($three->journal_no)->toStartWith('BV-');
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. What "balanced" means, and whether every writer agrees
// ─────────────────────────────────────────────────────────────────────────────

test('an unbalanced journal is refused before it is written', function () {
    jpdAdmin();
    jpdFiscalYear();
    $pair = jpdPair();

    expect(fn () => jpdService()->create(jpdPayload($pair, 100.00, 99.00)))
        ->toThrow(DomainException::class, 'not balanced');

    expect(Journal::query()->count())->toBe(0);
});

test('a difference that survives to the stored cent is refused when the draft is created', function () {
    jpdAdmin();
    jpdFiscalYear();
    $pair = jpdPair();

    // create() tolerated any difference up to 0.01 while post() demanded exact agreement at two
    // decimal places through Journal::isBalanced(). Between those two rules sits a difference that
    // is accepted as a draft and then refused forever at posting time — an unpostable journal the
    // system had already declared balanced. 100.009 is that case: within the tolerance, but it
    // stores as 100.01.
    //
    // Sub-cent line amounts are not a UI typo, they are what an unrounded unit price times a
    // quantity, a VAT split or a discount allocation produces — which is precisely the exposure
    // P1.2 (float money) describes.
    expect(fn () => jpdService()->create(jpdPayload($pair, 100.000, 100.009)))
        ->toThrow(DomainException::class);

    expect(Journal::query()->count())->toBe(0, 'an unpostable draft was persisted');
});

test('float noise from summing lines is not mistaken for an imbalance', function () {
    jpdAdmin();
    jpdFiscalYear();
    [$cash, $sales] = jpdPair();

    // 33.33 + 33.33 + 33.34 does not sum to exactly 100.0 in binary floating point.
    $lines = [
        ['account_id' => $cash->id, 'debit' => 33.33, 'credit' => null],
        ['account_id' => $cash->id, 'debit' => 33.33, 'credit' => null],
        ['account_id' => $cash->id, 'debit' => 33.34, 'credit' => null],
        ['account_id' => $sales->id, 'debit' => null, 'credit' => 100.00],
    ];

    $journal = jpdService()->create([
        'journal_date' => JPD_TODAY, 'type' => 'JV', 'status' => 'draft',
        'total_debit' => array_sum(array_map(fn ($l) => (float) ($l['debit'] ?? 0), $lines)),
        'total_credit' => array_sum(array_map(fn ($l) => (float) ($l['credit'] ?? 0), $lines)),
        'lines' => $lines,
    ]);

    // And it must be postable — the whole point of tolerating the noise.
    expect(jpdService()->post($journal)->status)->toBe('posted');
});

test('a zero-total journal is refused', function () {
    jpdAdmin();
    jpdFiscalYear();
    $pair = jpdPair();

    expect(fn () => jpdService()->create(jpdPayload($pair, 0.0, 0.0)))
        ->toThrow(DomainException::class, 'cannot be zero');
});

test('replacing a draft\'s lines carries the header totals with them', function () {
    jpdAdmin();
    jpdFiscalYear();
    $pair = jpdPair();

    $journal = jpdService()->create(jpdPayload($pair, 100.00, 100.00));

    $updated = jpdService()->update($journal, ['lines' => jpdLines($pair, 300.00, 300.00)]);

    // JournalController@update recomputes the totals itself, so the web form hides this; any other
    // caller — including the posting provider other modules use — got a journal whose header said
    // 100.00 while its lines summed to 300.00, and post() only ever looked at the header.
    expect((float) $updated->total_debit)->toBe(300.0)
        ->and((float) $updated->total_credit)->toBe(300.0)
        ->and((float) $updated->lines->sum('debit'))->toBe(300.0);
});

test('posting refuses a journal whose lines do not agree with its header', function () {
    jpdAdmin();
    jpdFiscalYear();
    [$cash, $sales] = jpdPair();

    // Built directly, bypassing the service's create() guards, the way an internal caller or a
    // future import could: a header that balances over lines that do not.
    $journal = Journal::create([
        'journal_no' => 'JV-JPD-MISMATCH', 'journal_date' => JPD_TODAY, 'type' => 'JV',
        'total_debit' => 100.00, 'total_credit' => 100.00, 'status' => 'draft',
    ]);
    $journal->lines()->create(['account_id' => $cash->id, 'debit' => 400.00, 'credit' => 0, 'line_no' => 1]);
    $journal->lines()->create(['account_id' => $sales->id, 'debit' => 0, 'credit' => 100.00, 'line_no' => 2]);

    expect(fn () => jpdService()->post($journal->fresh(['lines'])))
        ->toThrow(DomainException::class);

    expect(AccountLedger::where('journal_id', $journal->id)->count())->toBe(0)
        ->and($journal->fresh()->status)->toBe('draft');
});

test('a group header and an inactive account are both refused', function () {
    jpdAdmin();
    jpdFiscalYear();

    $group = Account::create([
        'code' => '1000', 'name' => 'Current Assets', 'account_type' => 'asset', 'nature' => 'debit',
        'level' => 1, 'is_group' => true, 'is_posting' => false, 'is_active' => true,
    ]);
    $inactive = Account::create([
        'code' => '1099', 'name' => 'Closed Suspense', 'account_type' => 'asset', 'nature' => 'debit',
        'level' => 1, 'is_group' => false, 'is_posting' => true, 'is_active' => false,
    ]);
    $sales = jpdAccount('4001', 'Sales Revenue', 'credit');

    expect(fn () => jpdService()->create([
        'journal_date' => JPD_TODAY, 'type' => 'JV', 'status' => 'draft',
        'total_debit' => 10.0, 'total_credit' => 10.0,
        'lines' => [
            ['account_id' => $group->id, 'debit' => 10.0, 'credit' => null],
            ['account_id' => $sales->id, 'debit' => null, 'credit' => 10.0],
        ],
    ]))->toThrow(DomainException::class, 'group header');

    expect(fn () => jpdService()->create([
        'journal_date' => JPD_TODAY, 'type' => 'JV', 'status' => 'draft',
        'total_debit' => 10.0, 'total_credit' => 10.0,
        'lines' => [
            ['account_id' => $inactive->id, 'debit' => 10.0, 'credit' => null],
            ['account_id' => $sales->id, 'debit' => null, 'credit' => 10.0],
        ],
    ]))->toThrow(DomainException::class, 'inactive');
});

// ─────────────────────────────────────────────────────────────────────────────
// 3. Voiding unwinds exactly
// ─────────────────────────────────────────────────────────────────────────────

test('voiding a posted journal unwinds the ledger exactly', function () {
    jpdAdmin();
    jpdFiscalYear();
    [$cash, $sales] = jpdPair();

    $posted = jpdPosted(250.50, [$cash, $sales]);

    expect((float) jpdLedgerFor($posted, $cash->id)->balance_after)->toBe(250.5);

    $voided = jpdService()->void($posted, 'entered against the wrong invoice');

    expect($voided->status)->toBe('void')
        ->and($voided->void_reason)->toBe('entered against the wrong invoice')
        ->and($voided->voided_at)->not->toBeNull();

    // The reversal is its own posted journal with the sides swapped.
    $reversal = Journal::where('source_type', 'journal_void')->where('source_id', $posted->id)->first();

    expect($reversal)->not->toBeNull()
        ->and($reversal->status)->toBe('posted')
        ->and((float) $reversal->total_debit)->toBe(250.5)
        ->and((float) $reversal->total_credit)->toBe(250.5);

    // And the running balance is back to where it started.
    expect((float) jpdLedgerFor($reversal, $cash->id)->balance_after)->toBe(0.0)
        ->and((float) jpdLedgerFor($reversal, $sales->id)->balance_after)->toBe(0.0);
});

test('only a posted journal can be voided', function () {
    jpdAdmin();
    jpdFiscalYear();
    $pair = jpdPair();

    $draft = jpdService()->create(jpdPayload($pair, 10.00, 10.00));

    expect(fn () => jpdService()->void($draft, 'not posted yet'))->toThrow(DomainException::class);
});

test('voiding is refused when the reversal would land in a closed period', function () {
    jpdAdmin();
    $fy = jpdFiscalYear();
    $posted = jpdPosted(120.00);

    // void() dates its reversal at now(), so it writes into whatever period covers today. It was the
    // only writing path in JournalService that never consulted assertFiscalOpen().
    $fy->update(['status' => 'closed']);

    expect(fn () => jpdService()->void($posted->fresh(['lines']), 'year end closed meanwhile'))
        ->toThrow(DomainException::class);

    expect(Journal::where('source_type', 'journal_void')->count())->toBe(0)
        ->and($posted->fresh()->status)->toBe('posted');
});

// ─────────────────────────────────────────────────────────────────────────────
// 4. The fiscal calendar gate
// ─────────────────────────────────────────────────────────────────────────────

test('posting is refused by the state of the fiscal calendar', function (string $status, string $expectation) {
    jpdAdmin();
    jpdFiscalYear($status);
    $pair = jpdPair();

    expect(fn () => jpdService()->create(jpdPayload($pair, 50.00, 50.00)))
        ->toThrow(DomainException::class, $expectation);
})->with([
    'a closed year' => ['closed', 'closed'],
    'a locked year' => ['locked', 'locked'],
]);

test('a closed period blocks posting even while its year is open', function () {
    jpdAdmin();
    $fy = jpdFiscalYear('open');

    FiscalPeriod::create([
        'fiscal_year_id' => $fy->id, 'name' => 'November 2026', 'month_no' => 5,
        'start_date' => '2026-11-01', 'end_date' => '2026-11-30', 'status' => 'closed',
    ]);

    $pair = jpdPair();

    expect(fn () => jpdService()->create(jpdPayload($pair, 50.00, 50.00, '2026-11-15')))
        ->toThrow(DomainException::class, 'closed');
});

test('a date no fiscal year covers is refused', function () {
    jpdAdmin();
    jpdFiscalYear();
    $pair = jpdPair();

    expect(fn () => jpdService()->create(jpdPayload($pair, 50.00, 50.00, '2019-03-04')))
        ->toThrow(DomainException::class, 'No fiscal year covers');
});

test('the fiscal gate can be switched off for a deployment still building its calendar', function () {
    jpdAdmin();
    config(['accounts.posting.require_fiscal_open' => false]);

    $pair = jpdPair();

    $journal = jpdService()->create(jpdPayload($pair, 50.00, 50.00, '2019-03-04'));

    expect($journal->exists)->toBeTrue();
});

test('the first and last day of a fiscal year are both inside it', function (string $date) {
    jpdAdmin();
    jpdFiscalYear();
    $pair = jpdPair();

    // assertFiscalOpen() compares a Y-m-d string against columns carrying a `date` cast. How that
    // serialises is driver-dependent — P1.5 — and the boundary is where the two representations
    // disagree: a journal dated exactly on the year's first day is the one that falls out.
    $journal = jpdService()->create(jpdPayload($pair, 75.00, 75.00, $date));

    expect($journal->exists)->toBeTrue()
        ->and(jpdService()->post($journal)->status)->toBe('posted');
})->with([
    'the first day' => [JPD_START],
    'the last day' => [JPD_END],
    'a mid-year day' => [JPD_TODAY],
]);

// ─────────────────────────────────────────────────────────────────────────────
// 5. Payable and receivable side effects
// ─────────────────────────────────────────────────────────────────────────────

test('posting a bill voucher against a vendor opens a payable', function () {
    jpdAdmin();
    jpdFiscalYear();
    $pair = jpdPair();

    $vendor = Partner::create(['partner_code' => 'V-JPD-1', 'type' => 'vendor', 'name' => 'JPD Vendor Ltd']);

    $payload = jpdPayload($pair, 1500.00, 1500.00, JPD_TODAY, 'BV') + [
        'partner_id' => $vendor->id,
        'partner_type' => 'vendor',
    ];

    $posted = jpdService()->post(jpdService()->create($payload));

    $payable = Payable::where('journal_id', $posted->id)->first();

    expect($payable)->not->toBeNull()
        ->and((float) $payable->amount)->toBe(1500.0)
        ->and($payable->status)->toBe('open')
        ->and($payable->due_date->toDateString())->toBe('2026-12-05');
});

test('posting a receipt voucher against a customer opens a receivable', function () {
    jpdAdmin();
    jpdFiscalYear();
    $pair = jpdPair();

    $customer = Partner::create(['partner_code' => 'C-JPD-1', 'type' => 'customer', 'name' => 'JPD Customer Ltd']);

    $payload = jpdPayload($pair, 900.00, 900.00, JPD_TODAY, 'RV') + [
        'partner_id' => $customer->id,
        'partner_type' => 'customer',
    ];

    $posted = jpdService()->post(jpdService()->create($payload));

    $receivable = Receivable::where('journal_id', $posted->id)->first();

    expect($receivable)->not->toBeNull()
        ->and((float) $receivable->amount)->toBe(900.0)
        ->and($receivable->status)->toBe('open');
});

test('a journal voucher with no partner opens neither a payable nor a receivable', function () {
    jpdAdmin();
    jpdFiscalYear();

    $posted = jpdPosted(80.00);

    expect(Payable::where('journal_id', $posted->id)->count())->toBe(0)
        ->and(Receivable::where('journal_id', $posted->id)->count())->toBe(0);
});

test('a partner on the wrong side of the transaction opens nothing', function () {
    jpdAdmin();
    jpdFiscalYear();
    $pair = jpdPair();

    // A bill voucher naming a customer is a data-entry mistake; it must not silently mint a payable
    // against someone who is not a vendor.
    $customer = Partner::create(['partner_code' => 'C-JPD-2', 'type' => 'customer', 'name' => 'Wrong Side Ltd']);

    $payload = jpdPayload($pair, 400.00, 400.00, JPD_TODAY, 'BV') + [
        'partner_id' => $customer->id,
        'partner_type' => 'customer',
    ];

    $posted = jpdService()->post(jpdService()->create($payload));

    expect(Payable::where('journal_id', $posted->id)->count())->toBe(0)
        ->and(Receivable::where('journal_id', $posted->id)->count())->toBe(0);
});
