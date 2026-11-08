<?php

/**
 * The Product create/edit form contract.
 *
 * Written before the redesign, from reading `StoreProductRequest`, `UpdateProductRequest`,
 * `ProductController` and `ProductRepository` — then run against the *unchanged* code so each
 * failure is evidence rather than an assumption. Three things looked wrong:
 *
 *   1. `variants.*.name` and `suppliers.*.partner_id` are `required_with:variants` /
 *      `required_with:suppliers`, and the page always seeds one blank row in each repeater.
 *      The array key is therefore always present, so a plain product with no variants and no
 *      supplier links should fail validation on fields the user never touched.
 *   2. `UpdateProductRequest` has no `weight` and no `currency` rule, and `edit()` does not
 *      send `weight` — so the Edit page renders a blank Weight field that cannot be saved.
 *   3. `UpdateProductRequest` has no `variants` rules at all, while the Edit page shows an
 *      editable Variants tab.
 *
 * Helper names are prefixed: Pest loads every Feature file into one process and `adminUser()`
 * is already declared by SelectorEndpointIntegrityTest.
 */

use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\CORE\Models\User;
use Modules\INVENTORY\Models\Product;

function productAdmin(): User
{
    test()->seed(SuperAdminSeeder::class);

    $admin = User::where('email', config('core.super_admin.email', 'admin@example.com'))->first();
    expect($admin)->not->toBeNull('SuperAdminSeeder produced no admin user');

    return $admin;
}

function productFixture(array $overrides = []): Product
{
    return Product::create(array_merge([
        'name' => 'Widget',
        'sku' => 'WID-0001',
        'type' => 'stockable',
        'valuation_method' => 'weighted',
        'status' => 'active',
        'cost_price' => 100.00,
        'selling_price' => 150.00,
        'currency' => 'BDT',
        'weight' => 2.5,
        'is_active' => true,
    ], $overrides));
}

/** The payload `Products/Create.vue` submits before the user touches either repeater. */
function productUiPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Widget',
        'sku' => 'WID-9001',
        'barcode' => '',
        'category_id' => null,
        'brand_id' => null,
        'uom_id' => null,
        'type' => 'stockable',
        'description' => '',
        'hs_code' => '',
        'weight' => null,
        'reorder_level' => 10,
        'reorder_qty' => 50,
        'valuation_method' => 'weighted',
        'cost_price' => 100.00,
        'selling_price' => 150.00,
        'mrp' => null,
        'vat_rate' => 0,
        'currency' => 'BDT',
        'is_serialized' => false,
        'is_batch_tracked' => false,
        'shelf_life_days' => null,
        'is_active' => true,
        'status' => 'active',
        // exactly what the page seeds
        'variants' => [['name' => '', 'sku' => '', 'barcode' => '', 'cost_price' => null, 'selling_price' => null]],
        'suppliers' => [['partner_id' => null, 'supplier_sku' => '', 'last_purchase_price' => null, 'is_primary' => true]],
    ], $overrides);
}

beforeEach(function () {
    $this->actingAs(productAdmin());
});

test('the blank variant and supplier rows the page seeds do not block a valid product', function () {
    $response = $this->post(route('inventory.products.store'), productUiPayload());

    $response->assertSessionHasNoErrors();
    $response->assertSessionHas('success');

    expect(Product::where('sku', 'WID-9001')->exists())->toBeTrue('the product was not created')
        ->and(Product::where('sku', 'WID-9001')->first()->variants()->count())->toBe(0, 'a blank variant row was persisted')
        ->and(Product::where('sku', 'WID-9001')->first()->suppliers()->count())->toBe(0, 'a blank supplier row was persisted');
});

test('a product can be created when the repeaters are omitted entirely', function () {
    $payload = productUiPayload(['sku' => 'WID-9002']);
    unset($payload['variants'], $payload['suppliers']);

    $response = $this->post(route('inventory.products.store'), $payload);

    // A 500 carries no session errors, so assert the redirect target too — otherwise a
    // QueryException passes assertSessionHasNoErrors() and only the exists() check notices.
    $response->assertSessionHasNoErrors();
    $response->assertRedirect(route('inventory.products.show', Product::where('sku', 'WID-9002')->value('id')));

    expect(Product::where('sku', 'WID-9002')->exists())->toBeTrue();
});

test('a real variant row is still persisted', function () {
    $payload = productUiPayload([
        'sku' => 'WID-9003',
        'variants' => [['name' => 'Large', 'sku' => 'WID-9003-L', 'barcode' => '', 'cost_price' => 120.00, 'selling_price' => 180.00]],
    ]);
    unset($payload['suppliers']);

    $this->post(route('inventory.products.store'), $payload)->assertSessionHasNoErrors();

    $product = Product::where('sku', 'WID-9003')->first();

    expect($product->variants()->count())->toBe(1)
        ->and($product->variants()->first()->name)->toBe('Large');
});

test('the edit page sends the fields its form renders', function () {
    $product = productFixture();

    $response = $this->get(route('inventory.products.edit', $product->id));

    $props = $response->viewData('page')['props'];

    // The Weight input is rendered by the page; without the prop it always shows blank.
    expect($props['product'])->toHaveKey('weight')
        ->and($props['product']['weight'])->not->toBeNull();
});

test('weight can be changed through the edit endpoint', function () {
    $product = productFixture(['weight' => 2.5]);

    $response = $this->put(route('inventory.products.update', $product->id), [
        'name' => $product->name,
        'sku' => $product->sku,
        'type' => 'stockable',
        'valuation_method' => 'weighted',
        'status' => 'active',
        'cost_price' => 100.00,
        'selling_price' => 150.00,
        'weight' => 7.25,
    ]);

    $response->assertSessionHasNoErrors();

    expect((float) $product->fresh()->weight)->toBe(7.25, 'weight was silently dropped by validation');
});

test('the method-override header the edit page sends reaches update()', function () {
    $product = productFixture(['sku' => 'WID-OVERRIDE']);

    // Products/Edit.vue posts with X-HTTP-Method-Override: PUT rather than a _method field.
    $response = $this->postJson(route('inventory.products.update', $product->id), [
        'name' => 'Renamed by override',
        'sku' => 'WID-OVERRIDE',
        'type' => 'stockable',
        'valuation_method' => 'weighted',
        'status' => 'active',
        'cost_price' => 100.00,
        'selling_price' => 150.00,
    ], ['X-HTTP-Method-Override' => 'PUT']);

    expect($response->status())->not->toBe(405, 'the override header was not honoured')
        ->and($product->fresh()->name)->toBe('Renamed by override');
});

test('variants can be added through the edit endpoint', function () {
    $product = productFixture(['sku' => 'WID-VAR']);

    $response = $this->put(route('inventory.products.update', $product->id), [
        'name' => $product->name,
        'sku' => 'WID-VAR',
        'type' => 'stockable',
        'valuation_method' => 'weighted',
        'status' => 'active',
        'cost_price' => 100.00,
        'selling_price' => 150.00,
        'variants' => [
            ['name' => 'Large', 'sku' => '', 'barcode' => '', 'cost_price' => null, 'selling_price' => 180.00],
        ],
    ]);

    $response->assertSessionHasNoErrors();

    $fresh = $product->fresh(['variants']);

    // UpdateProductRequest had no variants rules, so validated() stripped them and the Edit
    // page's Variants tab discarded every edit without telling the user.
    expect($fresh->variants)->toHaveCount(1)
        ->and($fresh->variants->first()->name)->toBe('Large')
        // cost_price was null; the column is NOT NULL DEFAULT '0'.
        ->and((float) $fresh->variants->first()->cost_price)->toBe(0.0)
        ->and((float) $fresh->variants->first()->selling_price)->toBe(180.00);
});

test('a variant_code is derived when the form does not supply one', function () {
    $product = productFixture(['sku' => 'WID-CODE']);

    $this->put(route('inventory.products.update', $product->id), [
        'name' => $product->name,
        'sku' => 'WID-CODE',
        'type' => 'stockable',
        'valuation_method' => 'weighted',
        'status' => 'active',
        'cost_price' => 100.00,
        'selling_price' => 150.00,
        'variants' => [
            ['name' => 'Small', 'cost_price' => 90.00, 'selling_price' => 140.00],
            ['name' => 'Large', 'cost_price' => 110.00, 'selling_price' => 170.00],
        ],
    ])->assertSessionHasNoErrors();

    $codes = $product->fresh()->variants()->pluck('variant_code')->all();

    // NOT NULL, UNIQUE, no default, and no form field for it.
    expect($codes)->toHaveCount(2)
        ->and(array_unique($codes))->toHaveCount(2, 'derived variant codes collided')
        ->and($codes[0])->not->toBe('')
        ->and($codes[1])->not->toBe('');
});

test('clearing a numeric field does not 500 on a NOT NULL column', function () {
    $response = $this->post(route('inventory.products.store'), productUiPayload([
        'sku' => 'WID-NULLS',
        'mrp' => null,
        'weight' => null,
        'shelf_life_days' => null,
        'vat_rate' => null,
        'reorder_level' => null,
        'reorder_qty' => null,
    ]));

    $response->assertSessionHasNoErrors();

    $product = Product::where('sku', 'WID-NULLS')->first();

    expect($product)->not->toBeNull()
        ->and((float) $product->mrp)->toBe(0.0)
        ->and((float) $product->vat_rate)->toBe(0.0)
        // genuinely nullable columns must stay null rather than being coerced to zero
        ->and($product->weight)->toBeNull()
        ->and($product->shelf_life_days)->toBeNull();
});
