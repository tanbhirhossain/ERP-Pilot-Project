<?php

/**
 * The sales invoice create/edit contract.
 *
 * The mirror image of PURCHASE/VendorBillContractTest: `sales_invoice_items.discount_pct` and
 * `vat_rate` are NOT NULL, `StoreInvoiceRequest` declares both `nullable`, and
 * `InvoiceService::enrichTotals()` forwards the raw row. Written before the fix and run against
 * the unchanged code so the failures are evidence.
 *
 * Helper names are prefixed: Pest loads every Feature file into one process.
 */

use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\CORE\Models\User;
use Modules\INVENTORY\Models\Product;
use Modules\INVENTORY\Models\Warehouse;
use Modules\PARTNER\Models\Partner;
use Modules\SALES\Models\Invoice;

function invoiceAdmin(): User
{
    test()->seed(SuperAdminSeeder::class);

    $admin = User::where('email', config('core.super_admin.email', 'admin@example.com'))->first();
    expect($admin)->not->toBeNull('SuperAdminSeeder produced no admin user');

    return $admin;
}

function invoiceFixtures(): array
{
    $customer = Partner::create(['partner_code' => 'CUS-INV-1', 'type' => 'customer', 'name' => 'Apex Retail']);
    $warehouse = Warehouse::create(['name' => 'Main Warehouse', 'code' => 'WH-INV-1']);
    $product = Product::create([
        'name' => 'Widget',
        'sku' => 'WID-INV-1',
        'type' => 'stockable',
        'status' => 'active',
        'valuation_method' => 'weighted',
        'cost_price' => 100.00,
        'selling_price' => 150.00,
    ]);

    return [$customer, $warehouse, $product];
}

function invoicePayload(Partner $customer, Warehouse $warehouse, Product $product, array $itemOverrides = []): array
{
    return [
        'customer_id' => $customer->id,
        'warehouse_id' => $warehouse->id,
        'invoice_date' => '2026-02-01',
        'due_date' => '2026-02-15',
        'payment_method' => 'credit',
        'notes' => 'February sale',
        'items' => [
            array_merge([
                'product_id' => $product->id,
                'qty' => 3,
                'unit_price' => 200.00,
                'discount_pct' => 0,
                'vat_rate' => 0,
            ], $itemOverrides),
        ],
    ];
}

beforeEach(function () {
    $this->actingAs(invoiceAdmin());
});

test('an invoice can be created from a single line', function () {
    [$customer, $warehouse, $product] = invoiceFixtures();

    $response = $this->post(route('sales.invoices.store'), invoicePayload($customer, $warehouse, $product));

    $response->assertSessionHasNoErrors();
    $response->assertSessionHas('success');

    $invoice = Invoice::first();

    expect($invoice)->not->toBeNull('the invoice was not created')
        ->and((float) $invoice->total_amount)->toBe(600.00)
        ->and($invoice->items)->toHaveCount(1);
});

test('clearing the discount and VAT fields on an invoice line does not 500', function () {
    [$customer, $warehouse, $product] = invoiceFixtures();

    $response = $this->post(route('sales.invoices.store'), invoicePayload($customer, $warehouse, $product, [
        'discount_pct' => null,
        'vat_rate' => null,
    ]));

    $response->assertSessionHasNoErrors();
    $response->assertSessionHas('success');

    $line = Invoice::first()->items->first();

    expect((float) $line->discount_pct)->toBe(0.0, 'a cleared discount was not coerced')
        ->and((float) $line->vat_rate)->toBe(0.0, 'a cleared VAT rate was not coerced')
        ->and((float) $line->line_total)->toBe(600.00);
});

test('invoice totals apply the discount before VAT', function () {
    [$customer, $warehouse, $product] = invoiceFixtures();

    $this->post(route('sales.invoices.store'), invoicePayload($customer, $warehouse, $product, [
        'qty' => 2,
        'unit_price' => 100.00,
        'discount_pct' => 10,
        'vat_rate' => 5,
    ]))->assertSessionHasNoErrors();

    $invoice = Invoice::first();

    expect((float) $invoice->subtotal)->toBe(200.0)
        ->and((float) $invoice->discount_amount)->toBe(20.0)
        ->and((float) $invoice->vat_amount)->toBe(9.0)
        ->and((float) $invoice->total_amount)->toBe(189.0);
});

test('a posted invoice cannot be edited', function () {
    [$customer, $warehouse, $product] = invoiceFixtures();

    $invoice = Invoice::create([
        'invoice_no' => 'INV-TEST-1',
        'customer_id' => $customer->id,
        'warehouse_id' => $warehouse->id,
        'invoice_date' => '2026-02-01',
        'subtotal' => 600.00,
        'total_amount' => 600.00,
        'status' => 'posted',
        'payment_status' => 'unpaid',
    ]);

    $response = $this->put(route('sales.invoices.update', $invoice->id), invoicePayload($customer, $warehouse, $product, [
        'qty' => 99,
    ]));

    $response->assertSessionHas('error');

    expect((float) $invoice->fresh()->total_amount)->toBe(600.00, 'a posted invoice accepted an edit')
        ->and($invoice->fresh()->status)->toBe('posted');
});
