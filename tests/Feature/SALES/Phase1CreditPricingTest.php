<?php

/**
 * Phase 1 — F11 credit control + F10 contract/tier pricing.
 *
 * Credit control must apply the same exposure arithmetic on every credit path:
 * open receivables + the new amount vs the customer's limit, with `<= 0` meaning
 * "no limit". The pricing engine must resolve partner → default → shelf price,
 * honour quantity tiers, and never override an explicit line price.
 *
 * Helper names are prefixed `p1cp*`.
 */

use Modules\ACCOUNTS\Models\Journal;
use Modules\ACCOUNTS\Models\Receivable;
use Modules\INVENTORY\Models\Product;
use Modules\PARTNER\Models\Partner;
use Modules\PARTNER\Models\PriceList;
use Modules\PARTNER\Models\PriceListItem;
use Modules\PARTNER\Services\PricingService;
use Modules\SALES\Services\CreditControlService;
use Modules\SALES\Support\LinePricer;

function p1cpCustomer(float $limit): Partner
{
    return Partner::create([
        'partner_code' => 'P1CP-'.uniqid(),
        'type' => 'customer',
        'name' => 'P1CP Customer',
        'credit_limit' => $limit,
        'is_active' => true,
        'currency' => 'BDT',
    ]);
}

test('a sale within the credit limit passes', function () {
    $customer = p1cpCustomer(1000);
    app(CreditControlService::class)->assertWithinLimit($customer->id, 500, 'invoice');
    expect(true)->toBeTrue();
});

test('a sale beyond the credit limit is refused', function () {
    $customer = p1cpCustomer(1000);
    $this->expectException(DomainException::class);
    app(CreditControlService::class)->assertWithinLimit($customer->id, 1500, 'invoice');
});

test('open receivables count toward exposure', function () {
    $customer = p1cpCustomer(1000);
    // A receivable always hangs off a journal in production; mirror that here.
    $journal = Journal::create([
        'journal_no' => 'P1CP-JV-'.uniqid(),
        'journal_date' => now()->toDateString(),
        'type' => 'JV',
        'status' => 'posted',
        'total_debit' => 800,
        'total_credit' => 800,
    ]);
    Receivable::create([
        'partner_id' => $customer->id,
        'journal_id' => $journal->id,
        'invoice_no' => 'P1CP-INV-1',
        'invoice_date' => now()->toDateString(),
        'amount' => 800,
        'received_amount' => 0,
        'status' => 'open',
    ]);

    // 800 already owed + 300 new = 1100 > 1000.
    $this->expectException(DomainException::class);
    app(CreditControlService::class)->assertWithinLimit($customer->id, 300, 'order');
});

test('a zero or negative limit means unlimited', function () {
    $customer = p1cpCustomer(0);
    app(CreditControlService::class)->assertWithinLimit($customer->id, 9999999, 'sale');
    expect(true)->toBeTrue();
});

test('a null customer or non-credit amount is a no-op', function () {
    app(CreditControlService::class)->assertWithinLimit(null, 5000, 'sale');
    $customer = p1cpCustomer(100);
    app(CreditControlService::class)->assertWithinLimit($customer->id, 0, 'sale');
    expect(true)->toBeTrue();
});

// ───────────────────────────── pricing engine ─────────────────────────────

function p1cpProduct(float $shelf): Product
{
    return Product::create([
        'name' => 'P1CP Widget',
        'sku' => 'P1CP-'.uniqid(),
        'type' => 'stockable',
        'status' => 'active',
        'valuation_method' => 'weighted',
        'cost_price' => 10,
        'selling_price' => $shelf,
        'is_active' => true,
    ]);
}

test('the partner price list wins over the default and the shelf price', function () {
    $product = p1cpProduct(100);
    $customer = p1cpCustomer(0);

    $default = PriceList::create(['name' => 'P1CP Default', 'code' => 'P1CP-DEF-'.uniqid(), 'is_default' => true, 'is_active' => true]);
    PriceListItem::create(['price_list_id' => $default->id, 'product_id' => $product->id, 'unit_price' => 95, 'min_qty' => 1, 'discount_pct' => 0]);

    $contract = PriceList::create(['name' => 'P1CP Contract', 'code' => 'P1CP-CON-'.uniqid(), 'is_default' => false, 'is_active' => true]);
    PriceListItem::create(['price_list_id' => $contract->id, 'product_id' => $product->id, 'unit_price' => 80, 'min_qty' => 1, 'discount_pct' => 5]);
    $customer->update(['price_list_id' => $contract->id]);

    $resolved = app(PricingService::class)->resolve($product->id, $customer->id, 1);
    expect($resolved['unit_price'])->toBe(80.0)
        ->and($resolved['discount_pct'])->toBe(5.0)
        ->and($resolved['price_list_name'])->toBe('P1CP Contract');
});

test('quantity tiers pick the highest qualifying break', function () {
    $product = p1cpProduct(100);
    $customer = p1cpCustomer(0);
    $list = PriceList::create(['name' => 'P1CP Tier', 'code' => 'P1CP-TIER-'.uniqid(), 'is_default' => true, 'is_active' => true]);
    PriceListItem::create(['price_list_id' => $list->id, 'product_id' => $product->id, 'unit_price' => 90, 'min_qty' => 1, 'discount_pct' => 0]);
    PriceListItem::create(['price_list_id' => $list->id, 'product_id' => $product->id, 'unit_price' => 75, 'min_qty' => 100, 'discount_pct' => 0]);

    $svc = app(PricingService::class);
    expect($svc->resolve($product->id, $customer->id, 1)['unit_price'])->toBe(90.0)
        ->and($svc->resolve($product->id, $customer->id, 99)['unit_price'])->toBe(90.0)
        ->and($svc->resolve($product->id, $customer->id, 100)['unit_price'])->toBe(75.0)
        ->and($svc->resolve($product->id, $customer->id, 500)['unit_price'])->toBe(75.0);
});

test('no price list resolves to null so the caller falls back to shelf', function () {
    $product = p1cpProduct(100);
    $customer = p1cpCustomer(0);
    expect(app(PricingService::class)->resolve($product->id, $customer->id, 1))->toBeNull();
});

test('an inactive price list is ignored', function () {
    $product = p1cpProduct(100);
    $customer = p1cpCustomer(0);
    $list = PriceList::create(['name' => 'P1CP Off', 'code' => 'P1CP-OFF-'.uniqid(), 'is_default' => true, 'is_active' => false]);
    PriceListItem::create(['price_list_id' => $list->id, 'product_id' => $product->id, 'unit_price' => 50, 'min_qty' => 1, 'discount_pct' => 0]);

    expect(app(PricingService::class)->resolve($product->id, $customer->id, 1))->toBeNull();
});

// ───────────────────────────── line pricer ─────────────────────────────

test('LinePricer fills a blank price from the engine', function () {
    $product = p1cpProduct(100);
    $customer = p1cpCustomer(0);
    $list = PriceList::create(['name' => 'P1CP LP', 'code' => 'P1CP-LP-'.uniqid(), 'is_default' => true, 'is_active' => true]);
    PriceListItem::create(['price_list_id' => $list->id, 'product_id' => $product->id, 'unit_price' => 88, 'min_qty' => 1, 'discount_pct' => 2]);

    $item = ['product_id' => $product->id, 'qty' => 3, 'unit_price' => '', 'discount_pct' => ''];
    LinePricer::apply($item, $customer->id);
    expect($item['unit_price'])->toBe(88.0)
        ->and($item['discount_pct'])->toBe(2.0);
});

test('LinePricer never overrides an explicit price or discount', function () {
    $product = p1cpProduct(100);
    $customer = p1cpCustomer(0);
    $list = PriceList::create(['name' => 'P1CP LP2', 'code' => 'P1CP-LP2-'.uniqid(), 'is_default' => true, 'is_active' => true]);
    PriceListItem::create(['price_list_id' => $list->id, 'product_id' => $product->id, 'unit_price' => 88, 'min_qty' => 1, 'discount_pct' => 2]);

    $item = ['product_id' => $product->id, 'qty' => 3, 'unit_price' => 120, 'discount_pct' => 10];
    LinePricer::apply($item, $customer->id);
    expect($item['unit_price'])->toBe(120)
        ->and($item['discount_pct'])->toBe(10);
});

test('LinePricer falls back to the product selling price with no list', function () {
    $product = p1cpProduct(100);
    $item = ['product_id' => $product->id, 'qty' => 1, 'unit_price' => null];
    LinePricer::apply($item, null);
    expect($item['unit_price'])->toBe(100.0);
});
