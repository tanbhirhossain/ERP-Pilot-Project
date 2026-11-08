<?php

/**
 * Authorisation of the `api/options/{type}` typeahead endpoints, and of the POS product lookup.
 *
 * Five of the seven modules served their reference data behind `['web', 'auth']` and nothing else,
 * so any authenticated user could enumerate:
 *
 *   - every bank account **number** (`accounts/api/options/banks` puts `account_no` in the label),
 *   - the whole posting chart of accounts,
 *   - the entire customer and vendor list **with phone numbers** (`PartnerRepository::options()`
 *     selects `legal_name` and `phone` and emits the phone as the description),
 *   - every product **with its selling price** (`ProductRepository::options()` emits `meta`/`price`
 *     and selects `cost_price` as well),
 *   - live stock levels and prices one barcode at a time through `inventory/api/pos-product/{key}`.
 *
 * The exposure was invisible from the route list, which is where an audit normally looks: HRM was
 * gated at the route level, and CORE and PURCHASE gated per type *inside the controller*. Reading
 * only the route files under each module shows two protected endpoints and six wide open ones;
 * the controllers shows the opposite for CORE. Both layers have to be read.
 *
 * HRM had the mirror-image defect. Its route carried a single coarse `hrm.view`, while every HRM
 * *page* is gated on a specific permission — `leaves/index` needs `hrm.leaves.view`. The two seeded
 * operational roles happen to hold `hrm.view`, so nothing broke in the demo; a custom role granted
 * only `hrm.leaves.view` could open `LeaveRequests/Create.vue` and get a 403 from its own employee
 * picker, which renders as an empty dropdown rather than an error. Test 8 pins the fix.
 *
 * Helper names are prefixed because Pest loads every Feature file into one process, and the file is
 * self-contained because Pest only loads the helpers of the files it is asked to run.
 */

use Illuminate\Support\Facades\Route;
use Modules\ACCOUNTS\Database\Seeders\AccountsPermissionSeeder;
use Modules\ACCOUNTS\Models\Account;
use Modules\ACCOUNTS\Models\Bank;
use Modules\CORE\Database\Seeders\CoreSeeder;
use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\CORE\Http\Controllers\OptionsController;
use Modules\CORE\Models\Permission;
use Modules\CORE\Models\Role;
use Modules\CORE\Models\User;
use Modules\CORE\Support\OptionAccess;
use Modules\HRM\Database\Seeders\HRMPermissionSeeder;
use Modules\INVENTORY\Database\Seeders\InventoryPermissionSeeder;
use Modules\INVENTORY\Http\Controllers\PosLookupController;
use Modules\INVENTORY\Models\Product;
use Modules\PARTNER\Database\Seeders\PartnerPermissionSeeder;
use Modules\PAYROLL\Database\Seeders\PAYROLLPermissionSeeder;
use Modules\PURCHASE\Database\Seeders\PurchasePermissionSeeder;
use Modules\SALES\Database\Seeders\SalesPermissionSeeder;

/**
 * Controller => the route name that serves it. `PosLookupController` takes `{key}` rather than
 * `{type}`, and its single entry is keyed `pos-product` so it can be driven by the same loops.
 */
function optAuthEndpoints(): array
{
    return [
        OptionsController::class => 'core.api.options',
        Modules\PARTNER\Http\Controllers\OptionsController::class => 'partner.api.options',
        Modules\ACCOUNTS\Http\Controllers\OptionsController::class => 'accounts.api.options',
        Modules\SALES\Http\Controllers\OptionsController::class => 'sales.api.options',
        Modules\INVENTORY\Http\Controllers\OptionsController::class => 'inventory.api.options',
        Modules\PURCHASE\Http\Controllers\OptionsController::class => 'purchase.api.options',
        Modules\HRM\Http\Controllers\OptionsController::class => 'hrm.api.options',
        PosLookupController::class => 'inventory.api.pos-product',
    ];
}

/** Every declared type of every controller, as [controller, route name, type, permissions]. */
function optAuthMatrix(): array
{
    $rows = [];

    foreach (optAuthEndpoints() as $controller => $routeName) {
        $constant = (new ReflectionClass($controller))->getReflectionConstant('PERMISSIONS');

        expect($constant)->not->toBeFalse("{$controller} declares no PERMISSIONS map, so it cannot be gated");

        foreach ((array) $constant->getValue() as $type => $permissions) {
            $rows[] = [$controller, $routeName, (string) $type, array_values((array) $permissions)];
        }
    }

    // A scan that finds nothing passes vacuously.
    expect($rows)->toHaveCount(38, 'the option-type matrix drifted; update this test deliberately');

    return $rows;
}

function optAuthSeedPermissions(): void
{
    test()->seed([
        CoreSeeder::class,
        HRMPermissionSeeder::class,
        PAYROLLPermissionSeeder::class,
        PartnerPermissionSeeder::class,
        InventoryPermissionSeeder::class,
        AccountsPermissionSeeder::class,
        SalesPermissionSeeder::class,
        PurchasePermissionSeeder::class,
    ]);
}

function optAuthAdmin(): User
{
    test()->seed(SuperAdminSeeder::class);

    $admin = User::where('email', config('core.super_admin.email', 'admin@example.com'))->first();
    expect($admin)->not->toBeNull('SuperAdminSeeder produced no admin user');

    return $admin;
}

/** A user holding exactly one permission — the shape of a real, narrowly-scoped role. */
function optAuthUserWith(string $permission, int $salt): User
{
    $role = Role::create(['name' => "OptAuth Role {$salt}", 'guard_name' => 'web']);
    $role->givePermissionTo($permission);

    $user = User::create([
        'name' => "Opt Auth {$salt}",
        'email' => "optauth{$salt}@example.test",
        'password' => bcrypt('not-the-default'),
        'email_verified_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

function optAuthUrl(string $routeName, string $type): string
{
    return $routeName === 'inventory.api.pos-product'
        ? route($routeName, ['key' => $type])
        : route($routeName, ['type' => $type]);
}

// ─────────────────────────── the gate itself ───────────────────────────

test('every option type answers the super admin with a readable envelope', function () {
    optAuthSeedPermissions();
    $this->actingAs(optAuthAdmin());

    $failures = [];

    foreach (optAuthMatrix() as [$controller, $routeName, $type]) {
        $response = $this->getJson(optAuthUrl($routeName, $type));

        if ($response->status() !== 200) {
            // A type present in the map but absent from the controller's `match` lands here as a
            // 500, which is the drift the deny-by-default gate is meant to surface.
            $failures[] = "{$routeName} {$type} → {$response->status()}";

            continue;
        }

        $json = $response->json();

        if (! is_array($json) || (! array_key_exists('data', $json) && ! array_key_exists('items', $json) && ! array_key_exists('found', $json))) {
            $failures[] = "{$routeName} {$type} → 200 but no envelope SearchableSelect can read";
        }
    }

    expect($failures)->toBe([], "option types did not answer:\n".implode("\n", $failures));
});

test('every option type refuses a user who holds no permission at all', function () {
    optAuthSeedPermissions();

    // A user with a role that grants nothing — the shape of a mis-provisioned account.
    $role = Role::create(['name' => 'OptAuth Empty', 'guard_name' => 'web']);
    $user = User::create([
        'name' => 'No Grants',
        'email' => 'nogrants@example.test',
        'password' => bcrypt('not-the-default'),
        'email_verified_at' => now(),
    ]);
    $user->assignRole($role);
    $this->actingAs($user);

    $failures = [];

    foreach (optAuthMatrix() as [$controller, $routeName, $type, $permissions]) {
        $response = $this->getJson(optAuthUrl($routeName, $type));

        if ($response->status() !== 403) {
            $failures[] = "{$routeName} {$type} → {$response->status()}, expected 403";

            continue;
        }

        // The body names what is missing, so an operator sees a permission problem rather than an
        // empty dropdown.
        $required = $response->json('requires_any_of');

        if (! is_array($required)) {
            $failures[] = "{$routeName} {$type} → 403 without a requires_any_of body";
        }
    }

    expect($failures)->toBe([], "unauthorised reads were not refused:\n".implode("\n", $failures));
});

test('every option type accepts a user holding just one of its listed permissions', function () {
    optAuthSeedPermissions();

    $failures = [];
    $cache = [];
    $salt = 0;

    foreach (optAuthMatrix() as [$controller, $routeName, $type, $permissions]) {
        expect($permissions)->not->toBeEmpty("{$routeName} {$type} declares an empty permission list, which can never be satisfied");

        $permission = $permissions[0];

        if (! isset($cache[$permission])) {
            $cache[$permission] = optAuthUserWith($permission, ++$salt);
        }

        $response = $this->actingAs($cache[$permission])->getJson(optAuthUrl($routeName, $type));

        if ($response->status() !== 200) {
            $failures[] = "{$routeName} {$type} with '{$permission}' → {$response->status()}, expected 200";
        }
    }

    expect($failures)->toBe([], "grants that should open a picker did not:\n".implode("\n", $failures));
});

test('no permission named by an option controller is a phantom', function () {
    optAuthSeedPermissions();

    $declared = OptionAccess::declaredPermissions(array_keys(optAuthEndpoints()));

    expect($declared)->toHaveCount(8, 'a controller stopped declaring a PERMISSIONS map');

    $known = Permission::query()->pluck('name')->all();
    // 179 since item 42, which added `settings.appearance.manage` — the gate on the company-wide
    // colour scheme. Choosing your own needs no permission, so it has no `.view` sibling and no
    // option endpoint is gated on it: the maps below are unchanged, which is what this test is
    // really asking when the vocabulary moves.
    expect($known)->toHaveCount(179, 'the seeded permission vocabulary changed; re-check the option maps against it');

    $phantoms = [];

    foreach ($declared as $controller => $permissions) {
        foreach ($permissions as $permission) {
            if (! in_array($permission, $known, true)) {
                $phantoms[] = class_basename($controller).' → '.$permission;
            }
        }
    }

    // A typo here does not fail loudly: the Super Admin role holds the `*` wildcard and still gets
    // through, so it ships as an empty dropdown for every real role.
    expect($phantoms)->toBe([], "these permissions are required by an option map but never seeded:\n".implode("\n", $phantoms));
});

test('an unknown option type is refused rather than answered with an empty list', function () {
    optAuthSeedPermissions();
    $this->actingAs(optAuthAdmin());

    // Before the gate, PARTNER/ACCOUNTS/SALES/INVENTORY fell through to `default => collect()` and
    // returned 200 with an empty list — indistinguishable in the UI from "no matches", which is how
    // a typo in an endpoint silently shipped before.
    foreach (['partner.api.options', 'accounts.api.options', 'sales.api.options', 'inventory.api.options', 'purchase.api.options'] as $routeName) {
        $this->getJson(route($routeName, ['type' => 'not-a-real-type']))
            ->assertStatus(404)
            ->assertJson(['error' => 'Unknown option type.', 'type' => 'not-a-real-type']);
    }
});

test('the option endpoints are not reachable without authenticating', function () {
    optAuthSeedPermissions();

    foreach (optAuthMatrix() as [$controller, $routeName, $type]) {
        $response = $this->getJson(optAuthUrl($routeName, $type));

        expect($response->status())->not->toBe(200, "{$routeName} {$type} answered a guest with 200");
    }
});

test('every route that serves options points at a controller with a permission map', function () {
    // Structural: this is the check that catches the *next* module shipping an ungated picker,
    // rather than the seven that exist today.
    $ungated = [];

    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();

        if (! str_contains($uri, 'api/options/') && ! str_contains($uri, 'api/pos-product')) {
            continue;
        }

        $controller = $route->getAction('controller');

        if (! is_string($controller)) {
            $ungated[] = "{$uri} → no controller action";

            continue;
        }

        $class = str_contains($controller, '@') ? explode('@', $controller)[0] : $controller;

        if (! class_exists($class)) {
            $ungated[] = "{$uri} → {$class} does not exist";

            continue;
        }

        if ((new ReflectionClass($class))->getReflectionConstant('PERMISSIONS') === false) {
            $ungated[] = "{$uri} → {$class} declares no PERMISSIONS map";
        }
    }

    expect($ungated)->toBe([], "these option routes are not gated by a declared permission map:\n".implode("\n", $ungated));
});

// ─────────────────── the cross-module shape of the rule ───────────────────

test('a role scoped to one HRM permission can still fill that page employee picker', function () {
    optAuthSeedPermissions();

    // `leaves/create` is gated on hrm.leaves.view, not hrm.view. The picker the page uses must
    // accept the same grant the page does, or the form opens with an empty employee dropdown.
    $user = optAuthUserWith('hrm.leaves.view', 9001);

    $this->actingAs($user)
        ->getJson(route('hrm.api.options', ['type' => 'employees']))
        ->assertOk()
        ->assertJsonStructure(['items']);

    // And the coarse grant still works, so no existing role lost access.
    $this->actingAs(optAuthUserWith('hrm.view', 9002))
        ->getJson(route('hrm.api.options', ['type' => 'employees']))
        ->assertOk();

    // A grant from an unrelated module does not.
    $this->actingAs(optAuthUserWith('sales.invoices.view', 9003))
        ->getJson(route('hrm.api.options', ['type' => 'employees']))
        ->assertStatus(403);
});

test('a purchase-only role can fill the bill form pickers but cannot read bank accounts', function () {
    optAuthSeedPermissions();

    $user = optAuthUserWith('purchase.bills.view', 9101);

    // `VendorBillForm.vue` reaches into INVENTORY for both of these, so the inventory permission
    // alone would have been the wrong gate.
    $this->actingAs($user)->getJson(route('inventory.api.options', ['type' => 'products']))->assertOk();
    $this->actingAs($user)->getJson(route('inventory.api.options', ['type' => 'warehouses']))->assertOk();
    $this->actingAs($user)->getJson(route('partner.api.options', ['type' => 'vendors']))->assertOk();
    $this->actingAs($user)->getJson(route('purchase.api.options', ['type' => 'bills']))->assertOk();

    // The boundary: bank account numbers are not purchase reference data.
    $this->actingAs($user)->getJson(route('accounts.api.options', ['type' => 'banks']))->assertStatus(403);
    $this->actingAs($user)->getJson(route('partner.api.options', ['type' => 'customers']))->assertStatus(403);
    $this->actingAs($user)->getJson(route('inventory.api.pos-product', ['key' => '1']))->assertStatus(403);
});

test('the POS product lookup returns price and stock to a cashier and to nobody else', function () {
    optAuthSeedPermissions();

    $product = Product::create([
        'name' => 'OptAuth Barcode Widget',
        'sku' => 'OA-SKU-1',
        'barcode' => '9780000000017',
        'type' => 'stockable',
        'status' => 'active',
        'valuation_method' => 'weighted',
        'cost_price' => 40.00,
        'selling_price' => 99.50,
    ]);

    $url = route('inventory.api.pos-product', ['key' => $product->sku]);

    // This payload is why the endpoint needed a gate: price and live stock for one barcode scan.
    $this->actingAs(optAuthUserWith('pos.terminal.sell', 9201))
        ->getJson($url)
        ->assertOk()
        ->assertJsonPath('found', true)
        ->assertJsonPath('data.sku', 'OA-SKU-1')
        ->assertJsonPath('data.selling_price', '99.50');

    $this->actingAs(optAuthUserWith('hrm.employees.view', 9202))
        ->getJson($url)
        ->assertStatus(403);
});

// ─────────────────────── the search escaping fix ───────────────────────

test('a LIKE wildcard in a search is a literal, not a match-all', function () {
    optAuthSeedPermissions();
    $this->actingAs(optAuthAdmin());

    $account = Account::create([
        'code' => '1002',
        'name' => 'Bank Suspense',
        'account_type' => 'asset',
        'nature' => 'debit',
        'level' => 1,
        'is_group' => false,
        'is_posting' => true,
        'is_active' => true,
    ]);

    foreach ([['Prime Bank', 'BK-1', '1111222233334'], ['Dutch-Bangla', 'BK-2', '5555666677778']] as $i => [$name, $code, $no]) {
        Bank::create([
            'name' => $name,
            'code' => $code,
            'bank_name' => $name.' Ltd',
            'account_no' => $no,
            'account_id' => $account->id,
            'is_active' => true,
        ]);
    }

    $endpoint = route('accounts.api.options', ['type' => 'banks']);

    // `%` and `_` are LIKE wildcards. Unescaped, a one-character search for "_" matched every digit
    // of every account number and returned the whole bank book.
    $this->getJson($endpoint.'?search='.urlencode('%'))->assertOk()->assertJsonPath('data', []);
    $this->getJson($endpoint.'?search='.urlencode('_'))->assertOk()->assertJsonPath('data', []);
    $this->getJson($endpoint.'?search='.urlencode('1111'))->assertOk()->assertJsonCount(1, 'data');
});
