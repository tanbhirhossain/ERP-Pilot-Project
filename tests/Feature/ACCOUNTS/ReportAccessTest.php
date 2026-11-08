<?php

/**
 * Who can read the books — report item 39, the second half of "I can not see any trial balance".
 *
 * There were two independent reasons the trial balance was invisible, and fixing only one would have
 * left the complaint true:
 *
 *  1. The sidebar drew two levels of a three-level menu, so `Accounts › Financial Reports › Trial
 *     Balance` rendered as a dead `#` link for everybody. That is fixed in `TreeSidebar.vue` and
 *     pinned by `treeSidebarPolish.test.ts`.
 *  2. `accounts.reports.view` was granted to the Super Admin alone. Every other role — including
 *     Auditor, the one role whose entire job is reading the books — got the menu item filtered out and
 *     a 403 from the route.
 *
 * This file pins the second half: the read-only report permissions are held by the roles that run and
 * audit the business, and are *not* held by a role that should not see the ledger. It asserts access
 * rather than grants, because a grant nobody can use is worthless and an accidental grant is a leak.
 *
 * Note what is deliberately not granted: posting, voiding and managing the chart of accounts stay with
 * the Super Admin. These are read permissions.
 */

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\CORE\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    config(['hashing.bcrypt.rounds' => 4]);
});

/** The seeded user who holds a role, or a fresh one if the base seed did not create that role's user. */
function racUser(string $roleName): User
{
    $role = Role::where('name', $roleName)->firstOrFail();

    $user = $role->users()->first();

    if ($user !== null) {
        return $user;
    }

    $user = User::create([
        'name' => "RAC {$roleName}",
        'email' => 'rac.'.strtolower(str_replace(' ', '-', $roleName)).'@example.com',
        'password' => bcrypt('password'),
    ]);
    $user->assignRole($role);

    return $user;
}

/** The financial reports a reader role must be able to open. */
function racReportRoutes(): array
{
    return [
        'trial balance' => 'accounts.reports.trial',
        'profit & loss' => 'accounts.reports.pl',
        'balance sheet' => 'accounts.reports.bs',
        'general ledger' => 'accounts.reports.ledger',
        'cash flow' => 'accounts.reports.cash-flow',
    ];
}

test('an auditor can open every financial report', function () {
    $auditor = racUser('Auditor');

    foreach (racReportRoutes() as $label => $route) {
        $this->actingAs($auditor)
            ->get(route($route))
            ->assertOk();
    }

    // The subledgers too: an auditor who can read the trial balance but not the aging behind it
    // cannot reconcile anything.
    $this->actingAs($auditor)->get(route('accounts.reports.receivables-aging'))->assertOk();
    $this->actingAs($auditor)->get(route('accounts.reports.payables-aging'))->assertOk();
});

test('a manager and an administrator can read the books as well', function (string $role) {
    $user = racUser($role);

    foreach (racReportRoutes() as $route) {
        $this->actingAs($user)->get(route($route))->assertOk();
    }
})->with(['Manager', 'Administrator']);

test('a member of staff is refused the ledger', function () {
    $staff = racUser('Staff');

    foreach (racReportRoutes() as $route) {
        $this->actingAs($staff)->get(route($route))->assertForbidden();
    }

    // Reading is granted; writing is not granted to anybody but the Super Admin. A reader role that
    // could post a journal would be able to change the books it is supposed to be reading.
    $auditor = racUser('Auditor');
    $this->actingAs($auditor)->post(route('accounts.journals.store'), [])->assertForbidden();
});

test('the same roles can read the POS day-end and collection report', function () {
    foreach (['Super Admin', 'Administrator', 'Manager', 'Auditor'] as $role) {
        $this->actingAs(racUser($role))
            ->get(route('sales.pos.reports.dayEnd'))
            ->assertOk();
    }

    // A cashier's own permissions do not include it: the report shows every cashier's takings and
    // variances side by side, which is a management view, not a till view.
    $this->actingAs(racUser('Staff'))
        ->get(route('sales.pos.reports.dayEnd'))
        ->assertForbidden();
});

test('the menu gates the report group on the permission the routes enforce', function () {
    // A source contract, because the failure mode here is silent: a menu item gated on a permission
    // nobody holds disappears, and the route behind it 403s, so the feature looks deleted rather
    // than forbidden. The gate in the menu and the gate on the route have to be the same string.
    $menu = (string) file_get_contents(base_path('modules/CORE/Resources/js/Config/menu.ts'));

    $this->assertStringContainsString("permission: 'accounts.reports.view'", $menu);
    $this->assertStringContainsString("route: 'accounts.reports.trial'", $menu, 'the trial balance is not linked in the menu');
    $this->assertStringContainsString("permission: 'pos.reports.view'", $menu);
    $this->assertStringContainsString("route: 'sales.pos.reports.dayEnd'", $menu);

    // And the permission the menu names must exist, or the item is hidden for everyone forever.
    $this->assertNotNull(
        Permission::where('name', 'accounts.reports.view')->first(),
        'accounts.reports.view is not a seeded permission',
    );
    $this->assertNotNull(
        Permission::where('name', 'pos.reports.view')->first(),
        'pos.reports.view is not a seeded permission',
    );
});
