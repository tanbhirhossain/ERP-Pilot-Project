<?php

/**
 * Every slip of a salary sheet reaches the general ledger — report item 40, finding 35.
 *
 * The defect this file exists to keep fixed:
 *
 * `payroll_journal_posts` carried `unique(salary_sheet_id, posting_type)`, added with the comment
 * `// idempotent`. `PayrollJournalService::postSalaryPayment()` writes **one post per slip** and keyed
 * its own idempotence on `meta->slip_id`, a JSON path that the unique index does not include. So the
 * second slip of any sheet violated `uniq_sheet_posting`, the surrounding transaction rolled the
 * journal back with it, and two layers of `catch (\Throwable) { Log::… }` — one in
 * `PayrollPaymentPostingService`, one in `MarkPayrollSlipPaymentAction` under the comment "never blocks
 * HR" — meant nothing surfaced. Every slip was marked paid, the PF ledger was written for each one, and
 * N−1 of N salary payments never reached the books: for a 998-employee sheet, a bank account overstated
 * by nearly a full payroll, with the only evidence in a log file.
 *
 * The fix is a schema decision plus a visibility decision, and this file pins both:
 *
 *  1. **The idempotence key includes the slip.** `posting_scope` is a real column — `'sheet'` for
 *     sheet-level postings, `'slip:<id>'` for per-slip ones — and the unique index is
 *     `(salary_sheet_id, posting_type, posting_scope)`. A JSON path cannot be part of a portable unique
 *     index, and widening the old index to a nullable `payroll_slip_id` would not work either: SQLite
 *     and MySQL both treat NULLs as distinct, so sheet-level rows would have lost their idempotence
 *     entirely. Test 3 is here to prove that half survived.
 *  2. **"Never blocks HR" no longer means "never surfaces".** The inner service rethrows; the action
 *     still lets the payroll officer mark the slip paid, but records the reason on the slip itself, so
 *     a paid-but-unposted slip is a queryable fact rather than a log line. Tests 5 and 6 pin that pair,
 *     because either half alone is a regression: throwing all the way up would block payroll on the
 *     chart of accounts, and swallowing is what caused this finding.
 *
 * Helpers are prefixed `spp` because every Feature file loads into one process. Slips are built from
 * the seeded roster, never from invented employee ids.
 */

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\ACCOUNTS\Models\Account;
use Modules\ACCOUNTS\Models\Bank;
use Modules\CORE\Models\User;
use Modules\HRM\Models\Employee\Employee;
use Modules\PAYROLL\Actions\MarkPayrollSlipPaymentAction;
use Modules\PAYROLL\Models\PayrollJournalPost;
use Modules\PAYROLL\Models\PayrollSalarySheet;
use Modules\PAYROLL\Models\PayrollSlip;
use Modules\PAYROLL\Services\PayrollJournalService;
use Modules\PAYROLL\Services\PayrollPaymentPostingService;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Posting needs the chart of accounts, an open fiscal year and its periods.
    $this->seed(DatabaseSeeder::class);
    config(['hashing.bcrypt.rounds' => 4]);
});

function sppSheet(int $year = 2026, int $month = 11, string $status = 'released'): PayrollSalarySheet
{
    return PayrollSalarySheet::create([
        'pay_year' => $year,
        'pay_month' => $month,
        'status' => $status,
        'employee_count' => 0,
        'processed_count' => 0,
    ]);
}

function sppSlip(PayrollSalarySheet $sheet, string $employeeId, float $net): PayrollSlip
{
    $name = Employee::where('employee_id', $employeeId)->value('full_name');

    return PayrollSlip::create([
        'salary_sheet_id' => $sheet->id,
        'employee_id' => $employeeId,
        'employee_name' => $name ?? $employeeId,
        'department' => 'SPP Department',
        'pay_year' => $sheet->pay_year,
        'pay_month' => $sheet->pay_month,
        'basic_salary' => $net,
        'gross_salary' => $net,
        'total_benefits' => 0,
        'total_deductions' => 0,
        'net_salary' => $net,
        'payment_status' => 'unpaid',
        'breakdown_json' => [],
    ]);
}

/**
 * Real employee ids. `payroll_slips.employee_id` is a foreign key to `employees.employee_id`
 * (RESTRICT), so a made-up id fails the insert — and it fails as a bare "FOREIGN KEY constraint
 * failed" that looks like the sheet is missing. The base seed carries the full 998-strong roster.
 */
function sppEmployees(int $n): array
{
    return Employee::query()
        ->orderBy('id')
        ->limit($n)
        ->pluck('employee_id')
        ->all();
}

/** The bank's own GL account — `accounts.defaults.bank_code` 1002 is a *group*, and posting to a group is refused. */
function sppBankAccountId(): int
{
    $bank = Bank::where('is_active', true)->orderBy('id')->first();

    if ($bank !== null && $bank->account_id !== null) {
        return (int) $bank->account_id;
    }

    return (int) Account::where('code', '1001')->value('id');
}

/**
 * Make the next posting fail, for real.
 *
 * `Bank::account_id` is repointed at `1002 Cash at Bank`, which is a group header, and
 * `JournalService` refuses to post to one ("post to a child account"). This is the whole production
 * stack failing, not a mock: `PayrollJournalService` and `PayrollPaymentPostingService` are both
 * `final`, so Mockery cannot stand in for them anyway, and a mocked failure would only have proved
 * that the test's own stub throws. A mis-linked bank is also how this would actually happen.
 */
function sppBreakTheBankLink(): void
{
    $group = Account::where('code', '1002')->where('is_group', true)->firstOrFail();

    Bank::where('is_active', true)->update(['account_id' => $group->id]);
}

/** The debit and credit one journal wrote to one account code. */
function sppLeg(int $journalId, string $code): array
{
    $row = DB::table('acc_journal_lines as l')
        ->join('acc_accounts as a', 'a.id', '=', 'l.account_id')
        ->where('l.journal_id', $journalId)
        ->where('a.code', $code)
        ->selectRaw('COALESCE(SUM(l.debit), 0) as dr, COALESCE(SUM(l.credit), 0) as cr')
        ->first();

    return [(float) $row->dr, (float) $row->cr];
}

// ─────────────────────────────────────────────────────────────────────────────
// The defect: N slips, N−1 of them missing from the ledger
// ─────────────────────────────────────────────────────────────────────────────

test('every slip of a sheet reaches the general ledger, not just the first', function () {
    $sheet = sppSheet();
    $nets = [1000.00, 2000.00, 500.50];
    $employees = sppEmployees(3);
    $slips = [];

    foreach ($nets as $i => $net) {
        $slips[] = sppSlip($sheet, $employees[$i], $net);
    }

    $service = app(PayrollJournalService::class);
    $bankAccountId = sppBankAccountId();

    foreach ($slips as $slip) {
        $service->postSalaryPayment($slip, $bankAccountId, now()->toDateString());
    }

    $posts = PayrollJournalPost::where('salary_sheet_id', $sheet->id)
        ->where('posting_type', 'salary_payment')
        ->get();

    expect($posts)->toHaveCount(3, 'one slip of three reached the ledger; this is finding 35');

    // Three separate journals, one per employee, each balanced and each carrying that employee's net.
    expect($posts->pluck('journal_id')->unique())->toHaveCount(3, 'two slips shared a journal');

    foreach ($slips as $i => $slip) {
        $post = $posts->firstWhere('payroll_slip_id', $slip->id);
        $this->assertNotNull($post, "slip {$slip->id} has no payroll_journal_posts row");

        $journal = $post->journal;
        $this->assertNotNull($journal, "slip {$slip->id} has a post but no journal");
        $this->assertSame('posted', $journal->status);
        $this->assertSame((float) $journal->total_debit, (float) $journal->total_credit, 'the payment journal does not balance');

        [$drPayable, $crPayable] = sppLeg($journal->id, '2121');
        [$drBank, $crBank] = sppLeg($journal->id, Account::find($bankAccountId)->code);

        $this->assertSame(round($nets[$i], 2), round($drPayable, 2), 'salary payable was not debited for slip '.$slip->id);
        $this->assertSame(round($nets[$i], 2), round($crBank, 2), 'the bank was not credited for slip '.$slip->id);
        $this->assertSame(0.0, $drBank, 'the bank leg is on the wrong side');

        // And the slip points at its own journal, which is what the payroll screen shows.
        $this->assertSame((int) $journal->id, (int) $slip->fresh()->payment_journal_id);
    }
});

test('the ledger\'s salary payments add up to the sheet\'s net pay', function () {
    $sheet = sppSheet(2026, 10);
    $nets = [1234.56, 9876.54, 0.01, 5000.00];
    $employees = sppEmployees(4);

    foreach ($nets as $i => $net) {
        sppSlip($sheet, $employees[$i], $net);
    }

    $service = app(PayrollJournalService::class);
    $bankAccountId = sppBankAccountId();

    foreach (PayrollSlip::where('salary_sheet_id', $sheet->id)->get() as $slip) {
        $service->postSalaryPayment($slip, $bankAccountId, now()->toDateString());
    }

    $posted = (float) PayrollJournalPost::where('salary_sheet_id', $sheet->id)
        ->where('posting_type', 'salary_payment')
        ->sum('total_credit');

    // This is the figure the finding says was wrong by N−1 slips: a bank account overstated by nearly
    // a full payroll, because the credit side never happened for anybody but the first employee.
    $this->assertSame(round(array_sum($nets), 2), round($posted, 2), 'the posted payments do not equal the net pay');
});

test('two sheets in different periods each post their own slips', function () {
    $bankAccountId = sppBankAccountId();
    $service = app(PayrollJournalService::class);

    $a = sppSheet(2026, 8);
    $b = sppSheet(2026, 9);

    $employees = sppEmployees(2);

    $service->postSalaryPayment(sppSlip($a, $employees[0], 700.00), $bankAccountId, now()->toDateString());
    $service->postSalaryPayment(sppSlip($b, $employees[1], 800.00), $bankAccountId, now()->toDateString());

    expect(PayrollJournalPost::where('posting_type', 'salary_payment')->count())->toBe(2)
        ->and((float) PayrollJournalPost::where('salary_sheet_id', $a->id)->sum('total_credit'))->toBe(700.0)
        ->and((float) PayrollJournalPost::where('salary_sheet_id', $b->id)->sum('total_credit'))->toBe(800.0);
});

// ─────────────────────────────────────────────────────────────────────────────
// Idempotence, both kinds
// ─────────────────────────────────────────────────────────────────────────────

test('posting the same slip twice posts it once', function () {
    $sheet = sppSheet(2026, 7);
    $slip = sppSlip($sheet, sppEmployees(1)[0], 1500.00);

    $service = app(PayrollJournalService::class);
    $bankAccountId = sppBankAccountId();

    $first = $service->postSalaryPayment($slip, $bankAccountId, now()->toDateString());
    $second = $service->postSalaryPayment($slip->fresh(), $bankAccountId, now()->toDateString());

    expect((int) $second->id)->toBe((int) $first->id, 'the same slip posted twice')
        ->and(PayrollJournalPost::where('posting_type', 'salary_payment')->count())->toBe(1)
        ->and(DB::table('acc_journals')->count())->toBe(1, 'a second journal was written for one payment');
});

test('a sheet-level posting is still one row per sheet, which is what the old index was for', function () {
    // Widening the key to include the slip must not cost the sheet-level postings their idempotence.
    // A nullable slip column would have: SQLite and MySQL both treat NULLs as distinct in a unique
    // index, so accruals would have been free to duplicate. `posting_scope = 'sheet'` is not null.
    $sheet = sppSheet(2026, 6);
    sppSlip($sheet, sppEmployees(1)[0], 2500.00);

    $service = app(PayrollJournalService::class);

    $first = $service->postSalaryAccrual($sheet);
    $second = $service->postSalaryAccrual($sheet->fresh());

    expect((int) $second->id)->toBe((int) $first->id, 'the accrual posted twice for one sheet')
        ->and(PayrollJournalPost::where('posting_type', 'salary_accrual')->count())->toBe(1)
        ->and($first->posting_scope)->toBe('sheet');
});

test('per-slip posts carry the slip, so the payroll screen can find them', function () {
    $sheet = sppSheet(2026, 5);
    $slip = sppSlip($sheet, sppEmployees(1)[0], 300.00);

    $post = app(PayrollJournalService::class)
        ->postSalaryPayment($slip, sppBankAccountId(), now()->toDateString());

    expect($post->posting_scope)->toBe('slip:'.$slip->id)
        ->and((int) $post->payroll_slip_id)->toBe((int) $slip->id)
        ->and((int) $post->meta['slip_id'])->toBe((int) $slip->id, 'the meta snapshot is still what the screen used to read');
});

// ─────────────────────────────────────────────────────────────────────────────
// "Never blocks HR" must not mean "never surfaces"
// ─────────────────────────────────────────────────────────────────────────────

test('the posting service rethrows a failure instead of logging it away', function () {
    $sheet = sppSheet(2026, 4);
    $slip = sppSlip($sheet, sppEmployees(1)[0], 900.00);
    $slip->update(['payment_status' => 'paid', 'paid_at' => now()]);
    sppBreakTheBankLink();

    // This is the line that used to be `catch (\Throwable) { Log::error(...) }`. Nothing reached the
    // caller, so the action below had no failure to record and the slip simply looked paid.
    expect(fn () => app(PayrollPaymentPostingService::class)->postIfPaid($slip->fresh()))
        ->toThrow(DomainException::class, 'group header');

    $this->assertNull($slip->fresh()->payment_journal_id, 'a refused posting still wrote a journal');
});

test('a slip that could not be posted stays paid, and says why on the record', function () {
    $sheet = sppSheet(2026, 3);
    $slip = sppSlip($sheet, sppEmployees(1)[0], 1750.00);
    $actor = User::first();
    sppBreakTheBankLink();

    // HR is not blocked: the officer marks the slip paid and the payroll closes.
    $result = app(MarkPayrollSlipPaymentAction::class)->execute($slip, 'paid', $actor?->id);

    $this->assertSame('paid', $result->payment_status, 'a posting failure blocked the payroll');
    $this->assertNull($result->payment_journal_id, 'the slip claims a journal that does not exist');

    // But it is not silent either: the reason is on the slip, so it can be listed, counted and worked.
    $this->assertNotNull($result->payment_posting_error, 'the failure was swallowed into a log again');
    $this->assertStringContainsString('group header', $result->payment_posting_error);
    $this->assertStringContainsString((string) $slip->id, $result->payment_posting_error, 'the reason does not name the slip');

    // Queryable, which is the difference between a divergence somebody can find and one nobody can.
    $unposted = PayrollSlip::paidButUnposted()->whereNotNull('payment_posting_error')->count();

    $this->assertSame(1, $unposted, 'a paid-but-unposted slip is not something anybody can list');
});

test('a slip that posts cleanly carries no error', function () {
    $sheet = sppSheet(2026, 2);
    $slip = sppSlip($sheet, sppEmployees(1)[0], 2200.00);
    $actor = User::first();

    $result = app(MarkPayrollSlipPaymentAction::class)->execute($slip, 'paid', $actor?->id);

    $this->assertSame('paid', $result->payment_status);
    $this->assertNotNull($result->payment_journal_id, 'the payment did not reach the ledger');
    $this->assertNull($result->payment_posting_error);
    $this->assertSame(1, PayrollJournalPost::where('posting_type', 'salary_payment')->count());
});
