<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\CORE\Models\User;
use Modules\PAYROLL\Models\PfAccount;
use Modules\PAYROLL\Models\PfLedger;
use Modules\PAYROLL\Models\PfPolicy;
use Modules\PAYROLL\Models\PfWithdrawal;
use Modules\PAYROLL\Models\PayrollSalarySheet;
use Modules\PAYROLL\Models\PayrollSlip;
use Modules\PAYROLL\Services\ProvidentFundService;

/**
 * The provident fund is only worth having if four things hold at once. Each is a test here, because
 * each was broken in the code this replaces:
 *
 *   1. Accrual posts a liability without moving cash.
 *   2. Accrual is idempotent — a re-run cannot double-credit a member.
 *   3. Settlement changes the cash side and *not* the liability.
 *   4. A withdrawal leaves the fund only through the ledger, and a balance always equals its ledger.
 *
 * The last one is the whole point of the rebuild: `assertBalancesReconcile()` is asserted after
 * every mutation rather than once at the end, so a failure points at the operation that broke it.
 */
uses(RefreshDatabase::class);

/**
 * A signed-in fund officer.
 *
 * The Pest database is `:memory:` built from migrations only, so permissions are not seeded and
 * `givePermissionTo()` would throw. The suite's established pattern for that is a `Gate::before`
 * escape hatch scoped to the exact abilities under test — it grants nothing outside this file.
 */
function pfUser(): User
{
    Gate::before(fn ($actor, string $ability) => $actor instanceof User
        && in_array($ability, ['payroll.pf.view', 'payroll.pf.manage'], true)
            ? true
            : null);

    return User::factory()->create();
}

function pfEmployee(string $id = 'PF-0001', ?string $joined = '2020-01-01'): string
{
    DB::table('employees')->insert([
        'employee_id' => $id,
        'full_name' => 'Fund Member '.$id,
        'first_name' => 'Fund',
        'last_name' => 'Member',
        'joining_date' => $joined,
        'status' => 'Active',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function pfPolicy(float $employee = 10.0, float $employer = 10.0): PfPolicy
{
    return PfPolicy::query()->create([
        'name' => 'Standard PF',
        'employee_contribution_percent' => $employee,
        'employer_contribution_percent' => $employer,
        'minimum_service_months' => 0,
        'contribution_base' => 'basic',
        'is_active' => true,
        'effective_from' => '2020-01-01',
    ]);
}

function pfAccount(string $employeeId, PfPolicy $policy): PfAccount
{
    return PfAccount::query()->create([
        'employee_id' => $employeeId,
        'employee_name' => 'Fund Member '.$employeeId,
        'pf_policy_id' => $policy->id,
        'eligible_from' => '2020-01-01',
        'status' => 'active',
        'employee_balance' => 0,
        'employer_balance' => 0,
    ]);
}

/**
 * A released sheet with one slip whose `breakdown_json` carries a provident-fund block, which is
 * exactly what the payroll engine writes and what accrual reads.
 */
function pfSheet(PfAccount $account, int $year, int $month, string $status, float $employee = 500.0, float $employer = 500.0): PayrollSalarySheet
{
    $sheet = PayrollSalarySheet::query()->create([
        'pay_year' => $year,
        'pay_month' => $month,
        'status' => $status,
        'include_punishments' => false,
        'employee_count' => 1,
        'processed_count' => 1,
        'gross_total' => 10000,
        'deduction_total' => 1000,
        'net_total' => 9000,
        // `created_by` is a foreign key to `users`, not a magic number: factories, not 1.
        'created_by' => User::factory()->create()->id,
    ]);

    PayrollSlip::query()->create([
        'salary_sheet_id' => $sheet->id,
        'employee_id' => $account->employee_id,
        'employee_name' => $account->employee_name,
        'pay_year' => $year,
        'pay_month' => $month,
        'basic_salary' => 5000,
        'gross_salary' => 10000,
        'total_deductions' => 1000,
        'net_salary' => 9000,
        'payment_status' => 'unpaid',
        'breakdown_json' => [
            'provident_fund' => [
                'account_id' => $account->id,
                'policy_id' => $account->pf_policy_id,
                'eligible' => true,
                'employee_contribution' => $employee,
                'employer_contribution' => $employer,
                'base_amount' => 5000,
            ],
        ],
    ]);

    return $sheet;
}

/** The invariant: cached balances are re-derivable from the ledger, side by side. */
function assertBalancesReconcile(PfAccount $account): void
{
    $fresh = $account->fresh();
    $recomputed = (clone $fresh)->recalculateBalances();

    expect((float) $fresh->employee_balance)->toBe((float) $recomputed->employee_balance)
        ->and((float) $fresh->employer_balance)->toBe((float) $recomputed->employer_balance);

    expect(app(ProvidentFundService::class)->reconcile())->toBe([]);
}

it('refuses to accrue a month whose sheet is not released', function (): void {
    $policy = pfPolicy();
    $account = pfAccount(pfEmployee(), $policy);
    $sheet = pfSheet($account, 2026, 10, 'ready');

    expect(fn () => app(ProvidentFundService::class)->accrueFromSheet($sheet, 1))
        ->toThrow(Illuminate\Validation\ValidationException::class);

    expect(PfLedger::query()->count())->toBe(0)
        ->and((float) $account->fresh()->employee_balance)->toBe(0.0);
});

it('accrues both sides from a released sheet without touching the cash position', function (): void {
    $policy = pfPolicy();
    $account = pfAccount(pfEmployee(), $policy);
    $sheet = pfSheet($account, 2026, 10, 'released', 500.0, 700.0);

    $result = app(ProvidentFundService::class)->accrueFromSheet($sheet, 1);

    expect($result['posted'])->toBe(1);
    expect(PfLedger::query()->where('status', 'accrued')->count())->toBe(2);

    $account->refresh();
    expect((float) $account->employee_balance)->toBe(500.0)
        ->and((float) $account->employer_balance)->toBe(700.0);

    $cash = app(ProvidentFundService::class)->cashPosition();
    expect($cash['received'])->toBe(0.0)          // nothing has been paid into the fund yet
        ->and($cash['outstanding'])->toBe(1200.0)  // but the liability exists
        ->and($cash['liability'])->toBe(1200.0);

    assertBalancesReconcile($account);
});

it('is idempotent: accruing the same month twice changes nothing', function (): void {
    $policy = pfPolicy();
    $account = pfAccount(pfEmployee(), $policy);
    $sheet = pfSheet($account, 2026, 10, 'released');
    $service = app(ProvidentFundService::class);

    $service->accrueFromSheet($sheet, 1);
    $afterFirst = PfLedger::query()->count();

    $second = $service->accrueFromSheet($sheet->fresh(), 1);

    expect($second['posted'])->toBe(0)
        ->and(PfLedger::query()->count())->toBe($afterFirst)
        ->and((float) $account->fresh()->employee_balance)->toBe(500.0);

    assertBalancesReconcile($account);
});

it('settles a month without changing any member balance', function (): void {
    $policy = pfPolicy();
    $account = pfAccount(pfEmployee(), $policy);
    $sheet = pfSheet($account, 2026, 10, 'released');
    $service = app(ProvidentFundService::class);

    $service->accrueFromSheet($sheet, 1);
    $before = (float) $account->fresh()->totalBalance();

    $settled = $service->settleMonth('2026-10', 1);

    expect($settled['settled'])->toBe(2)
        ->and((float) $account->fresh()->totalBalance())->toBe($before)
        ->and(PfLedger::query()->where('status', 'accrued')->count())->toBe(0);

    $cash = $service->cashPosition();
    expect($cash['received'])->toBe(1000.0)
        ->and($cash['outstanding'])->toBe(0.0)
        ->and($cash['liability'])->toBe(1000.0);

    assertBalancesReconcile($account);
});

it('refuses a partial withdrawal above the member side but allows a final settlement of the whole fund', function (): void {
    $policy = pfPolicy();
    $account = pfAccount(pfEmployee(), $policy);
    $service = app(ProvidentFundService::class);
    $service->accrueFromSheet(pfSheet($account, 2026, 10, 'released', 500.0, 500.0), 1);

    expect(fn () => $service->requestWithdrawal($account->fresh(), [
        'type' => 'partial', 'requested_amount' => 700, 'reason' => 'Medical expenses',
    ], 1))->toThrow(Illuminate\Validation\ValidationException::class);

    $ok = $service->requestWithdrawal($account->fresh(), [
        'type' => 'partial', 'requested_amount' => 400, 'reason' => 'Medical expenses',
    ], 1);
    expect($ok->status)->toBe('pending');

    $final = $service->requestWithdrawal($account->fresh(), [
        'type' => 'final_settlement', 'requested_amount' => 1000, 'reason' => 'Resignation',
    ], 1);
    expect($final->status)->toBe('pending');
});

it('approving a withdrawal moves no money, and paying it posts exactly one ledger debit', function (): void {
    $policy = pfPolicy();
    $account = pfAccount(pfEmployee(), $policy);
    $service = app(ProvidentFundService::class);
    $service->accrueFromSheet(pfSheet($account, 2026, 10, 'released', 500.0, 500.0), 1);

    $requester = User::factory()->create();
    $signer = User::factory()->create();

    $request = $service->requestWithdrawal($account->fresh(), [
        'type' => 'partial', 'requested_amount' => 400, 'reason' => 'Medical expenses',
    ], $requester->id);

    // Four eyes: the officer who raises a withdrawal may not be the one who clears it.
    $service->decideWithdrawal($request, 'approve', null, $signer->id);

    expect((float) $account->fresh()->totalBalance())->toBe(1000.0)
        ->and(PfLedger::query()->whereIn('entry_type', ['withdrawal', 'settlement'])->count())->toBe(0);

    $service->decideWithdrawal($request->fresh(), 'pay', 400.0, $signer->id);

    $debits = PfLedger::query()->whereIn('entry_type', ['withdrawal', 'settlement'])->get();
    expect($debits)->toHaveCount(1)
        ->and($debits->first()->entry_type)->toBe('withdrawal')
        ->and((float) $debits->first()->amount)->toBe(400.0);

    $account->refresh();
    expect((float) $account->employee_balance)->toBe(100.0)
        ->and((float) $account->employer_balance)->toBe(500.0);

    assertBalancesReconcile($account);
});

it('pays a request that was approved first, not only one still pending', function (): void {
    $policy = pfPolicy();
    $account = pfAccount(pfEmployee(), $policy);
    $service = app(ProvidentFundService::class);
    $service->accrueFromSheet(pfSheet($account, 2026, 10, 'released', 500.0, 500.0), 1);

    $requester = User::factory()->create();
    $signer = User::factory()->create();

    $request = $service->requestWithdrawal($account->fresh(), [
        'type' => 'partial', 'requested_amount' => 300, 'reason' => 'School fees',
    ], $requester->id);

    // The two-step flow the withdrawal desk presents: approve, then pay the approved row.
    $service->decideWithdrawal($request, 'approve', 300.0, $signer->id);
    $service->decideWithdrawal($request->fresh(), 'pay', 300.0, $signer->id);

    expect($request->fresh()->status)->toBe('paid')
        ->and((float) $account->fresh()->totalBalance())->toBe(700.0);

    assertBalancesReconcile($account);
});

it('pays a final settlement as a settlement entry and closes the account', function (): void {
    $policy = pfPolicy();
    $account = pfAccount(pfEmployee(), $policy);
    $service = app(ProvidentFundService::class);
    $service->accrueFromSheet(pfSheet($account, 2026, 10, 'released', 500.0, 500.0), 1);

    $request = $service->requestWithdrawal($account->fresh(), [
        'type' => 'final_settlement', 'requested_amount' => 1000, 'reason' => 'Resignation',
    ], 1);

    $service->decideWithdrawal($request, 'pay', 1000.0, User::factory()->create()->id);

    $entry = PfLedger::query()->where('entry_type', 'settlement')->sole();
    expect((float) $entry->amount)->toBe(1000.0);

    $account->refresh();
    expect($account->status)->toBe('closed')
        ->and($account->totalBalance())->toBe(0.0);

    assertBalancesReconcile($account);
});

it('refuses to accrue into a closed account', function (): void {
    $policy = pfPolicy();
    $account = pfAccount(pfEmployee(), $policy);
    $service = app(ProvidentFundService::class);
    $service->accrueFromSheet(pfSheet($account, 2026, 10, 'released'), 1);

    $request = $service->requestWithdrawal($account->fresh(), [
        'type' => 'final_settlement', 'requested_amount' => 1000, 'reason' => 'Resignation',
    ], 1);
    $service->decideWithdrawal($request, 'pay', 1000.0, User::factory()->create()->id);

    $service->accrueFromSheet(pfSheet($account->fresh(), 2026, 11, 'released'), 1);

    expect((float) $account->fresh()->totalBalance())->toBe(0.0)
        ->and(PfLedger::query()->where('contribution_month', '2026-11')->count())->toBe(0);
});

it('reports drift when a cached balance is edited behind the ledger\'s back', function (): void {
    $policy = pfPolicy();
    $account = pfAccount(pfEmployee(), $policy);
    app(ProvidentFundService::class)->accrueFromSheet(pfSheet($account, 2026, 10, 'released'), 1);

    $account->update(['employee_balance' => 999.0]);

    $drift = app(ProvidentFundService::class)->reconcile();

    // The drift is reported as ledger minus cached, so an over-stated balance reads as -499.
    expect($drift)->toHaveCount(1)
        ->and((float) $drift[0]['employee_drift'])->toBe(-499.0);
});

it('serves every page of the fund module to someone who may view it', function (): void {
    $policy = pfPolicy();
    $account = pfAccount(pfEmployee(), $policy);
    app(ProvidentFundService::class)->accrueFromSheet(pfSheet($account, 2026, 10, 'released'), 1);

    $user = pfUser();

    // The dashboard reads the fund; the register reads the months; the roster reads the accounts.
    $this->actingAs($user)->get(route('payroll.pf.dashboard'))->assertOk();
    $this->actingAs($user)->get(route('payroll.pf.index'))->assertOk();
    $this->actingAs($user)->get(route('payroll.pf.members'))->assertOk();
    $this->actingAs($user)->get(route('payroll.pf.show', $account->id))->assertOk();
    $this->actingAs($user)->get(route('payroll.pf.withdrawals.index'))->assertOk();
});

it('orders the contribution trend oldest first, with a zero for a month that posted nothing', function (): void {
    $policy = pfPolicy();
    $account = pfAccount(pfEmployee(), $policy);
    $service = app(ProvidentFundService::class);

    // Two months, one of them empty: October posts, November does not.
    $service->accrueFromSheet(pfSheet($account, 2026, 10, 'released', 500.0, 500.0), 1);
    $service->accrueFromSheet(pfSheet($account, 2026, 12, 'released', 700.0, 700.0), 1);

    $trend = collect($service->contributionTrend(3))->keyBy('period');

    // A chart is read left to right, so the oldest month comes first — the opposite of a table.
    expect(array_column($service->contributionTrend(3), 'period'))->toBe(['2026-10', '2026-11', '2026-12']);

    // The gap is present and zero, so a missing month reads as a gap rather than collapsing the axis.
    expect($trend['2026-11']['total'])->toBe(0.0)
        ->and($trend['2026-10']['total'])->toBe(1000.0)
        ->and($trend['2026-12']['total'])->toBe(1400.0);
});

it('allocates accounts one employee at a time and reports what it examined', function (): void {
    $policy = pfPolicy();
    pfEmployee('PF-1001');
    pfEmployee('PF-1002');
    $service = app(ProvidentFundService::class);

    $first = $service->allocateAccounts($policy->id, ['PF-1001'], false);
    expect($first)->toBe(['created' => 1, 'skipped' => 0]);

    // A second call for the same person creates nothing and says so.
    $second = $service->allocateAccounts($policy->id, ['PF-1001'], false);
    expect($second['created'])->toBe(0)
        ->and($second['skipped'])->toBe(1);

    expect(PfAccount::query()->count())->toBe(1);
});

it('writes a date-only eligible_from instead of a timestamp', function (): void {
    $policy = pfPolicy();
    pfEmployee('PF-2001', '2023-03-15');
    app(ProvidentFundService::class)->allocateAccounts($policy->id, ['PF-2001'], false);

    $account = PfAccount::query()->where('employee_id', 'PF-2001')->sole();

    expect($account->eligible_from->toDateString())->toBe('2023-03-15');

    // And the register must serialise it as a date the browser cannot shift by timezone. The member
    // list lives on its own page now — `pf.index` is the contribution register, `pf.members` the roster.
    $user = pfUser();
    $response = $this->actingAs($user)->get(route('payroll.pf.members'));
    $response->assertOk();

    $rows = $response->viewData('page')['props']['accounts']['data'];
    $row = collect($rows)->firstWhere('employee_id', 'PF-2001');

    expect($row['eligible_from'])->toBe('2023-03-15');
});

/*
 * Four eyes on the fund.
 *
 * User 1 raised withdrawal #3 and user 1 approved it, and the withdrawable amount moved. Nothing in
 * the service objected, because nothing looked. The three tests below pin the rule in both
 * directions: self-decision refused at *both* decision points, and a second signer unaffected.
 */
it('refuses to let the officer who raised a withdrawal approve or pay it', function (): void {
    $policy = pfPolicy();
    $account = pfAccount(pfEmployee(), $policy);
    $service = app(ProvidentFundService::class);
    $service->accrueFromSheet(pfSheet($account, 2026, 10, 'released', 500.0, 500.0), 1);

    $officer = User::factory()->create();

    $request = $service->requestWithdrawal($account->fresh(), [
        'type' => 'partial', 'requested_amount' => 400, 'reason' => 'Medical expenses',
    ], $officer->id);

    expect(fn () => $service->decideWithdrawal($request, 'approve', null, $officer->id))
        ->toThrow(Illuminate\Validation\ValidationException::class);

    // Paying straight from `pending` is a legal shortcut for a second signer, not for the requester.
    expect(fn () => $service->decideWithdrawal($request->fresh(), 'pay', 400.0, $officer->id))
        ->toThrow(Illuminate\Validation\ValidationException::class);

    expect($request->fresh()->status)->toBe('pending')
        ->and((float) $account->fresh()->totalBalance())->toBe(1000.0)
        ->and(PfLedger::query()->whereIn('entry_type', ['withdrawal', 'settlement'])->count())->toBe(0);

    assertBalancesReconcile($account);
});

it('lets a second signer clear a withdrawal the first officer raised', function (): void {
    $policy = pfPolicy();
    $account = pfAccount(pfEmployee(), $policy);
    $service = app(ProvidentFundService::class);
    $service->accrueFromSheet(pfSheet($account, 2026, 10, 'released', 500.0, 500.0), 1);

    $requester = User::factory()->create();
    $signer = User::factory()->create();

    $request = $service->requestWithdrawal($account->fresh(), [
        'type' => 'partial', 'requested_amount' => 400, 'reason' => 'Medical expenses',
    ], $requester->id);

    $service->decideWithdrawal($request, 'approve', null, $signer->id);
    $service->decideWithdrawal($request->fresh(), 'pay', 400.0, $signer->id);

    $paid = $request->fresh();
    expect($paid->status)->toBe('paid')
        ->and((int) $paid->requested_by)->toBe($requester->id)
        ->and((int) $paid->decided_by)->toBe($signer->id)
        ->and((float) $account->fresh()->totalBalance())->toBe(600.0);

    assertBalancesReconcile($account);
});

it('leaves a request raised outside the desk (no actor) decidable by anyone', function (): void {
    $policy = pfPolicy();
    $account = pfAccount(pfEmployee(), $policy);
    $service = app(ProvidentFundService::class);
    $service->accrueFromSheet(pfSheet($account, 2026, 10, 'released', 500.0, 500.0), 1);

    $request = $service->requestWithdrawal($account->fresh(), [
        'type' => 'partial', 'requested_amount' => 250, 'reason' => 'System-generated request',
    ], null);

    // No person raised it, so there is no second pair of eyes to demand.
    $service->decideWithdrawal($request, 'pay', 250.0, User::factory()->create()->id);

    expect($request->fresh()->status)->toBe('paid')
        ->and((float) $account->fresh()->totalBalance())->toBe(750.0);

    assertBalancesReconcile($account);
});
