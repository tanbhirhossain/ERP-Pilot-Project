<?php

/**
 * Journal edit guard, and the null-side defect it exposed.
 *
 * `acc_journal_lines.debit`/`credit` are `numeric NOT NULL DEFAULT '0'`. A default only applies
 * when the column is *omitted* from the INSERT — and both `JournalRepository::create()` and
 * `update()` forward each request line verbatim. The UI submits `null` for whichever side a line
 * does not use (typing a debit clears the credit), so every journal create and every draft edit
 * hit `NOT NULL constraint failed`. `store()` catches only `DomainException`, so that surfaced as
 * a 500 rather than a flashed error. `acc_journals` ships empty in the demo seed and had no test
 * coverage, which is why nothing ever exercised the path.
 *
 * The posted/void cases below also lock in `JournalRepository::update()`'s draft guard: it was
 * correct all along, but untested. `Journals/Edit.vue` now surfaces the same rule in the UI.
 *
 * Helper names are prefixed because Pest loads every Feature file into one process and
 * `adminUser()` is already declared by SelectorEndpointIntegrityTest.
 */

use Modules\ACCOUNTS\Models\Account;
use Modules\ACCOUNTS\Models\FiscalYear;
use Modules\ACCOUNTS\Models\Journal;
use Modules\ACCOUNTS\Models\JournalLine;
use Modules\ACCOUNTS\Services\JournalService;
use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\CORE\Models\User;

function journalAdmin(): User
{
    test()->seed(SuperAdminSeeder::class);

    $admin = User::where('email', config('core.super_admin.email', 'admin@example.com'))->first();
    expect($admin)->not->toBeNull('SuperAdminSeeder produced no admin user');

    return $admin;
}

/** A leaf posting account — active and not a group header, per assertAccountsPosting(). */
function journalAccount(string $code, string $name): Account
{
    return Account::create([
        'code' => $code,
        'name' => $name,
        'account_type' => 'asset',
        'nature' => 'debit',
        'level' => 1,
        'is_group' => false,
        'is_posting' => true,
        'is_active' => true,
        'currency' => 'BDT',
        'opening_balance' => 0,
    ]);
}

/** An open fiscal year covering the fixture dates — `create()` asserts one exists. */
function journalFiscalYear(string $status = 'open'): FiscalYear
{
    return FiscalYear::create([
        'name' => 'FY 2025-26',
        'code' => 'FY2526',
        'start_date' => '2025-07-01',
        'end_date' => '2026-06-30',
        'status' => $status,
    ]);
}

/** A balanced two-line journal totalling 1,000.00, in the given status. */
function journalFixture(string $status): Journal
{
    $cash = journalAccount('1001', 'Cash in Hand');
    $bank = journalAccount('1002', 'Bank — DBBL');

    $journal = Journal::create([
        'journal_no' => 'JV-GUARD-0001',
        'journal_date' => '2026-01-15',
        'type' => 'JV',
        'reference_no' => 'ORIGINAL',
        'narration' => 'Original narration',
        'total_debit' => 1000.00,
        'total_credit' => 1000.00,
        'status' => $status,
    ]);

    JournalLine::create(['journal_id' => $journal->id, 'account_id' => $cash->id, 'debit' => 1000.00, 'credit' => 0, 'line_no' => 1]);
    JournalLine::create(['journal_id' => $journal->id, 'account_id' => $bank->id, 'debit' => 0, 'credit' => 1000.00, 'line_no' => 2]);

    return $journal->fresh(['lines']);
}

/**
 * A payload that *passes* StoreJournalRequest: balanced, real accounts, valid type.
 * Deliberately valid, so the only thing that can refuse it is the draft guard.
 */
function journalPayload(Journal $journal, float $amount): array
{
    return [
        'journal_date' => '2026-01-15',
        'type' => 'JV',
        'reference_no' => 'TAMPERED',
        'narration' => 'Rewritten after posting',
        'lines' => [
            ['account_id' => $journal->lines[0]->account_id, 'debit' => $amount, 'credit' => null, 'description' => ''],
            ['account_id' => $journal->lines[1]->account_id, 'debit' => null, 'credit' => $amount, 'description' => ''],
        ],
    ];
}

beforeEach(function () {
    $this->actingAs(journalAdmin());
});

test('a posted journal cannot be edited through the endpoint', function () {
    journalFiscalYear();

    $journal = journalFixture('posted');

    $response = $this->put(route('accounts.journals.update', $journal->id), journalPayload($journal, 5000.00));

    // The controller turns a DomainException into a redirect back with a flashed error,
    // not a 500 — the user is told why rather than shown a stack trace.
    $response->assertRedirect();
    $response->assertSessionHas('error');

    $fresh = $journal->fresh(['lines']);

    expect($fresh->reference_no)->toBe('ORIGINAL', 'a posted journal accepted an edit')
        ->and($fresh->narration)->toBe('Original narration')
        ->and((float) $fresh->total_debit)->toBe(1000.00, 'posted totals were rewritten')
        ->and((float) $fresh->lines[0]->debit)->toBe(1000.00, 'posted lines were rewritten')
        ->and($fresh->status)->toBe('posted');
});

test('a void journal cannot be resurrected by editing it', function () {
    journalFiscalYear();

    $journal = journalFixture('void');

    $response = $this->put(route('accounts.journals.update', $journal->id), journalPayload($journal, 2500.00));

    $response->assertRedirect();
    $response->assertSessionHas('error');

    expect($journal->fresh()->reference_no)->toBe('ORIGINAL')
        ->and($journal->fresh()->status)->toBe('void');
});

test('a draft journal can still be edited', function () {
    journalFiscalYear();

    $journal = journalFixture('draft');

    $response = $this->put(route('accounts.journals.update', $journal->id), journalPayload($journal, 2500.00));

    $response->assertSessionHas('success');

    $fresh = $journal->fresh(['lines']);

    // Guards the guard: refusing non-drafts must not lock out the normal correction path.
    expect($fresh->reference_no)->toBe('TAMPERED')
        ->and((float) $fresh->total_debit)->toBe(2500.00)
        ->and($fresh->status)->toBe('draft')
        ->and($fresh->lines)->toHaveCount(2);
});

test('the service refuses a non-draft journal, via the repository guard', function () {
    journalFiscalYear();

    $journal = journalFixture('posted');

    expect(fn () => app(JournalService::class)->update($journal, journalPayload($journal, 9000.00)))
        ->toThrow(DomainException::class, 'Only draft journals can be edited');
});

test('a journal can be created when the unused side of each line is null, as the UI submits it', function () {
    journalFiscalYear();

    $cash = journalAccount('1001', 'Cash in Hand');
    $bank = journalAccount('1002', 'Bank — DBBL');

    $response = $this->post(route('accounts.journals.store'), [
        'journal_date' => '2026-02-01',
        'type' => 'JV',
        'reference_no' => 'NULLSIDE',
        'narration' => 'Being cash deposited to bank',
        'lines' => [
            ['account_id' => $cash->id, 'debit' => 500.00, 'credit' => null, 'description' => ''],
            ['account_id' => $bank->id, 'debit' => null, 'credit' => 500.00, 'description' => ''],
        ],
    ]);

    // A QueryException here escapes store()'s catch and becomes a 500, so this is the real gate.
    $response->assertSessionHas('success');

    $journal = Journal::where('reference_no', 'NULLSIDE')->first();

    expect($journal)->not->toBeNull('the journal was not created')
        ->and($journal->status)->toBe('draft')
        ->and((float) $journal->total_debit)->toBe(500.00)
        ->and((float) $journal->total_credit)->toBe(500.00)
        ->and($journal->lines()->count())->toBe(2)
        // The unused side must land as zero, not null.
        ->and((float) $journal->lines()->where('account_id', $cash->id)->value('credit'))->toBe(0.0)
        ->and((float) $journal->lines()->where('account_id', $bank->id)->value('debit'))->toBe(0.0);
});

test('a draft journal can be re-saved with a null on the unused side', function () {
    journalFiscalYear();

    $journal = journalFixture('draft');

    $response = $this->put(route('accounts.journals.update', $journal->id), journalPayload($journal, 750.00));

    $response->assertSessionHas('success');

    $fresh = $journal->fresh(['lines']);

    expect((float) $fresh->total_debit)->toBe(750.00)
        ->and((float) $fresh->lines[0]->credit)->toBe(0.0)
        ->and((float) $fresh->lines[1]->debit)->toBe(0.0);
});

test('a closed fiscal year still refuses a new journal', function () {
    journalFiscalYear('closed');

    $cash = journalAccount('1001', 'Cash in Hand');
    $bank = journalAccount('1002', 'Bank — DBBL');

    $response = $this->post(route('accounts.journals.store'), [
        'journal_date' => '2026-02-01',
        'type' => 'JV',
        'reference_no' => 'CLOSED-FY',
        'lines' => [
            ['account_id' => $cash->id, 'debit' => 100.00, 'credit' => null, 'description' => ''],
            ['account_id' => $bank->id, 'debit' => null, 'credit' => 100.00, 'description' => ''],
        ],
    ]);

    $response->assertSessionHas('error');

    // Guards the normalisation: coercing null to 0.00 must not weaken the fiscal-period check.
    expect(Journal::where('reference_no', 'CLOSED-FY')->exists())->toBeFalse();
});

test('a draft cannot be re-dated into a closed fiscal year', function () {
    journalFiscalYear('closed');

    $journal = journalFixture('draft');

    $payload = journalPayload($journal, 1000.00);
    $payload['journal_date'] = '2026-02-01'; // inside the closed year

    $response = $this->put(route('accounts.journals.update', $journal->id), $payload);

    $response->assertSessionHas('error');

    // update() skipped assertFiscalOpen(), so a draft could be moved into a closed period and
    // left sitting there until posting was attempted.
    expect($journal->fresh()->journal_date->format('Y-m-d'))->toBe('2026-01-15', 'the draft was re-dated into a closed period');
});
