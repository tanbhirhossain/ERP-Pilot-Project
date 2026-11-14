<?php

/**
 * Phase 3 — F15 fixed assets & depreciation.
 *
 * Pins the two contracts the feature rests on: the per-asset charge (straight line and declining
 * balance, pro-rated by days, capped at salvage), and the monthly run (one balanced journal,
 * idempotent per period, all-or-nothing against a misconfigured category). Plus the HTTP gates.
 *
 * The math runs against `DepreciationService::computeCharge` directly (no ledger), so a rounding or
 * pro-rata regression shows up as a wrong cent, not a masked posting error. The run tests use the
 * same fiscal + account fixtures `JournalPostingDomainTest` establishes.
 *
 * Helpers are prefixed `p3fa*`.
 */

use Modules\ACCOUNTS\Models\Account;
use Modules\ACCOUNTS\Models\AssetCategory;
use Modules\ACCOUNTS\Models\DepreciationRun;
use Modules\ACCOUNTS\Models\FiscalYear;
use Modules\ACCOUNTS\Models\FixedAsset;
use Modules\ACCOUNTS\Services\DepreciationService;
use Modules\CORE\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

function p3faSvc(): DepreciationService
{
    return app(DepreciationService::class);
}

/** A leaf posting account; `code` is UNIQUE on acc_accounts, so callers pass distinct codes. */
function p3faAccount(string $code, string $nature): Account
{
    return Account::create([
        'code' => $code, 'name' => 'P3FA '.$code, 'account_type' => $nature === 'credit' ? 'income' : 'asset',
        'nature' => $nature, 'level' => 1, 'is_group' => false, 'is_posting' => true,
        'is_active' => true, 'currency' => 'BDT', 'opening_balance' => 0,
    ]);
}

function p3faFiscalYear(): void
{
    FiscalYear::create([
        'name' => 'P3FA FY', 'code' => 'P3FA-FY-'.uniqid(),
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open',
    ]);
}

/**
 * A category wired to three posting accounts. The account codes are derived from a global counter so
 * one test creating several categories never collides on the unique `code`.
 */
function p3faCategory(array $overrides = []): AssetCategory
{
    static $n = 0;
    $n++;
    $base = 9500 + $n;

    return AssetCategory::create($overrides + [
        'name' => "P3FA Cat {$n}", 'code' => "P3FAC{$n}",
        'asset_account_id' => p3faAccount((string) $base, 'debit')->id,
        'accum_dep_account_id' => p3faAccount((string) ($base + 100), 'credit')->id,
        'dep_expense_account_id' => p3faAccount((string) ($base + 200), 'debit')->id,
        'default_method' => 'straight_line', 'default_useful_life_years' => 10,
        'default_salvage_pct' => 0, 'default_db_factor' => 2, 'is_active' => true,
    ]);
}

/** An in-use asset with its opening NBV already set, bypassing capitalise for the pure-math tests. */
function p3faAsset(AssetCategory $cat, array $attrs = []): FixedAsset
{
    $cost = $attrs['cost'] ?? 120000;

    return FixedAsset::create($attrs + [
        'asset_code' => 'P3FA-'.uniqid(), 'name' => 'P3FA asset', 'category_id' => $cat->id,
        'purchase_date' => '2026-01-01', 'in_service_date' => '2026-01-01', 'cost' => $cost,
        'salvage_value' => 0, 'method' => 'straight_line', 'useful_life_years' => 10, 'db_factor' => 2,
        'accumulated_dep' => 0, 'net_book_value' => $cost, 'status' => 'in_use',
    ]);
}

/* ───────────────────────────── charge math ───────────────────────────── */

test('straight line charges (cost − salvage) / life / 12 for a full month', function () {
    $a = p3faAsset(p3faCategory(), ['cost' => 120000, 'salvage_value' => 0, 'useful_life_years' => 10]);
    $r = p3faSvc()->computeCharge($a, '2026-04');   // 120000/10/12 = 1000

    expect($r['ok'])->toBeTrue()->and($r['charge'])->toBe(1000.00)
        ->and($r['closing_nbv'])->toBe(119000.00)->and($r['fully'])->toBeFalse();
});

test('the acquisition month is pro-rated by days in service', function () {
    $a = p3faAsset(p3faCategory(), ['cost' => 120000, 'useful_life_years' => 10, 'in_service_date' => '2026-04-16']);
    // 15 of 30 days in April → half a month's 1000.
    $r = p3faSvc()->computeCharge($a, '2026-04');

    expect($r['charge'])->toBe(500.00);
});

test('declining balance charges NBV × (factor/life)/12', function () {
    $a = p3faAsset(p3faCategory(), ['cost' => 100000, 'method' => 'declining_balance', 'useful_life_years' => 5, 'db_factor' => 2]);
    // 100000 × (2/5) / 12 = 3333.33
    $r = p3faSvc()->computeCharge($a, '2026-04');

    expect($r['charge'])->toBe(3333.33)->and($r['closing_nbv'])->toBe(96666.67);
});

test('the charge is capped so net book value never dips below salvage', function () {
    $a = p3faAsset(p3faCategory(), ['cost' => 100000, 'salvage_value' => 90000, 'useful_life_years' => 5, 'accumulated_dep' => 9900, 'net_book_value' => 1000]);
    // depreciable base 10000, 9900 already taken → 100 left, even though a month is ~166.67.
    $r = p3faSvc()->computeCharge($a, '2026-04');

    expect($r['charge'])->toBe(100.00)->and($r['fully'])->toBeTrue()
        ->and($r['opening_nbv'])->toBe(90100.00)  // cost 100000 − accumulated 9900
        ->and($r['closing_nbv'])->toBe(90000.00);  // floored at salvage
});

test('a fully depreciated asset charges nothing', function () {
    $a = p3faAsset(p3faCategory(), ['cost' => 100000, 'salvage_value' => 0, 'useful_life_years' => 1, 'accumulated_dep' => 100000, 'net_book_value' => 0]);
    expect(p3faSvc()->computeCharge($a, '2026-04')['ok'])->toBeFalse();
});

test('an asset not yet in service charges nothing', function () {
    $a = p3faAsset(p3faCategory(), ['in_service_date' => '2026-12-15']);
    expect(p3faSvc()->computeCharge($a, '2026-04')['ok'])->toBeFalse();
});

test('a disposed asset charges nothing after its disposal month', function () {
    $a = p3faAsset(p3faCategory());
    p3faSvc()->dispose($a, '2026-03-15', 5000);
    expect(p3faSvc()->computeCharge($a->refresh(), '2026-04')['ok'])->toBeFalse();
});

/* ───────────────────────────── the run ───────────────────────────── */

test('a run charges the register and posts one balanced journal', function () {
    p3faFiscalYear();
    $cat = p3faCategory();
    $a = p3faAsset($cat, ['cost' => 120000, 'useful_life_years' => 10]);

    $run = p3faSvc()->run('2026-04');

    expect($run->line_count)->toBe(1)->and((float) $run->total_amount)->toBe(1000.00);

    $a->refresh();
    expect((float) $a->accumulated_dep)->toBe(1000.00)->and((float) $a->net_book_value)->toBe(119000.00);

    $journal = $run->journal()->first();
    expect($journal)->not->toBeNull()
        ->and($journal->status)->toBe('posted')
        ->and($journal->isBalanced())->toBeTrue()
        ->and($journal->lines()->count())->toBe(2);
});

test('a period cannot be run twice', function () {
    p3faFiscalYear();
    $cat = p3faCategory();
    p3faAsset($cat);
    p3faSvc()->run('2026-04');

    $this->expectException(HttpException::class);
    p3faSvc()->run('2026-04');
});

test('a misconfigured category account rolls the whole run back — no half journal', function () {
    p3faFiscalYear();
    $cat = p3faCategory();
    // Point the expense account at a group header, which cannot be posted to.
    $group = Account::create(['code' => '9600-G', 'name' => 'P3FA Group', 'account_type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_group' => true, 'is_posting' => false, 'is_active' => true]);
    $cat->forceFill(['dep_expense_account_id' => $group->id])->save();
    p3faAsset($cat);

    try {
        p3faSvc()->run('2026-04');
        $this->fail('expected a posting refusal');
    } catch (DomainException $e) {
        expect(DepreciationRun::count())->toBe(0)->and($e->getMessage())->not->toBe('');
    }
});

test('an empty period still closes off with a zero run', function () {
    p3faFiscalYear();
    $run = p3faSvc()->run('2026-04');   // no in-use assets
    expect($run->line_count)->toBe(0)->and((float) $run->total_amount)->toBe(0.0)
        ->and($run->journal_id)->toBeNull();
});

test('capitalise freezes the category defaults and opens the asset to depreciation', function () {
    $cat = p3faCategory(['default_method' => 'declining_balance', 'default_salvage_pct' => 10]);
    $a = FixedAsset::create(['asset_code' => 'P3FA-D'.uniqid(), 'name' => 'draft', 'category_id' => $cat->id, 'purchase_date' => '2026-01-01', 'cost' => 100000, 'status' => 'draft']);

    $live = p3faSvc()->capitalize($a);

    expect($live->status)->toBe('in_use')->and($live->method)->toBe('declining_balance')
        ->and((float) $live->net_book_value)->toBe(100000.00)
        // 10% of 100000
        ->and((float) $live->salvage_value)->toBe(10000.00);
});

/* ───────────────────────────── HTTP gates ───────────────────────────── */

function p3faUser(array $permissions, int $salt): User
{
    $role = Role::create(['name' => "P3FA Role {$salt}", 'guard_name' => 'web']);
    foreach ($permissions as $p) {
        Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        $role->givePermissionTo($p);
    }
    $user = User::create(['name' => "P3FA {$salt}", 'email' => "p3fa{$salt}@example.test", 'password' => bcrypt('secret-password'), 'email_verified_at' => now()]);
    $user->assignRole($role);

    return $user;
}

test('the asset register requires accounts.assets.view', function () {
    $this->actingAs(p3faUser([], 1))->get(route('accounts.assets.index'))->assertForbidden();
    $this->actingAs(p3faUser(['accounts.assets.view'], 2))->get(route('accounts.assets.index'))->assertOk();
});

test('only a manager can run depreciation', function () {
    p3faFiscalYear();
    $viewer = p3faUser(['accounts.assets.view'], 3);
    $this->actingAs($viewer)->post(route('accounts.assets.depreciation.run'), ['period' => '2026-04'])->assertForbidden();

    $manager = p3faUser(['accounts.assets.view', 'accounts.assets.manage'], 4);
    $this->actingAs($manager)->post(route('accounts.assets.depreciation.run'), ['period' => '2026-04'])->assertRedirect();
    expect(DepreciationRun::where('period', '2026-04')->exists())->toBeTrue();
});

test('a drafted asset can be created and appears with a generated code', function () {
    $cat = p3faCategory();
    $manager = p3faUser(['accounts.assets.view', 'accounts.assets.manage'], 5);
    $this->actingAs($manager)->post(route('accounts.assets.store'), [
        'name' => 'Delivery Van', 'category_id' => $cat->id, 'purchase_date' => '2026-05-01', 'cost' => 2500000,
    ])->assertRedirect();

    $asset = FixedAsset::where('name', 'Delivery Van')->first();
    expect($asset)->not->toBeNull()->and($asset->status)->toBe('draft')
        ->and($asset->asset_code)->toMatch('/^FA-\d{4}-\d{4}$/');
});
