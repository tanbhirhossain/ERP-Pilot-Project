<?php

/**
 * The sales order edit contract.
 *
 * `Orders/Edit.vue` was a 14-line stub that mapped its lines to
 * `{ product_id, qty, unit_price }` only — no `discount_pct`, no `vat_rate`. `OrderRepository::
 * update()` does a **full replacement** (`items()->delete()` then re-create) and
 * `OrderService::update()` recomputes the header totals from whatever arrived. So every save
 * through that page silently reset each line's discount and VAT to zero and changed the order
 * total, with a "Order updated." success flash. No error, no 500 — just quieter money.
 *
 * The two tests here are deliberately different in kind:
 *   - the HTTP tests pin the backend's full-replacement semantics, which is *why* a form must
 *     send every line field;
 *   - the source-contract test pins the frontend half, because the backend is behaving correctly
 *     and nothing it does can catch a page that omits a field.
 */

use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\CORE\Models\User;
use Modules\INVENTORY\Interfaces\InventoryStockProviderInterface;
use Modules\INVENTORY\Models\Product;
use Modules\INVENTORY\Models\Warehouse;
use Modules\PARTNER\Models\Partner;
use Modules\SALES\Models\Order;

/** Prefixed: Pest loads every Feature file into one process. */
function soAdmin(): User
{
    test()->seed(SuperAdminSeeder::class);

    $admin = User::where('email', config('core.super_admin.email', 'admin@example.com'))->first();
    expect($admin)->not->toBeNull('SuperAdminSeeder produced no admin user');

    return $admin;
}

/** @return array{0: Partner, 1: Warehouse, 2: Product} */
function soFixtures(): array
{
    $customer = Partner::create(['partner_code' => 'CUS-SO-1', 'type' => 'customer', 'name' => 'Apex Retail']);
    $warehouse = Warehouse::create(['name' => 'Main Warehouse', 'code' => 'WH-SO-1']);
    $product = Product::create([
        'name' => 'Widget',
        'sku' => 'WID-SO-1',
        'type' => 'stockable',
        'status' => 'active',
        'valuation_method' => 'weighted',
        'cost_price' => 100.00,
        'selling_price' => 150.00,
    ]);

    return [$customer, $warehouse, $product];
}

function soPayload(Partner $customer, Warehouse $warehouse, Product $product, array $itemOverrides = []): array
{
    return [
        'customer_id' => $customer->id,
        'warehouse_id' => $warehouse->id,
        'order_date' => '2026-02-01',
        'items' => [
            array_merge([
                'product_id' => $product->id,
                'qty' => 2,
                'unit_price' => 100.00,
                'discount_pct' => 10.0,
                'vat_rate' => 5.0,
            ], $itemOverrides),
        ],
    ];
}

test('an order can be created with a line discount and VAT', function () {
    $this->actingAs(soAdmin());
    [$customer, $warehouse, $product] = soFixtures();

    $this->post(route('sales.orders.store'), soPayload($customer, $warehouse, $product))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $order = Order::with('items')->firstOrFail();

    // 2 × 100 = 200 gross, less 10% = 180 net, plus 5% VAT = 9 → 189.
    expect((float) $order->items[0]->discount_pct)->toBe(10.0)
        ->and((float) $order->items[0]->vat_rate)->toBe(5.0)
        ->and((float) $order->subtotal)->toBe(200.0)
        ->and((float) $order->discount_amount)->toBe(20.0)
        ->and((float) $order->vat_amount)->toBe(9.0)
        ->and((float) $order->total_amount)->toBe(189.0);
});

test('update is a full replacement: any line field the client omits is reset', function () {
    $this->actingAs(soAdmin());
    [$customer, $warehouse, $product] = soFixtures();

    $this->post(route('sales.orders.store'), soPayload($customer, $warehouse, $product));
    $order = Order::firstOrFail();
    expect((float) $order->total_amount)->toBe(189.0);

    // Exactly what the old Orders/Edit.vue submitted: product, qty and price, nothing else.
    $this->put(route('sales.orders.update', $order->id), [
        'customer_id' => $customer->id,
        'warehouse_id' => $warehouse->id,
        'order_date' => '2026-02-01',
        'notes' => '',
        'items' => [['product_id' => $product->id, 'qty' => 2, 'unit_price' => 100.00]],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $order->refresh()->load('items');

    // The backend is correct — it persists what it was given. This is the consequence a form
    // that omits fields produces, and it is silent: a success flash and 189 → 200.
    expect((float) $order->items[0]->discount_pct)->toBe(0.0)
        ->and((float) $order->items[0]->vat_rate)->toBe(0.0)
        ->and((float) $order->total_amount)->toBe(200.0);
});

test('clearing the discount and VAT fields on a line does not 500', function () {
    $this->actingAs(soAdmin());
    [$customer, $warehouse, $product] = soFixtures();

    // `''` → ConvertEmptyStringsToNull → null → `sales_order_items.discount_pct` is NOT NULL.
    $this->post(route('sales.orders.store'), soPayload($customer, $warehouse, $product, [
        'discount_pct' => '',
        'vat_rate' => '',
    ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $order = Order::with('items')->firstOrFail();

    expect((float) $order->items[0]->discount_pct)->toBe(0.0)
        ->and((float) $order->total_amount)->toBe(200.0);
});

test('editing a confirmed order is refused with a flashed error, not a 500', function () {
    $this->actingAs(soAdmin());
    [$customer, $warehouse, $product] = soFixtures();

    $order = Order::create([
        'order_no' => 'SO-CONF-1',
        'customer_id' => $customer->id,
        'warehouse_id' => $warehouse->id,
        'order_date' => '2026-02-01',
        'status' => 'confirmed',
        'payment_status' => 'unpaid',
    ]);

    $this->put(route('sales.orders.update', $order->id), soPayload($customer, $warehouse, $product))
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($order->fresh()->status)->toBe('confirmed');
});

/**
 * Pages whose line tables genuinely have no `vat_rate`, or whose service derives it rather than
 * taking it from the form. Documented so the allowlist cannot grow silently.
 */
const VATLESS_LINE_FORMS = [
    // price_list_items has unit_price/min_qty/discount_pct and no vat_rate column at all.
    'PARTNER/Resources/js/Pages/PriceLists/Create.vue',
    'PARTNER/Resources/js/Pages/PriceLists/Edit.vue',
    // sales_return_items has no discount_pct, and SalesReturnService sets vat_rate itself from
    // the source invoice item rather than reading it off the form.
    'SALES/Resources/js/Pages/Returns/Create.vue',
];

test('every line-item editor that collects a price also collects VAT', function () {
    // Three levels, not two: this file lives in tests/Feature/SALES/. Getting it wrong makes the
    // globs return nothing and the test pass without examining a single file — which is exactly
    // what it did on its first run, so the counts below are assertions, not decoration.
    $root = dirname(__DIR__, 3);

    $files = array_merge(
        glob($root.'/modules/*/Resources/js/Pages/*/*.vue') ?: [],
        glob($root.'/modules/*/Resources/js/Components/*Form.vue') ?: [],
    );

    $this->assertGreaterThan(150, count($files), 'the scan found no Vue pages at all — $root is wrong');

    $scanned = 0;
    $offenders = [];

    foreach ($files as $file) {
        $result = checkLineForm($file, $root);

        if ($result === null) {
            continue;   // not a priced line-item editor, or allowlisted
        }

        $scanned++;
        $offenders = array_merge($offenders, $result);
    }

    $this->assertGreaterThanOrEqual(10, $scanned,
        'the scan matched no line-item editors — the glob or the filename rule is wrong, and this test would be vacuous');

    expect($offenders)->toBe([], implode("\n", $offenders));
});

/**
 * A Create/Edit page or shared *Form component that builds priced line rows must carry
 * `vat_rate`. Show pages are excluded by the filename rule; read-only displays legitimately
 * print a price without offering one.
 *
 * @return list<string>|null null when the file is out of scope, so the caller can count what it scanned
 */
function checkLineForm(string $file, string $root): ?array
{
    $relative = substr($file, strlen($root.'/modules/'));
    $base = basename($file);

    $isEditor = preg_match('/^(Create|Edit)\.vue$/', $base) === 1 || str_ends_with($base, 'Form.vue');

    if (! $isEditor || in_array($relative, VATLESS_LINE_FORMS, true)) {
        return null;
    }

    $source = file_get_contents($file);

    // Only line-item editors: something that collects a unit price and a quantity.
    if (! str_contains($source, 'unit_price') || ! str_contains($source, 'qty')) {
        return null;
    }

    return str_contains($source, 'vat_rate')
        ? []
        : ["{$relative} collects unit_price and qty but never vat_rate — saving it resets every line's VAT to 0"];
}
/**
 * `OrderService::cancel()` swallowed a failed `releaseReservation()` and cancelled the order on
 * the next line anyway, so the stock stayed reserved against an order that no longer exists —
 * permanently understating what is available to sell, with nothing logged or flashed. Unlike the
 * POS and bill voids this method is not wrapped in `DB::transaction()`, so there was not even a
 * rollback being defeated; the reserved quantity was simply stranded.
 */
function soConfirmedOrder(Partner $customer, Warehouse $warehouse, Product $product): Order
{
    $order = Order::create([
        'order_no' => 'SO-CANCEL-1',
        'customer_id' => $customer->id,
        'warehouse_id' => $warehouse->id,
        'order_date' => '2026-02-01',
        'status' => 'confirmed',
        'payment_status' => 'unpaid',
    ]);

    $order->items()->create([
        'product_id' => $product->id,
        'product_name' => 'Widget',
        'qty' => 2,
        'unit_price' => 100.00,
        'line_total' => 200.00,
    ]);

    return $order;
}

test('cancelling an order whose stock release fails leaves it confirmed and says why', function () {
    $this->actingAs(soAdmin());
    [$customer, $warehouse, $product] = soFixtures();
    $order = soConfirmedOrder($customer, $warehouse, $product);

    $this->mock(InventoryStockProviderInterface::class, function ($mock) {
        $mock->shouldReceive('releaseReservation')->andThrow(new RuntimeException('stock service down'));
    });

    $this->post(route('sales.orders.cancel', $order))
        ->assertRedirect()
        ->assertSessionHas('error')
        ->assertSessionMissing('success');

    expect((string) session('error'))
        ->toContain('SO-CANCEL-1')
        ->toContain('stock service down');

    expect($order->fresh()->status)->toBe('confirmed');
});

test('cancelling succeeds when the stock release succeeds', function () {
    $this->actingAs(soAdmin());
    [$customer, $warehouse, $product] = soFixtures();
    $order = soConfirmedOrder($customer, $warehouse, $product);

    $this->mock(InventoryStockProviderInterface::class, function ($mock) {
        $mock->shouldReceive('releaseReservation')->andReturnNull();
    });

    $this->post(route('sales.orders.cancel', $order))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($order->fresh()->status)->toBe('cancelled');
});
