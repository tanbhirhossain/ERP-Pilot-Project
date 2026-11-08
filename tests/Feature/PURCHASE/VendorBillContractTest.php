<?php

/**
 * The vendor bill create/edit contract.
 *
 * Written before touching the code and run against it unchanged, so each failure is evidence.
 * `purchase_bill_items.discount_pct` and `vat_rate` are `numeric NOT NULL DEFAULT '0'`,
 * `VendorBillRequest` declares both `nullable`, and `VendorBillService::enrichTotals()` reads them
 * with `?? 0` for the arithmetic but forwards the raw row to `items()->create()`. Clearing either
 * field in the UI therefore produces a NOT NULL violation — and `store()` has no try/catch at all,
 * so it is a 500 rather than a flashed error.
 *
 * Helper names are prefixed: Pest loads every Feature file into one process.
 */

use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\CORE\Models\User;
use Modules\INVENTORY\Models\Product;
use Modules\INVENTORY\Models\Warehouse;
use Modules\PARTNER\Models\Partner;
use Modules\PURCHASE\Models\VendorBill;

function billAdmin(): User
{
    test()->seed(SuperAdminSeeder::class);

    $admin = User::where('email', config('core.super_admin.email', 'admin@example.com'))->first();
    expect($admin)->not->toBeNull('SuperAdminSeeder produced no admin user');

    return $admin;
}

function billFixtures(): array
{
    $vendor = Partner::create(['partner_code' => 'VEN-BILL-1', 'type' => 'vendor', 'name' => 'Acme Supplies']);
    $warehouse = Warehouse::create(['name' => 'Main Warehouse', 'code' => 'WH-BILL-1']);
    $product = Product::create([
        'name' => 'Widget',
        'sku' => 'WID-BILL-1',
        'type' => 'stockable',
        'status' => 'active',
        'valuation_method' => 'weighted',
        'cost_price' => 100.00,
        'selling_price' => 150.00,
    ]);

    return [$vendor, $warehouse, $product];
}

function billPayload(Partner $vendor, Warehouse $warehouse, Product $product, array $itemOverrides = []): array
{
    return [
        'vendor_id' => $vendor->id,
        'warehouse_id' => $warehouse->id,
        'bill_date' => '2026-02-01',
        'due_date' => '2026-03-01',
        'vendor_reference' => 'ACME-INV-77',
        'notes' => 'February stock',
        'items' => [
            array_merge([
                'product_id' => $product->id,
                'qty' => 4,
                'unit_price' => 250.00,
                'discount_pct' => 0,
                'vat_rate' => 0,
            ], $itemOverrides),
        ],
    ];
}

beforeEach(function () {
    $this->actingAs(billAdmin());
});

test('a bill can be created from a single line', function () {
    [$vendor, $warehouse, $product] = billFixtures();

    $response = $this->post(route('purchase.bills.store'), billPayload($vendor, $warehouse, $product));

    // store() has no try/catch, so a QueryException here is a 500 with no session errors —
    // assertSessionHasNoErrors() alone would pass. Assert the redirect target too.
    $response->assertSessionHasNoErrors();
    $response->assertSessionHas('success');

    $bill = VendorBill::first();

    expect($bill)->not->toBeNull('the bill was not created')
        ->and($bill->status)->toBe('draft')
        ->and((float) $bill->total_amount)->toBe(1000.00)
        ->and($bill->items)->toHaveCount(1);
});

test('clearing the discount and VAT fields on a line does not 500', function () {
    [$vendor, $warehouse, $product] = billFixtures();

    // This is what the page submits when the user empties either number input:
    // v-model.number yields '', ConvertEmptyStringsToNull yields null.
    $response = $this->post(route('purchase.bills.store'), billPayload($vendor, $warehouse, $product, [
        'discount_pct' => null,
        'vat_rate' => null,
    ]));

    $response->assertSessionHasNoErrors();
    $response->assertSessionHas('success');

    $line = VendorBill::first()->items->first();

    expect((float) $line->discount_pct)->toBe(0.0, 'a cleared discount was not coerced')
        ->and((float) $line->vat_rate)->toBe(0.0, 'a cleared VAT rate was not coerced')
        ->and((float) $line->line_total)->toBe(1000.00);
});

test('bill totals apply the discount before VAT', function () {
    [$vendor, $warehouse, $product] = billFixtures();

    $this->post(route('purchase.bills.store'), billPayload($vendor, $warehouse, $product, [
        'qty' => 2,
        'unit_price' => 100.00,
        'discount_pct' => 10,
        'vat_rate' => 5,
    ]))->assertSessionHasNoErrors();

    $bill = VendorBill::first();

    // 200 gross, less 10% = 180, plus 5% VAT = 9 → 189.
    expect((float) $bill->subtotal)->toBe(200.0)
        ->and((float) $bill->discount_amount)->toBe(20.0)
        ->and((float) $bill->vat_amount)->toBe(9.0)
        ->and((float) $bill->total_amount)->toBe(189.0);
});

test('a draft bill can be edited and its lines replaced', function () {
    [$vendor, $warehouse, $product] = billFixtures();

    $this->post(route('purchase.bills.store'), billPayload($vendor, $warehouse, $product))->assertSessionHasNoErrors();
    $bill = VendorBill::first();

    $response = $this->put(route('purchase.bills.update', $bill->id), billPayload($vendor, $warehouse, $product, [
        'qty' => 10,
        'unit_price' => 100.00,
        'discount_pct' => null,
        'vat_rate' => null,
    ]));

    $response->assertSessionHasNoErrors();
    $response->assertSessionHas('success');

    $fresh = $bill->fresh(['items']);

    expect($fresh->items)->toHaveCount(1, 'lines were appended instead of replaced')
        ->and((float) $fresh->total_amount)->toBe(1000.00)
        ->and((float) $fresh->items->first()->discount_pct)->toBe(0.0);
});

test('a posted bill cannot be edited', function () {
    [$vendor, $warehouse, $product] = billFixtures();

    $bill = VendorBill::create([
        'bill_no' => 'BILL-TEST-1',
        'vendor_id' => $vendor->id,
        'warehouse_id' => $warehouse->id,
        'bill_date' => '2026-02-01',
        'subtotal' => 1000.00,
        'total_amount' => 1000.00,
        'status' => 'posted',
        'payment_status' => 'unpaid',
    ]);

    $response = $this->put(route('purchase.bills.update', $bill->id), billPayload($vendor, $warehouse, $product, [
        'qty' => 99,
    ]));

    $response->assertSessionHas('error');

    expect((float) $bill->fresh()->total_amount)->toBe(1000.00, 'a posted bill accepted an edit')
        ->and($bill->fresh()->status)->toBe('posted');
});
