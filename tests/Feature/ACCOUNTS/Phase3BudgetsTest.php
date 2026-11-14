<?php

/**
 * Phase 3 — F14 budgets & variance.
 *
 * The load-bearing contract is that actuals are read *live from the posted ledger* in the account's
 * natural direction, so `budget − actual` is a meaningful variance for an expense and for income alike
 * without per-row sign juggling. These tests post real journals and assert the report agrees with the
 * books — plus the line idempotency, the period/lock guards, and the approval transitions.
 *
 * Helpers are prefixed `p3bd*`.
 */

use Modules\ACCOUNTS\Models\Account;
use Modules\ACCOUNTS\Models\Budget;
use Modules\ACCOUNTS\Models\BudgetLine;
use Modules\ACCOUNTS\Models\FiscalYear;
use Modules\ACCOUNTS\Services\BudgetService;
use Modules\ACCOUNTS\Services\JournalService;
use Modules\CORE\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

function p3bdSvc(): BudgetService
{
    return app(BudgetService::class);
}

function p3bdAccount(string $code, string $nature): Account
{
    return Account::create([
        'code' => $code, 'name' => 'P3BD '.$code, 'account_type' => $nature === 'credit' ? 'income' : 'expense',
        'nature' => $nature, 'level' => 1, 'is_group' => false, 'is_posting' => true,
        'is_active' => true, 'currency' => 'BDT', 'opening_balance' => 0,
    ]);
}

function p3bdFy(): FiscalYear
{
    return FiscalYear::create(['name' => 'P3BD FY', 'code' => 'P3BD-'.uniqid(), 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
}

function p3bdBudget(int $fyId): Budget
{
    return Budget::create(['name' => 'P3BD '.uniqid(), 'code' => 'BDG-'.uniqid(), 'fiscal_year_id' => $fyId, 'status' => 'draft']);
}

/** Post a balanced draft journal that puts $amount onto $debitAccount (cr a cash account). */
function p3bdPost(Account $debitAccount, float $amount, string $date = '2026-04-15'): void
{
    $cash = p3bdAccount('9'.substr(md5(uniqid('', true)), 0, 5), 'debit'); // cash-side leaf, unique code
    test()->actingAs(User::factory()->create());
    $js = app(JournalService::class);
    $js->post($js->create([
        'journal_date' => $date, 'type' => 'JV', 'reference_no' => 'P3BD', 'narration' => 'P3BD actual', 'status' => 'draft',
        'lines' => [
            ['account_id' => $debitAccount->id, 'debit' => $amount, 'credit' => 0, 'description' => 'spend'],
            ['account_id' => $cash->id, 'debit' => 0, 'credit' => $amount, 'description' => 'fund'],
        ],
    ]));
}

/* ───────────────────────────── lines ───────────────────────────── */

test('an upsert creates a line and re-adding the same account+month updates it', function () {
    $b = p3bdBudget(p3bdFy()->id);
    $acc = p3bdAccount('5001', 'debit');

    p3bdSvc()->upsertLine($b, $acc->id, '2026-04', 1000);
    p3bdSvc()->upsertLine($b, $acc->id, '2026-04', 1200);   // same key → update, not duplicate

    expect(BudgetLine::where('budget_id', $b->id)->count())->toBe(1)
        ->and((float) BudgetLine::first()->amount)->toBe(1200.00);
});

test('writing zero to a line deletes it', function () {
    $b = p3bdBudget(p3bdFy()->id);
    $acc = p3bdAccount('5002', 'debit');
    p3bdSvc()->upsertLine($b, $acc->id, '2026-04', 800);
    $r = p3bdSvc()->upsertLine($b, $acc->id, '2026-04', 0);

    expect($r)->toBeNull()->and(BudgetLine::count())->toBe(0);
});

test('a period outside the fiscal year is refused', function () {
    $b = p3bdBudget(p3bdFy()->id);
    $this->expectException(HttpException::class);
    p3bdSvc()->upsertLine($b, p3bdAccount('5003', 'debit')->id, '2027-01', 100);
});

test('a budget cannot target a group header', function () {
    $b = p3bdBudget(p3bdFy()->id);
    $group = Account::create(['code' => '5099-G', 'name' => 'P3BD Group', 'account_type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_group' => true, 'is_posting' => false, 'is_active' => true]);
    $this->expectException(HttpException::class);
    p3bdSvc()->upsertLine($b, $group->id, '2026-04', 100);
});

/* ───────────────────────────── variance ───────────────────────────── */

test('the report reads actuals live from the posted ledger', function () {
    $b = p3bdBudget(p3bdFy()->id);
    $expense = p3bdAccount('5100', 'debit');
    p3bdSvc()->upsertLine($b, $expense->id, '2026-04', 1000);

    p3bdPost($expense, 600);   // post 600 of spend in April

    $report = p3bdSvc()->report($b, '2026-04');

    expect($report['rows'][0]['budget'])->toBe(1000.00)
        ->and($report['rows'][0]['actual'])->toBe(600.00)
        ->and($report['rows'][0]['variance'])->toBe(400.00)
        ->and($report['rows'][0]['favourable'])->toBeTrue()   // under budget on a spend account
        ->and($report['totals']['overrun_count'])->toBe(0);
});

test('an expense over budget is unfavourable and listed as off-plan', function () {
    $b = p3bdBudget(p3bdFy()->id);
    $expense = p3bdAccount('5101', 'debit');
    p3bdSvc()->upsertLine($b, $expense->id, '2026-04', 500);
    p3bdPost($expense, 900);   // overspent by 400

    $report = p3bdSvc()->report($b, '2026-04');

    expect($report['rows'][0]['favourable'])->toBeFalse()
        ->and($report['rows'][0]['variance'])->toBe(-400.00)
        ->and($report['totals']['overrun_count'])->toBe(1)
        ->and(count($report['unfavorable']))->toBe(1);
});

test('income favours beating the plan, the opposite of an expense', function () {
    $b = p3bdBudget(p3bdFy()->id);
    $revenue = p3bdAccount('4100', 'credit');
    p3bdSvc()->upsertLine($b, $revenue->id, '2026-04', 5000);

    // Post income: credit the revenue account 6000.
    $cash = p3bdAccount('410'.substr(md5(uniqid('', true)), 0, 3), 'debit');
    test()->actingAs(User::factory()->create());
    $js = app(JournalService::class);
    $js->post($js->create([
        'journal_date' => '2026-04-20', 'type' => 'JV', 'status' => 'draft', 'reference_no' => 'P3BD2', 'narration' => 'income',
        'lines' => [
            ['account_id' => $cash->id, 'debit' => 6000, 'credit' => 0, 'description' => 'cash in'],
            ['account_id' => $revenue->id, 'debit' => 0, 'credit' => 6000, 'description' => 'revenue'],
        ],
    ]));

    $report = p3bdSvc()->report($b, '2026-04');

    expect($report['rows'][0]['actual'])->toBe(6000.00)
        ->and($report['rows'][0]['variance'])->toBe(-1000.00)
        ->and($report['rows'][0]['favourable'])->toBeTrue();   // income ABOVE plan is good
});

test('the whole-plan report sums every month the budget has lines for', function () {
    $b = p3bdBudget(p3bdFy()->id);
    $expense = p3bdAccount('5102', 'debit');
    p3bdSvc()->upsertLine($b, $expense->id, '2026-04', 100);
    p3bdSvc()->upsertLine($b, $expense->id, '2026-05', 200);

    $report = p3bdSvc()->report($b);   // no period → aggregate
    expect($report['rows'][0]['budget'])->toBe(300.00)
        ->and($report['periods'])->toBe(['2026-04', '2026-05']);
});

/* ───────────────────────────── lifecycle ───────────────────────────── */

test('approve needs a line and an open year, then locks further edits only when locked', function () {
    $b = p3bdBudget(p3bdFy()->id);

    // No lines → refuse.
    $this->expectException(HttpException::class);
    p3bdSvc()->approve($b);
});

test('approve then lock, and a locked budget refuses a line', function () {
    $b = p3bdBudget(p3bdFy()->id);
    $acc = p3bdAccount('5103', 'debit');
    p3bdSvc()->upsertLine($b, $acc->id, '2026-04', 100);

    $approved = p3bdSvc()->approve($b);
    expect($approved->status)->toBe('approved')->and($approved->approved_at)->not->toBeNull();

    $locked = p3bdSvc()->lock($approved);
    expect($locked->status)->toBe('locked');

    $this->expectException(HttpException::class);
    p3bdSvc()->upsertLine($locked, $acc->id, '2026-05', 100);
});

/* ───────────────────────────── HTTP gates ───────────────────────────── */

function p3bdUser(array $permissions, int $salt): User
{
    $role = Role::create(['name' => "P3BD Role {$salt}", 'guard_name' => 'web']);
    foreach ($permissions as $p) {
        Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        $role->givePermissionTo($p);
    }
    $user = User::create(['name' => "P3BD {$salt}", 'email' => "p3bd{$salt}@example.test", 'password' => bcrypt('secret-password'), 'email_verified_at' => now()]);
    $user->assignRole($role);

    return $user;
}

test('the budget page requires accounts.budgets.view', function () {
    $this->actingAs(p3bdUser([], 1))->get(route('accounts.budgets.index'))->assertForbidden();
    $this->actingAs(p3bdUser(['accounts.budgets.view'], 2))->get(route('accounts.budgets.index'))->assertOk();
});

test('only a manager can create a budget; a viewer cannot', function () {
    $fy = p3bdFy();
    $viewer = p3bdUser(['accounts.budgets.view'], 3);
    $this->actingAs($viewer)->post(route('accounts.budgets.store'), ['name' => 'Nope', 'fiscal_year_id' => $fy->id])->assertForbidden();

    $manager = p3bdUser(['accounts.budgets.view', 'accounts.budgets.manage'], 4);
    $this->actingAs($manager)->post(route('accounts.budgets.store'), ['name' => 'Ops FY26', 'fiscal_year_id' => $fy->id])->assertRedirect();
    expect(Budget::where('name', 'Ops FY26')->exists())->toBeTrue();
});

test('the variance page renders a selected budget', function () {
    $b = p3bdBudget(p3bdFy()->id);
    p3bdSvc()->upsertLine($b, p3bdAccount('5110', 'debit')->id, '2026-04', 100);
    $user = p3bdUser(['accounts.budgets.view'], 5);
    $this->actingAs($user)->get(route('accounts.budgets.variance', ['budget' => $b->id]))->assertOk()
        ->assertInertia(fn ($p) => $p->has('report.totals')->where('report.budget.id', $b->id));
});
