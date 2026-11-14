<?php

/**
 * Phase 2 — U8 saved views + U9 global search.
 *
 * Saved views are strictly per-user: one user can never read or delete another's
 * views. Global search is permission-scoped per entity type and escapes LIKE
 * wildcards so `%` is a literal, not a match-all.
 *
 * Helper names are prefixed `p2sv*`.
 */

use Modules\CORE\Models\SavedView;
use Modules\CORE\Models\User;
use Modules\INVENTORY\Models\Product;
use Modules\PARTNER\Models\Partner;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function p2svUser(array $permissions, int $salt): User
{
    $role = Role::create(['name' => "P2SV Role {$salt}", 'guard_name' => 'web']);
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        $role->givePermissionTo($permission);
    }

    $user = User::create([
        'name' => "P2SV User {$salt}",
        'email' => "p2sv{$salt}@example.test",
        'password' => bcrypt('secret-password'),
        'email_verified_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

test('a user can save and list their own table view', function () {
    $user = p2svUser([], 1);
    $this->actingAs($user);

    $this->postJson(route('core.saved-views.store', ['tableKey' => 'sales.invoices.index']), [
        'name' => 'Overdue',
        'state' => ['query' => ['status' => 'posted'], 'hidden' => ['notes']],
    ])->assertOk();

    $response = $this->getJson(route('core.saved-views.index', ['tableKey' => 'sales.invoices.index']))->assertOk();
    $views = $response->json('views');
    expect($views)->toHaveCount(1)
        ->and($views[0]['name'])->toBe('Overdue')
        ->and($views[0]['state']['hidden'])->toBe(['notes']);
});

test('saving the same view name updates rather than duplicates', function () {
    $user = p2svUser([], 2);
    $this->actingAs($user);

    foreach (['posted', 'draft'] as $status) {
        $this->postJson(route('core.saved-views.store', ['tableKey' => 't']), [
            'name' => 'My view', 'state' => ['query' => ['status' => $status]],
        ])->assertOk();
    }

    $views = $this->getJson(route('core.saved-views.index', ['tableKey' => 't']))->json('views');
    expect($views)->toHaveCount(1)->and($views[0]['state']['query']['status'])->toBe('draft');
});

test('a user cannot see another user\'s saved views', function () {
    $alice = p2svUser([], 3);
    $bob = p2svUser([], 4);

    $this->actingAs($alice)->postJson(route('core.saved-views.store', ['tableKey' => 't']), [
        'name' => 'Alice only', 'state' => ['query' => []],
    ])->assertOk();

    $this->actingAs($bob);
    expect($this->getJson(route('core.saved-views.index', ['tableKey' => 't']))->json('views'))->toHaveCount(0);
});

test('a user cannot delete another user\'s saved view', function () {
    $alice = p2svUser([], 5);
    $bob = p2svUser([], 6);

    $this->actingAs($alice)->postJson(route('core.saved-views.store', ['tableKey' => 't']), [
        'name' => 'Alice only', 'state' => ['query' => []],
    ])->assertOk();
    $id = SavedView::where('user_id', $alice->id)->value('id');

    $this->actingAs($bob)
        ->deleteJson(route('core.saved-views.destroy', ['tableKey' => 't', 'id' => $id]))
        ->assertNotFound();

    expect(SavedView::find($id))->not->toBeNull();
});

test('global search ignores queries shorter than two characters', function () {
    $user = p2svUser(['inventory.products.view'], 7);
    Product::factory()->create(['name' => 'P2SV Unique Widget', 'sku' => 'P2SV-W1']);

    $this->actingAs($user);
    expect($this->getJson(route('core.global-search', ['q' => 'P']))->json('results'))->toHaveCount(0);
});

test('global search is scoped to the viewer\'s permissions', function () {
    Product::factory()->create(['name' => 'P2SV Scoped Widget', 'sku' => 'P2SV-SC1']);

    $allowed = p2svUser(['inventory.products.view'], 8);
    $this->actingAs($allowed);
    $hits = $this->getJson(route('core.global-search', ['q' => 'P2SV Scoped']))->json('results');
    expect(collect($hits)->pluck('type'))->toContain('Product');

    $denied = p2svUser([], 9);
    $this->actingAs($denied);
    expect($this->getJson(route('core.global-search', ['q' => 'P2SV Scoped']))->json('results'))->toHaveCount(0);
});

test('global search escapes LIKE wildcards', function () {
    $user = p2svUser(['inventory.products.view'], 10);
    Product::factory()->create(['name' => 'P2SV Wildcard Product', 'sku' => 'P2SV-WC1']);

    $this->actingAs($user);
    // A bare '%' must not match every product.
    expect($this->getJson(route('core.global-search', ['q' => '%']))->json('results'))->toHaveCount(0);
});

test('global search finds partners by name with a deep link', function () {
    $user = p2svUser(['partner.customers.view'], 11);
    Partner::create([
        'partner_code' => 'P2SV-'.uniqid(), 'type' => 'customer', 'name' => 'P2SV Findable Customer', 'is_active' => true, 'currency' => 'BDT',
    ]);

    $this->actingAs($user);
    $hits = $this->getJson(route('core.global-search', ['q' => 'P2SV Findable']))->json('results');
    expect($hits)->toHaveCount(1)
        ->and($hits[0]['type'])->toBe('Customer')
        ->and($hits[0]['url'])->toContain('/partner/customers/');
});
