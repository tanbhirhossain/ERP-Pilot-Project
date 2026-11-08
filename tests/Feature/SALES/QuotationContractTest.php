<?php

/**
 * The quotation edit contract.
 *
 * `QuotationRepository::update()` throws `\DomainException('Only draft quotations can be
 * edited.')`, and `QuotationController::update()` — unlike its sibling `OrderController::update()`
 * and unlike `destroy()` in the very same class — has **no try/catch**. So editing a quotation
 * that has already been sent, accepted or converted is an uncaught exception: a 500 with a stack
 * trace, not the flashed "Only draft quotations can be edited." the repository wrote for exactly
 * this purpose. `send()` and `accept()` have the same shape.
 */

use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\CORE\Models\User;
use Modules\INVENTORY\Models\Product;
use Modules\PARTNER\Models\Partner;
use Modules\SALES\Models\Quotation;

/** Prefixed: Pest loads every Feature file into one process. */
function quAdmin(): User
{
    test()->seed(SuperAdminSeeder::class);

    $admin = User::where('email', config('core.super_admin.email', 'admin@example.com'))->first();
    expect($admin)->not->toBeNull('SuperAdminSeeder produced no admin user');

    return $admin;
}

/** @return array{0: Partner, 1: Product} — `sales_quotations` has no warehouse_id column. */
function quFixtures(): array
{
    $customer = Partner::create(['partner_code' => 'CUS-QU-1', 'type' => 'customer', 'name' => 'Apex Retail']);
    $product = Product::create([
        'name' => 'Widget',
        'sku' => 'WID-QU-1',
        'type' => 'stockable',
        'status' => 'active',
        'valuation_method' => 'weighted',
        'cost_price' => 100.00,
        'selling_price' => 150.00,
    ]);

    return [$customer, $product];
}

function quPayload(Partner $customer, Product $product, array $itemOverrides = []): array
{
    return [
        'customer_id' => $customer->id,
        'quotation_date' => '2026-02-01',
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

test('a quotation can be created with a line discount and VAT', function () {
    $this->actingAs(quAdmin());
    [$customer, $product] = quFixtures();

    $this->post(route('sales.quotations.store'), quPayload($customer, $product))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $quotation = Quotation::with('items')->firstOrFail();

    expect((float) $quotation->items[0]->discount_pct)->toBe(10.0)
        ->and((float) $quotation->items[0]->vat_rate)->toBe(5.0)
        ->and((float) $quotation->total_amount)->toBe(189.0);
});

test('a draft quotation can be edited and keeps its discount and VAT', function () {
    $this->actingAs(quAdmin());
    [$customer, $product] = quFixtures();

    $this->post(route('sales.quotations.store'), quPayload($customer, $product));
    $quotation = Quotation::firstOrFail();

    $this->put(route('sales.quotations.update', $quotation->id), quPayload($customer, $product, [
        'qty' => 4,
        'discount_pct' => 20.0,
    ]))->assertRedirect()->assertSessionHasNoErrors();

    $quotation->refresh()->load('items');

    // 4 × 100 = 400 gross, less 20% = 320 net, plus 5% VAT = 16 → 336.
    expect((float) $quotation->items[0]->qty)->toBe(4.0)
        ->and((float) $quotation->items[0]->discount_pct)->toBe(20.0)
        ->and((float) $quotation->items[0]->vat_rate)->toBe(5.0)
        ->and((float) $quotation->total_amount)->toBe(336.0);
});

test('editing a sent quotation flashes an error instead of 500ing', function (string $status) {
    $this->actingAs(quAdmin());
    [$customer, $product] = quFixtures();

    $quotation = Quotation::create([
        'quotation_no' => 'QUOT-LOCKED-1',
        'customer_id' => $customer->id,
        'quotation_date' => '2026-02-01',
        'status' => $status,
    ]);

    // The repository throws DomainException for exactly this case; the controller must catch it.
    $this->put(route('sales.quotations.update', $quotation->id), quPayload($customer, $product))
        ->assertRedirect()
        ->assertSessionHas('error');

    expect((string) session('error'))->toContain('Only draft quotations can be edited')
        ->and($quotation->fresh()->status)->toBe($status);
})->with(['sent', 'accepted', 'converted']);
test('terms and currency are persisted on create and on edit', function () {
    $this->actingAs(quAdmin());
    [$customer, $product] = quFixtures();

    // `terms` was validated by the request, listed in Quotation::$fillable and written by the
    // repository — but no page ever rendered a field for it, so it had never been saved.
    $this->post(route('sales.quotations.store'), quPayload($customer, $product) + [
        'currency' => 'USD',
        'terms' => '50% advance, balance on delivery. Valid 30 days.',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $quotation = Quotation::firstOrFail();

    expect($quotation->currency)->toBe('USD')
        ->and($quotation->terms)->toBe('50% advance, balance on delivery. Valid 30 days.');

    $this->put(route('sales.quotations.update', $quotation->id), quPayload($customer, $product) + [
        'currency' => 'BDT',
        'terms' => 'Revised: full payment on delivery.',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($quotation->fresh()->currency)->toBe('BDT')
        ->and($quotation->fresh()->terms)->toBe('Revised: full payment on delivery.');
});

test('an expiry date on or before the quotation date is rejected', function () {
    $this->actingAs(quAdmin());
    [$customer, $product] = quFixtures();

    // `after:quotation_date` — strictly after, unlike the invoice's `after_or_equal:due_date`.
    $this->post(route('sales.quotations.store'), quPayload($customer, $product) + [
        'expiry_date' => '2026-02-01',
    ])->assertSessionHasErrors('expiry_date');

    expect(Quotation::count())->toBe(0);
});
