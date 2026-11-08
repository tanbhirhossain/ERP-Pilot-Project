<?php

/**
 * Page-render smoke tests for the four document forms consolidated in §8.8.
 *
 * `Invoices/Edit.vue` was a 14-line stub that rendered three of its eight fields, and
 * `Invoices/Create.vue` read its order prefill from `(globalThis as any).__orderCustomer` — a
 * global nothing ever assigned. Neither was caught, because the whole suite tested document
 * *writes* and nothing tested that the pages render with the props their forms read.
 *
 * These pin the controller → page contract: the component that resolves, and the specific props
 * the shared forms depend on. `vite build` proves the templates compile; this proves the data
 * they need actually arrives.
 */

use Inertia\Testing\AssertableInertia as Assert;
use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\CORE\Models\User;
use Modules\INVENTORY\Models\Product;
use Modules\INVENTORY\Models\Warehouse;
use Modules\PARTNER\Models\Partner;
use Modules\PURCHASE\Models\VendorBill;
use Modules\SALES\Models\Invoice;
use Modules\SALES\Models\Order;
use Modules\SALES\Models\Quotation;

/**
 * Self-contained on purpose: Pest only loads the helpers of the files it is asked to run, so a
 * test that borrows `billAdmin()` from a sibling file passes in a full suite and fails alone.
 * Names are prefixed because a full run loads every Feature file into one process.
 */
function renderAdmin(): User
{
    test()->seed(SuperAdminSeeder::class);

    $admin = User::where('email', config('core.super_admin.email', 'admin@example.com'))->first();
    expect($admin)->not->toBeNull('SuperAdminSeeder produced no admin user');

    return $admin;
}

/** @return array{0: Partner, 1: Warehouse, 2: Product} */
function renderFixtures(string $type): array
{
    $partner = Partner::create([
        'partner_code' => strtoupper(substr($type, 0, 3)).'-RENDER-1',
        'type' => $type,
        'name' => 'Render Co',
    ]);
    $warehouse = Warehouse::create(['name' => 'Main Warehouse', 'code' => 'WH-RENDER-1']);
    $product = Product::create([
        'name' => 'Widget',
        'sku' => 'WID-RENDER-1',
        'type' => 'stockable',
        'status' => 'active',
        'valuation_method' => 'weighted',
        'cost_price' => 100.00,
        'selling_price' => 150.00,
    ]);

    return [$partner, $warehouse, $product];
}

test('the vendor bill create page renders with no source document', function () {
    $this->actingAs(renderAdmin());

    $this->get(route('purchase.bills.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('PURCHASE::Bills/Create')
            ->whereNull('order')
            ->whereNull('receipt'));
});

test('the vendor bill edit page renders the bill and its lines', function (string $status) {
    $this->actingAs(renderAdmin());
    [$vendor, $warehouse, $product] = renderFixtures('vendor');

    $bill = VendorBill::create([
        'bill_no' => 'BILL-RENDER-1',
        'vendor_id' => $vendor->id,
        'warehouse_id' => $warehouse->id,
        'bill_date' => '2026-02-01',
        'subtotal' => 200.00,
        'total_amount' => 200.00,
        'status' => $status,
        'payment_status' => 'unpaid',
    ]);

    // Explicit nulls, exactly as a cleared discount/VAT input arrives. The coercion trait must
    // store zeros, so the edit form re-opens showing 0 rather than an empty box.
    $bill->items()->create([
        'product_id' => $product->id,
        'product_name' => 'Widget',
        'qty' => 2,
        'unit_price' => 100.00,
        'discount_pct' => null,
        'vat_rate' => null,
        'line_total' => 200.00,
    ]);

    expect((float) $bill->fresh('items')->items[0]->discount_pct)->toBe(0.0)
        ->and((float) $bill->fresh('items')->items[0]->vat_rate)->toBe(0.0);

    $this->get(route('purchase.bills.edit', $bill->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('PURCHASE::Bills/Edit')
            ->where('bill.bill_no', 'BILL-RENDER-1')
            ->where('bill.status', $status)
            ->has('bill.items.0'));
})->with(['draft', 'posted']);

test('the invoice create page renders with no source document', function () {
    $this->actingAs(renderAdmin());

    $this->get(route('sales.invoices.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('SALES::Invoices/Create')
            ->whereNull('order'));
});

test('the invoice create page is given everything the order prefill needs', function () {
    $this->actingAs(renderAdmin());
    [$customer, $warehouse, $product] = renderFixtures('customer');

    $order = Order::create([
        'order_no' => 'SO-RENDER-1',
        'customer_id' => $customer->id,
        'warehouse_id' => $warehouse->id,
        'order_date' => '2026-02-01',
        'status' => 'confirmed',
        'payment_status' => 'unpaid',
        'subtotal' => 600.00,
        'total_amount' => 600.00,
    ]);

    $order->items()->create([
        'product_id' => $product->id,
        // NOT NULL *string* column: `enrichTotals()` back-fills it from the product, but this
        // fixture writes the row directly. The coercion trait covers numerics only, by design.
        'product_name' => 'Widget',
        'qty' => 3,
        'unit_price' => 200.00,
        'discount_pct' => 0,
        'vat_rate' => 0,
        'line_total' => 600.00,
    ]);

    // The regression test for the dead `__orderCustomer` global: `createInvoice()` redirects here
    // with `?order_id=`, and the form now reads customer/warehouse/items off this prop.
    $this->get(route('sales.invoices.create', ['order_id' => $order->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('SALES::Invoices/Create')
            ->where('order.id', $order->id)
            ->where('order.customer_id', $customer->id)
            ->where('order.warehouse_id', $warehouse->id)
            ->has('order.items.0'));
});

test('the invoice edit page renders the invoice and its lines', function () {
    $this->actingAs(renderAdmin());
    [$customer, $warehouse, $product] = renderFixtures('customer');

    $invoice = Invoice::create([
        'invoice_no' => 'INV-RENDER-1',
        'customer_id' => $customer->id,
        'warehouse_id' => $warehouse->id,
        'invoice_date' => '2026-02-01',
        'subtotal' => 600.00,
        'total_amount' => 600.00,
        'status' => 'draft',
        'payment_status' => 'unpaid',
    ]);

    $invoice->items()->create([
        'product_id' => $product->id,
        'product_name' => 'Widget',
        'qty' => 3,
        'unit_price' => 200.00,
        'discount_pct' => null,
        'vat_rate' => null,
        'line_total' => 600.00,
    ]);

    // The old stub threw on `invoice_date.slice(0, 10)` and rendered no date, warehouse, price,
    // discount or VAT field at all. The prop must carry them.
    $this->get(route('sales.invoices.edit', $invoice->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('SALES::Invoices/Edit')
            ->where('invoice.invoice_no', 'INV-RENDER-1')
            ->where('invoice.customer_id', $customer->id)
            ->where('invoice.warehouse_id', $warehouse->id)
            ->has('invoice.items.0'));
});
test('the sales order create page renders', function () {
    $this->actingAs(renderAdmin());

    $this->get(route('sales.orders.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('SALES::Orders/Create'));
});

test('the sales order edit page renders every field the form needs', function () {
    $this->actingAs(renderAdmin());
    [$customer, $warehouse, $product] = renderFixtures('customer');

    $order = Order::create([
        'order_no' => 'SO-RENDER-2',
        'customer_id' => $customer->id,
        'warehouse_id' => $warehouse->id,
        'order_date' => '2026-02-01',
        'delivery_date' => '2026-02-10',
        'status' => 'draft',
        'payment_status' => 'unpaid',
        'subtotal' => 189.00,
        'total_amount' => 189.00,
    ]);

    $order->items()->create([
        'product_id' => $product->id,
        'product_name' => 'Widget',
        'qty' => 2,
        'unit_price' => 100.00,
        'discount_pct' => 10.00,
        'vat_rate' => 5.00,
        'line_total' => 189.00,
    ]);

    // The old stub rendered customer, product and qty only. Discount, VAT, warehouse and the
    // delivery date must all reach the page, or saving through it silently resets them.
    $this->get(route('sales.orders.edit', $order->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('SALES::Orders/Edit')
            ->where('order.order_no', 'SO-RENDER-2')
            ->where('order.warehouse_id', $warehouse->id)
            ->where('order.status', 'draft')
            ->has('order.items.0')
            // Numeric, not string: unlike VendorBillItem and InvoiceItem, OrderItem declares no
            // decimal casts, so these arrive as raw numbers here and as '10.00' strings on MySQL.
            ->where('order.items.0.discount_pct', fn ($v) => (float) $v === 10.0)
            ->where('order.items.0.vat_rate', fn ($v) => (float) $v === 5.0));
});
test('the quotation edit page renders terms, currency and its lines', function () {
    $this->actingAs(renderAdmin());
    [$customer, $warehouse, $product] = renderFixtures('customer');

    $quotation = Quotation::create([
        'quotation_no' => 'QUOT-RENDER-1',
        'customer_id' => $customer->id,
        'quotation_date' => '2026-02-01',
        'expiry_date' => '2026-03-01',
        'currency' => 'USD',
        'terms' => '50% advance.',
        'status' => 'draft',
        'subtotal' => 189.00,
        'total_amount' => 189.00,
    ]);

    $quotation->items()->create([
        'product_id' => $product->id,
        'product_name' => 'Widget',
        'qty' => 2,
        'unit_price' => 100.00,
        'discount_pct' => 10.00,
        'vat_rate' => 5.00,
        'line_total' => 189.00,
        'sort_order' => 0,
    ]);

    // The old stub had no currency field, no terms field and no totals — all three must arrive.
    $this->get(route('sales.quotations.edit', $quotation->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('SALES::Quotations/Edit')
            ->where('quotation.quotation_no', 'QUOT-RENDER-1')
            ->where('quotation.currency', 'USD')
            ->where('quotation.terms', '50% advance.')
            ->where('quotation.status', 'draft')
            ->has('quotation.items.0')
            ->where('quotation.items.0.discount_pct', fn ($v) => (float) $v === 10.0)
            ->where('quotation.items.0.vat_rate', fn ($v) => (float) $v === 5.0));
});
