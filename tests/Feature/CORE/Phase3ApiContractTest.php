<?php

/**
 * Phase 3 — F13 API layer.
 *
 * Four contracts matter, and each is tested against the running router rather than a
 * mock of it:
 *
 * 1. a token is the *only* thing standing between curl and the ledger, so the failure
 *    modes are the interesting ones — missing, revoked, expired, and "no Accept header";
 * 2. abilities narrow what a token may do and can never widen what its user may do;
 * 3. money crosses the wire as an exact two-decimal string, everywhere, including the
 *    one place the service hands back floats;
 * 4. the published OpenAPI document is derived from these routes, so an undocumented or
 *    undocumented-in-the-file endpoint fails the build instead of quietly ageing.
 *
 * Helpers are prefixed `p3api*`. Pest loads every Feature file into one process.
 */

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Testing\TestResponse;
use Modules\ACCOUNTS\Models\Account;
use Modules\ACCOUNTS\Models\Budget;
use Modules\ACCOUNTS\Models\FiscalYear;
use Modules\ACCOUNTS\Models\Journal;
use Modules\ACCOUNTS\Services\BudgetService;
use Modules\ACCOUNTS\Services\JournalService;
use Modules\CORE\Models\User;
use Modules\CORE\Services\ApiTokenService;
use Modules\INVENTORY\Models\Warehouse;
use Modules\PARTNER\Models\Partner;
use Modules\SALES\Models\Invoice;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function p3apiSvc(): ApiTokenService
{
    return app(ApiTokenService::class);
}

function p3apiUser(string $email, array $permissions, int $salt): array
{
    $role = Role::create(['name' => "P3API Role {$salt}", 'guard_name' => 'web']);
    foreach ($permissions as $p) {
        Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        $role->givePermissionTo($p);
    }

    $user = User::create([
        'name' => "P3API {$salt}",
        'email' => $email,
        'password' => 'secret-password',
        'email_verified_at' => now(),
    ]);
    $user->assignRole($role);

    return [$user->fresh(), 'secret-password'];
}

/**
 * One request, made the way a client would make it.
 *
 * A test process reuses its container between calls, so `auth` hands back the guard it
 * resolved for the *previous* request — and a token revoked one line earlier would then
 * appear to keep working. Forgetting the resolved guards is what makes "the next request"
 * actually mean the next request.
 */
function p3api(?string $token, string $method, string $url, array $body = []): TestResponse
{
    test()->app('auth')->forgetGuards();

    $request = $token === null ? test() : test()->withToken($token);

    return match (strtoupper($method)) {
        'GET' => $request->getJson($url),
        'DELETE' => $request->deleteJson($url),
        default => $request->postJson($url, $body),
    };
}

/** A real bearer token, issued through the service the UI uses. */
function p3apiBearer(User $user, array $abilities = ['*']): string
{
    return p3apiSvc()->issue($user, 'test', $abilities)['token'];
}

function p3apiInvoice(): Invoice
{
    $customer = Partner::create(['partner_code' => 'CUS-API-1', 'type' => 'customer', 'name' => 'Apex Retail']);
    $warehouse = Warehouse::create(['name' => 'Main Warehouse', 'code' => 'WH-API-1']);

    return Invoice::create([
        'invoice_no' => 'INV-API-1',
        'customer_id' => $customer->id,
        'warehouse_id' => $warehouse->id,
        'invoice_date' => '2026-11-08',
        'subtotal' => '1000.00',
        'vat_amount' => '234.56',
        'total_amount' => '1234.56',
        'paid_amount' => '0.00',
        'status' => 'draft',
    ]);
}

/* ───────────────────────── the token exchange ───────────────────────── */

test('credentials exchange for a usable bearer token, prefixed and returned once', function () {
    [$user] = p3apiUser('p3api-issue@example.test', ['sales.invoices.view'], 1);

    $res = $this->postJson('/api/v1/auth/token', [
        'email' => $user->email,
        'password' => 'secret-password',
        'device_name' => 'ci',
    ])->assertCreated();

    $token = $res->json('token');
    // The wire form is `id|secret`; the prefix belongs to the secret, so it starts
    // *after* the pipe. Asserting the whole string begins with `erp_` is the mistake a
    // reader of the config would make, and the guard does not strip anything.
    expect($token)->toContain('|erp_')
        ->and($res->json('warning'))->toContain('cannot be retrieved later');

    // And it actually authenticates, which is the only thing the shape is worth.
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()
        ->assertJsonPath('email', $user->email);
});

test('an unknown address and a wrong password are indistinguishable', function () {
    [, $password] = p3apiUser('p3api-refuse@example.test', [], 2);

    $wrong = $this->postJson('/api/v1/auth/token', ['email' => 'p3api-refuse@example.test', 'password' => 'nope', 'device_name' => 'x']);
    $unknown = $this->postJson('/api/v1/auth/token', ['email' => 'nobody@example.test', 'password' => $password, 'device_name' => 'x']);

    $wrong->assertStatus(401);
    $unknown->assertStatus(401);
    expect($wrong->json('message'))->toBe($unknown->json('message'));
});

test('the token exchange is rate limited far tighter than the API it feeds', function () {
    p3apiUser('p3api-limit@example.test', [], 3);

    foreach (range(1, 5) as $ignored) {
        $this->postJson('/api/v1/auth/token', ['email' => 'p3api-limit@example.test', 'password' => 'bad', 'device_name' => 'x'])->assertStatus(401);
    }

    $this->postJson('/api/v1/auth/token', ['email' => 'p3api-limit@example.test', 'password' => 'bad', 'device_name' => 'x'])
        ->assertStatus(429);
});

/* ───────────────────────── authentication failure modes ───────────────────────── */

test('a request with no or wildcard Accept still gets 401 JSON, never a redirect to the login form', function () {
    // The whole reason ForceApiJson exists. `wantsJson()` says yes only when the *first*
    // acceptable type mentions json, so both shapes below would be answered with
    // `302 → /login`: an empty Accept, and the `*/*` that curl sends by default.
    //
    // The headers are stated explicitly because Symfony's Request::create() invents a
    // browser-ish `text/html,application/xhtml+xml,…` Accept when the caller omits one —
    // so a test that "sends nothing" is really sending a preference, and would prove
    // nothing about the case this middleware exists for.
    foreach (['' => 'empty Accept', '*/*' => 'curl default'] as $accept => $why) {
        $res = $this->call('GET', '/api/v1/auth/me', [], [], [], ['HTTP_ACCEPT' => $accept]);

        expect($res->getStatusCode())->toBe(401, $why.' must not redirect')
            ->and($res->headers->get('content-type'))->toContain('application/json');
    }

    // A caller that named something specific is not overruled. It gets the redirect its
    // own header asked for, which is the correct answer to a wrong question, and the
    // reason this middleware narrows to the no-preference shapes instead of forcing json.
    expect($this->call('GET', '/api/v1/auth/me', [], [], [], ['HTTP_ACCEPT' => 'text/html'])->getStatusCode())->toBe(302);
});

test('a revoked token stops working on the next request, not at some future expiry', function () {
    [$user] = p3apiUser('p3api-revoke@example.test', ['sales.invoices.view'], 4);
    $token = p3apiBearer($user);

    p3api($token, 'get', '/api/v1/sales/invoices')->assertOk();
    p3api($token, 'delete', '/api/v1/auth/token')->assertOk()->assertJson(['revoked' => true]);

    p3api($token, 'get', '/api/v1/sales/invoices')->assertUnauthorized();
});

test('an expired token is refused', function () {
    [$user] = p3apiUser('p3api-expired@example.test', ['sales.invoices.view'], 5);
    $plain = $user->createToken('p3', ['*'], now()->subMinute());

    expect(401)->toBe($this->withToken($plain->plainTextToken)->getJson('/api/v1/sales/invoices')->status());
});

test('deleting a user revokes their tokens, because a morph column cannot hold that foreign key', function () {
    [$user] = p3apiUser('p3api-delete@example.test', ['sales.invoices.view'], 6);
    $token = p3apiBearer($user);

    p3api($token, 'get', '/api/v1/auth/me')->assertOk();

    $user->delete();

    p3api($token, 'get', '/api/v1/auth/me')->assertUnauthorized();
    expect(DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count())->toBe(0);
});

/* ───────────────────────── abilities narrow, roles authorise ───────────────────────── */

test('a token may do less than its user, and that is the point', function () {
    [$user] = p3apiUser('p3api-narrow@example.test', ['sales.invoices.view', 'accounts.reports.view'], 7);

    // An accounts-only token cannot read invoices even though its owner can.
    $narrow = p3apiBearer($user, ['accounts:read']);
    p3api($narrow, 'get', '/api/v1/sales/invoices')->assertForbidden();
    p3api($narrow, 'get', '/api/v1/accounts/trial-balance')->assertOk();

    // And the wildcard token can do everything the ACL allows.
    $wide = p3apiBearer($user, ['*']);
    p3api($wide, 'get', '/api/v1/sales/invoices')->assertOk();
});

test('the read preset expands to the read abilities and no write, at issue time', function () {
    [$user] = p3apiUser('p3api-preset@example.test', ['accounts.journals.create'], 8);
    $issued = p3apiSvc()->issue($user, 'ci', ['read']);

    expect($issued['abilities'])->toBe(['accounts:read', 'sales:read']);

    // `read` cannot post a journal, even though the user holds accounts.journals.create.
    p3api($issued['token'], 'post', '/api/v1/accounts/journals', [
        'journal_date' => '2026-11-08', 'type' => 'JV',
        'lines' => [['account_id' => 1, 'debit' => '10.00'], ['account_id' => 1, 'credit' => '10.00']],
    ])->assertForbidden();
});

test('write implies read, so one token suffices and there is one row to revoke', function () {
    [$user] = p3apiUser('p3api-imply@example.test', ['accounts.reports.view'], 9);
    $issued = p3apiSvc()->issue($user, 'ci', ['accounts:write']);

    expect($issued['abilities'])->toBe(['accounts:read', 'accounts:write']);
});

test('the API enforces the same ACL as the screen, not a second copy of it', function () {
    [$noBooks] = p3apiUser('p3api-noacl@example.test', [], 10);
    $token = p3apiBearer($noBooks, ['*']);

    // A valid token, wildcard abilities, no permission: the route middleware decides.
    p3api($token, 'get', '/api/v1/accounts/trial-balance')->assertForbidden();
    p3api($token, 'get', '/api/v1/sales/invoices')->assertForbidden();
});

/* ───────────────────────── money on the wire ───────────────────────── */

test('amounts are exact two-decimal strings, including where the service returns floats', function () {
    [$user] = p3apiUser('p3api-money@example.test', ['sales.invoices.view'], 11);
    p3apiInvoice();
    $token = p3apiBearer($user);

    $list = p3api($token, 'get', '/api/v1/sales/invoices')->assertOk();
    $row = $list->json('data.0');

    // A float in JSON is how an integration ends up one paisa short. Assert the type,
    // not just the value — `1234.56` and `'1234.56'` are the same number and different contracts.
    expect($row['total_amount'])->toBe('1234.56')->toBeString()
        ->and($row['paid_amount'])->toBe('0.00')->toBeString()
        ->and($list->json('meta.total'))->toBe(1);

    $byNumber = p3api($token, 'get', '/api/v1/sales/invoices/INV-API-1')->assertOk();
    expect($byNumber->json('total_amount'))->toBe('1234.56');
});

test('the budget variance report converts its floats at the boundary', function () {
    [$user] = p3apiUser('p3api-variance@example.test', ['accounts.budgets.view'], 12);

    $fy = FiscalYear::create(['name' => 'P3API FY', 'code' => 'P3API-'.uniqid(), 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
    $account = Account::create(['code' => '5901', 'name' => 'P3API Expense', 'account_type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_group' => false, 'is_posting' => true, 'is_active' => true]);
    $budget = Budget::create(['name' => 'P3API Budget '.uniqid(), 'code' => 'BDG-API-'.uniqid(), 'fiscal_year_id' => $fy->id, 'status' => 'draft']);
    app(BudgetService::class)->upsertLine($budget, $account->id, '2026-04', 1500.5);

    // The service itself yields floats — that is what the Vue page wants.
    expect(app(BudgetService::class)->report($budget, '2026-04')['rows'][0]['budget'])->toBeFloat();

    $res = p3api(p3apiBearer($user), 'get', "/api/v1/accounts/budgets/{$budget->id}/variance?period=2026-04")->assertOk();

    expect($res->json('rows.0.budget'))->toBe('1500.50')->toBeString()
        ->and($res->json('totals.budget'))->toBe('1500.50')->toBeString()
        // A percentage is not an amount, so it stays a number — and with nothing posted
        // yet it is a whole 0, which is still not a string.
        ->and($res->json('rows.0.pct'))->not->toBeString();
});

test('the trial balance carries its sign convention rather than leaving it to be remembered', function () {
    [$user] = p3apiUser('p3api-tb@example.test', ['accounts.reports.view'], 13);

    // Post one real journal through the ledger so the rows are not vacuous: an empty
    // register would pass any loop over it.
    $fy = FiscalYear::create(['name' => 'P3API TB', 'code' => 'P3APITB-'.uniqid(), 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
    $expense = Account::create(['code' => '5921', 'name' => 'P3API TB Expense', 'account_type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_group' => false, 'is_posting' => true, 'is_active' => true]);
    $asset = Account::create(['code' => '1921', 'name' => 'P3API TB Bank', 'account_type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_group' => false, 'is_posting' => true, 'is_active' => true]);
    $js = app(JournalService::class);
    $js->post($js->create([
        'journal_date' => '2026-04-15', 'type' => 'JV', 'status' => 'draft', 'reference_no' => 'P3API-TB', 'narration' => 'tb',
        'lines' => [
            ['account_id' => $expense->id, 'debit' => 742.19, 'credit' => 0, 'description' => 'spend'],
            ['account_id' => $asset->id, 'debit' => 0, 'credit' => 742.19, 'description' => 'bank'],
        ],
    ]));

    $res = p3api(p3apiBearer($user), 'get', '/api/v1/accounts/trial-balance?as_of=2026-04-30')->assertOk();

    expect($res->json('as_of'))->toBe('2026-04-30')->and($res->json('rows'))->toHaveCount(2);
    foreach ($res->json('rows') as $row) {
        expect($row['balance'])->toBeString()->toMatch('/^-?\d+\.\d{2}$/')
            ->and($row)->toHaveKey('nature')->toHaveKey('balance_side');
    }
    // Both debit-nature accounts carry a positive balance on their own side.
    expect(collect($res->json('rows'))->firstWhere('code', '5921')['balance'])->toBe('742.19');
});

/* ───────────────────────── the one write endpoint ───────────────────────── */

test('a journal created over the API is a draft, and posting is not offered', function () {
    [$user] = p3apiUser('p3api-journal@example.test', ['accounts.journals.create'], 14);
    FiscalYear::create(['name' => 'P3API FY26', 'code' => 'P3APIJ-'.uniqid(), 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
    $debit = Account::create(['code' => '5911', 'name' => 'P3API Dr', 'account_type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_group' => false, 'is_posting' => true, 'is_active' => true]);
    $credit = Account::create(['code' => '1911', 'name' => 'P3API Cr', 'account_type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_group' => false, 'is_posting' => true, 'is_active' => true]);
    $token = p3apiBearer($user, ['accounts:write']);

    $res = $this->withToken($token)->postJson('/api/v1/accounts/journals', [
        'journal_date' => '2026-11-08',
        'type' => 'JV',
        'reference_no' => 'API-DR-1',
        'narration' => 'from the API',
        'lines' => [
            ['account_id' => $debit->id, 'debit' => '250.00', 'credit' => '0.00'],
            ['account_id' => $credit->id, 'debit' => '0.00', 'credit' => '250.00'],
        ],
    ])->assertCreated();

    expect($res->json('status'))->toBe('draft')
        ->and($res->json('line_count'))->toBe(2)
        ->and($res->json('next_step'))->toContain('not available over v1')
        ->and(Journal::find($res->json('id'))->status)->toBe('draft');
});

test('an unbalanced journal is refused by the same validator the screen uses, before anything is written', function () {
    [$user] = p3apiUser('p3api-unbalanced@example.test', ['accounts.journals.create'], 15);
    $debit = Account::create(['code' => '5912', 'name' => 'P3API Dr2', 'account_type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_group' => false, 'is_posting' => true, 'is_active' => true]);

    $before = Journal::count();

    $this->withToken(p3apiBearer($user, ['accounts:write']))->postJson('/api/v1/accounts/journals', [
        'journal_date' => '2026-11-08', 'type' => 'JV',
        'lines' => [['account_id' => $debit->id, 'debit' => '10.00', 'credit' => '0.00'], ['account_id' => $debit->id, 'debit' => '11.00', 'credit' => '0.00']],
    ])->assertStatus(422)->assertJsonValidationErrors('lines');

    expect(Journal::count())->toBe($before);
});

test('a domain refusal over the API is 422 with the screen\'s sentence, not a 500 page', function () {
    [$user] = p3apiUser('p3api-nofy@example.test', ['accounts.journals.create'], 19);
    $debit = Account::create(['code' => '5913', 'name' => 'P3API Dr3', 'account_type' => 'expense', 'nature' => 'debit', 'level' => 1, 'is_group' => false, 'is_posting' => true, 'is_active' => true]);
    $credit = Account::create(['code' => '1913', 'name' => 'P3API Cr3', 'account_type' => 'asset', 'nature' => 'debit', 'level' => 1, 'is_group' => false, 'is_posting' => true, 'is_active' => true]);

    // No fiscal year covers the date. Posting-domain rules throw DomainException, which
    // the browser UI renders as a 500; a machine client must not be told to retry.
    $res = p3api(p3apiBearer($user, ['accounts:write']), 'post', '/api/v1/accounts/journals', [
        'journal_date' => '2031-11-08', 'type' => 'JV',
        'lines' => [
            ['account_id' => $debit->id, 'debit' => '250.00', 'credit' => '0.00'],
            ['account_id' => $credit->id, 'debit' => '0.00', 'credit' => '250.00'],
        ],
    ])->assertStatus(422);

    expect($res->json('message'))->toContain('fiscal year')->and($res->json('errors.journal.0'))->toContain('fiscal year');
});

/* ───────────────────────── the published contract ───────────────────────── */

test('every v1 route documents itself, or the spec does not build', function () {
    $documented = collect(json_decode(file_get_contents(base_path('docs/openapi.json')), true)['paths'])
        ->flatMap(fn ($ops, $path) => collect(array_keys($ops))->map(fn ($m) => ltrim($path, '/')))
        ->unique();

    $live = collect(RouteFacade::getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1'))
        ->map(fn ($r) => $r->uri())
        ->unique();

    expect($live->diff($documented)->values())->toBeEmpty('a v1 route is missing from docs/openapi.json — regenerate the spec');
});

test('the committed spec is byte-identical to the routes, so a route change without a regenerated file fails', function () {
    expect(Artisan::call('api:spec', ['--check' => true]))->toBe(0)
        ->and(trim(Artisan::output()))->toContain('matches the routes');
});

test('the spec publishes the enforcement, not a copy of it', function () {
    $spec = json_decode(file_get_contents(base_path('docs/openapi.json')), true);
    $op = $spec['paths']['/api/v1/accounts/journals']['post'];

    // These strings live in the route file's middleware; the builder copied them.
    expect($op['x-required-permission'])->toBe('accounts.journals.create')
        ->and($op['x-required-ability'])->toBe('accounts:write')
        ->and($op['x-middleware'])->toContain('auth:sanctum')
        ->and($op['security'])->toBe([['bearerAuth' => []]])
        ->and($op['responses'])->toHaveKey('201')->toHaveKey('429');

    // The unauthenticated exchange says so instead of pretending to be open.
    expect($spec['paths']['/api/v1/auth/token']['post']['security'])->toBeEmpty();
});

test('the machine-readable spec is served, and is the same document the page renders', function () {
    $this->getJson('/api/v1/openapi.json')->assertOk()->assertHeader('content-type', 'application/json');

    $served = json_decode(file_get_contents(base_path('docs/openapi.json')), true);
    expect($served['openapi'])->toBe('3.1.0')->and($served['info']['version'])->toBe('1.0.0');
});

/* ───────────────────────── the self-service screen ───────────────────────── */

test('any signed-in user can manage their own tokens, and cannot touch anyone else\'s', function () {
    [$owner] = p3apiUser('p3api-owner@example.test', [], 16);
    [$other] = p3apiUser('p3api-other@example.test', [], 17);
    $issued = p3apiSvc()->issue($owner, 'owner token');

    test()->app('auth')->forgetGuards();
    $this->actingAs($other)->delete(route('core.developer.tokens.destroy', $issued['id']))
        ->assertSessionHasErrors('token');
    expect(DB::table('personal_access_tokens')->where('id', $issued['id'])->exists())->toBeTrue();

    $this->actingAs($owner)->post(route('core.developer.tokens.store'), ['name' => 'my ci', 'abilities' => ['read']])
        ->assertRedirect(route('core.developer.tokens.index'))
        ->assertSessionHas('api_token');

    // The secret rides along on the very next render — that is the one-time reveal —
    // and is gone after it, because the controller `pull`s it from flash.
    $this->actingAs($owner)->get(route('core.developer.tokens.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->has('tokens')->has('issued'));
    $this->actingAs($owner)->get(route('core.developer.tokens.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('issued', null));

    $this->actingAs($owner)->delete(route('core.developer.tokens.destroy', $issued['id']))->assertRedirect();
    expect(DB::table('personal_access_tokens')->where('id', $issued['id'])->exists())->toBeFalse();
});

test('a browser session cannot revoke itself through the API, because it has no token', function () {
    [$user] = p3apiUser('p3api-session@example.test', [], 18);

    $this->actingAs($user)->deleteJson('/api/v1/auth/token')->assertStatus(400);
});
