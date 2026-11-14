<?php

/**
 * Phase 2 — U7 bulk product actions.
 *
 * Activate/deactivate flips the active flag; delete reuses the per-product guard
 * (stock / movement history) so guarded rows are skipped, never force-deleted.
 * Each action is permission-checked independently of the route gate.
 *
 * Helper names are prefixed `p2bp*`.
 */

use Modules\CORE\Models\User;
use Modules\INVENTORY\Models\Product;
use Modules\INVENTORY\Models\Stock;
use Modules\INVENTORY\Models\Warehouse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function p2bpUser(array $permissions, int $salt): User
{
    $role = Role::create(['name' => "P2BP Role {$salt}", 'guard_name' => 'web']);
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        $role->givePermissionTo($permission);
    }

    $user = User::create([
        'name' => "P2BP User {$salt}", 'email' => "p2bp{$salt}@example.test",
        'password' => bcrypt('secret-password'), 'email_verified_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

test('bulk deactivate flips the active flag on every selected product', function () {
    $user = p2bpUser(['inventory.products.view', 'inventory.products.update'], 1);
    $a = Product::factory()->create(['is_active' => true]);
    $b = Product::factory()->create(['is_active' => true]);

    $this->actingAs($user)->post(route('inventory.products.bulk'), [
        'action' => 'deactivate', 'ids' => [$a->id, $b->id],
    ])->assertRedirect();

    expect((bool) $a->fresh()->is_active)->toBeFalse()->and((bool) $b->fresh()->is_active)->toBeFalse();
});

test('bulk activate requires the update permission', function () {
    $viewer = p2bpUser(['inventory.products.view'], 2);
    $product = Product::factory()->create(['is_active' => false]);

    $this->actingAs($viewer)->post(route('inventory.products.bulk'), [
        'action' => 'activate', 'ids' => [$product->id],
    ])->assertForbidden();

    expect((bool) $product->fresh()->is_active)->toBeFalse();
});

test('bulk delete skips products with on-hand stock and deletes the rest', function () {
    $user = p2bpUser(['inventory.products.view', 'inventory.products.delete'], 3);
    $warehouse = Warehouse::factory()->create();

    $guarded = Product::factory()->create();
    $stock = new Stock;
    $stock->forceFill(['product_id' => $guarded->id, 'warehouse_id' => $warehouse->id, 'qty_on_hand' => 5])->save();

    $deletable = Product::factory()->create();

    $this->actingAs($user)->post(route('inventory.products.bulk'), [
        'action' => 'delete', 'ids' => [$guarded->id, $deletable->id],
    ])->assertRedirect();

    expect(Product::find($guarded->id))->not->toBeNull()
        ->and(Product::find($deletable->id))->toBeNull();
});

test('bulk delete requires the delete permission', function () {
    $editor = p2bpUser(['inventory.products.view', 'inventory.products.update'], 4);
    $product = Product::factory()->create();

    $this->actingAs($editor)->post(route('inventory.products.bulk'), [
        'action' => 'delete', 'ids' => [$product->id],
    ])->assertForbidden();

    expect(Product::find($product->id))->not->toBeNull();
});
