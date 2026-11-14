<?php

/**
 * Phase 3 — F17 Bangladesh tax compliance.
 *
 * Covers the Mushak 9.1 VAT return (output = invoices + POS − credit notes,
 * input = bills − debit notes, net of certified VDS) and the TDS/VDS withholding
 * engine (threshold, idempotency, outward certification, all-or-nothing deposit).
 * Also pins the permission gates and the PDF export.
 *
 * Helper names are prefixed `p3tax*`.
 */

use Modules\ACCOUNTS\Models\TaxDeposit;
use Modules\ACCOUNTS\Models\Withholding;
use Modules\ACCOUNTS\Models\WithholdingRule;
use Modules\ACCOUNTS\Services\VatReturnService;
use Modules\ACCOUNTS\Services\WithholdingService;
use Modules\CORE\Models\User;
use Modules\INVENTORY\Models\Product;
use Modules\INVENTORY\Models\Warehouse;
use Modules\PARTNER\Models\Partner;
use Modules\PURCHASE\Models\PurchaseReturn;
use Modules\PURCHASE\Models\VendorBill;
use Modules\SALES\Models\Invoice;
use Modules\SALES\Models\InvoiceItem;
use Modules\SALES\Models\PosSale;
use Modules\SALES\Models\SalesReturn;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

function p3taxPartner(string $type): Partner
{
    return Partner::create([
        'partner_code' => 'P3TAX-'.uniqid(), 'type' => $type, 'name' => 'P3TAX '.ucfirst($type),
        'is_active' => true, 'currency' => 'BDT',
    ]);
}

function p3taxUser(array $permissions, int $salt): User
{
    $role = Role::create(['name' => "P3TAX Role {$salt}", 'guard_name' => 'web']);
    foreach ($permissions as $p) {
        Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        $role->givePermissionTo($p);
    }
    $user = User::create([
        'name' => "P3TAX User {$salt}", 'email' => "p3tax{$salt}@example.test",
        'password' => bcrypt('secret-password'), 'email_verified_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

function p3taxInvoice(float $subtotal, float $vat, float $rate, string $status = 'posted', ?string $date = null): Invoice
{
    $invoice = Invoice::create([
        'invoice_no' => 'P3TAX-INV-'.uniqid(), 'customer_id' => p3taxPartner('customer')->id,
        'warehouse_id' => Warehouse::factory()->create()->id, 'invoice_date' => $date ?? now()->toDateString(),
        'currency' => 'BDT', 'subtotal' => $subtotal, 'discount_amount' => 0, 'vat_amount' => $vat,
        'total_amount' => $subtotal + $vat, 'paid_amount' => 0, 'status' => $status, 'payment_status' => 'unpaid',
    ]);
    InvoiceItem::create([
        'invoice_id' => $invoice->id, 'product_id' => Product::factory()->create()->id,
        'product_name' => 'P3TAX line', 'qty' => 1, 'unit_price' => $subtotal, 'discount_pct' => 0,
        'discount_amount' => 0, 'vat_rate' => $rate, 'vat_amount' => $vat, 'line_total' => $subtotal,
    ]);

    return $invoice;
}

function p3taxPos(float $subtotal, float $vat, string $status = 'completed'): PosSale
{
    return PosSale::create([
        'pos_no' => 'P3TAX-POS-'.uniqid(), 'warehouse_id' => Warehouse::factory()->create()->id,
        'sale_date' => now()->toDateString(), 'customer_name' => 'Walk-in', 'subtotal' => $subtotal,
        'discount_amount' => 0, 'vat_amount' => $vat, 'total_amount' => $subtotal + $vat,
        'paid_amount' => $subtotal + $vat, 'payment_method' => 'cash', 'status' => $status,
    ]);
}

function p3taxBill(float $subtotal, float $vat, string $status = 'posted'): VendorBill
{
    return VendorBill::create([
        'bill_no' => 'P3TAX-BILL-'.uniqid(), 'vendor_id' => p3taxPartner('vendor')->id,
        'warehouse_id' => Warehouse::factory()->create()->id, 'bill_date' => now()->toDateString(),
        'currency' => 'BDT', 'subtotal' => $subtotal, 'discount_amount' => 0, 'vat_amount' => $vat,
        'total_amount' => $subtotal + $vat, 'paid_amount' => 0, 'status' => $status, 'payment_status' => 'unpaid',
    ]);
}

/* ───────────────────────────── Mushak 9.1 ───────────────────────────── */

test('output VAT combines posted invoices and completed POS sales, bucketed by rate', function () {
    p3taxInvoice(1000, 150, 15);      // standard
    p3taxInvoice(500, 37.5, 7.5);     // reduced
    p3taxPos(200, 30);                // POS → standard

    $ret = app(VatReturnService::class)->forMonth(now()->format('Y-m'));

    expect($ret['output']['standard']['vat'])->toBe(180.0)   // 150 + 30
        ->and($ret['output']['reduced']['vat'])->toBe(37.5)
        ->and($ret['output']['total_vat'])->toBe(217.5);
});

test('draft and void documents are excluded from the return', function () {
    p3taxInvoice(1000, 150, 15, 'posted');
    p3taxInvoice(9000, 1350, 15, 'draft');
    p3taxInvoice(9000, 1350, 15, 'void');
    p3taxPos(9000, 1350, 'void');

    $ret = app(VatReturnService::class)->forMonth(now()->format('Y-m'));

    expect($ret['output']['total_vat'])->toBe(150.0);
});

test('approved sales returns reduce output VAT as a decreasing adjustment', function () {
    $invoice = p3taxInvoice(1000, 150, 15);
    SalesReturn::create([
        'return_no' => 'P3TAX-SR-'.uniqid(), 'invoice_id' => $invoice->id, 'customer_id' => $invoice->customer_id,
        'warehouse_id' => $invoice->warehouse_id, 'return_date' => now()->toDateString(), 'status' => 'approved',
        'refund_method' => 'credit', 'subtotal' => 200, 'vat_amount' => 30, 'total_amount' => 230,
    ]);

    $ret = app(VatReturnService::class)->forMonth(now()->format('Y-m'));

    expect($ret['output_adjustment'])->toBe(30.0)
        ->and($ret['net_output_vat'])->toBe(120.0);
});

test('input VAT is posted bills less posted purchase returns', function () {
    p3taxBill(2000, 300);
    PurchaseReturn::create([
        'return_no' => 'P3TAX-PR-'.uniqid(), 'vendor_id' => p3taxPartner('vendor')->id,
        'warehouse_id' => Warehouse::factory()->create()->id, 'return_date' => now()->toDateString(),
        'subtotal' => 400, 'discount_amount' => 0, 'vat_amount' => 60, 'total_amount' => 460, 'status' => 'posted',
    ]);

    $ret = app(VatReturnService::class)->forMonth(now()->format('Y-m'));

    expect($ret['input']['total_vat'])->toBe(300.0)
        ->and($ret['input_adjustment'])->toBe(60.0)
        ->and($ret['net_input_vat'])->toBe(240.0);
});

test('net payable when output exceeds input; credit carried forward otherwise', function () {
    p3taxInvoice(1000, 150, 15);
    p3taxBill(200, 30);
    $payable = app(VatReturnService::class)->forMonth(now()->format('Y-m'));
    expect($payable['net_payable'])->toBe(120.0)->and($payable['credit_carried_forward'])->toBe(0.0);
});

test('a credit position carries forward, never a negative payable', function () {
    p3taxInvoice(100, 15, 15);
    p3taxBill(5000, 750, 'posted');
    $ret = app(VatReturnService::class)->forMonth(now()->format('Y-m'));
    expect($ret['net_payable'])->toBe(0.0)->and($ret['credit_carried_forward'])->toBe(735.0);
});

test('certified outward VDS credits the return; pending VDS does not', function () {
    p3taxInvoice(1000, 150, 15);
    $invoice = p3taxInvoice(1000, 150, 15);
    $svc = app(WithholdingService::class);
    $pending = $svc->recordOutward($invoice, 5, 1000);          // 50, pending → not claimable
    $certified = $svc->recordOutward($invoice, 3, 1000);        // 30, will certify
    $svc->certify($certified, 'VDS-CERT-001');

    $ret = app(VatReturnService::class)->forMonth(now()->format('Y-m'));

    expect($ret['vds_credit'])->toBe(30.0)
        ->and($ret['net_payable'])->toBe(270.0);   // 300 output − 30 VDS
});

/* ───────────────────────────── Withholding ───────────────────────────── */

test('applying a rule to a posted bill deducts rate x base and is idempotent', function () {
    $bill = p3taxBill(10000, 1500);
    $rule = WithholdingRule::create(['name' => 'TDS services', 'type' => 'tds', 'rate' => 10, 'threshold' => 0, 'is_active' => true]);
    $svc = app(WithholdingService::class);

    $w = $svc->applyToBill($bill, $rule);
    expect($w->amount)->toBe('1000.00')->and($w->status)->toBe('pending')->and($w->direction)->toBe('inward');

    $again = $svc->applyToBill($bill, $rule);
    expect($again->id)->toBe($w->id)->and(Withholding::count())->toBe(1);
});

test('a bill below the rule threshold requires no deduction', function () {
    $bill = p3taxBill(500, 75);
    $rule = WithholdingRule::create(['name' => 'TDS', 'type' => 'tds', 'rate' => 10, 'threshold' => 1000, 'is_active' => true]);

    expect(app(WithholdingService::class)->applyToBill($bill, $rule))->toBeNull();
});

test('withholding refuses a draft bill', function () {
    $bill = p3taxBill(10000, 1500, 'draft');
    $rule = WithholdingRule::create(['name' => 'TDS', 'type' => 'tds', 'rate' => 10, 'threshold' => 0, 'is_active' => true]);
    $this->expectException(HttpException::class);
    app(WithholdingService::class)->applyToBill($bill, $rule);
});

test('deposit marks selected withholdings deposited and writes one challan, all-or-nothing', function () {
    $rule = WithholdingRule::create(['name' => 'TDS', 'type' => 'tds', 'rate' => 10, 'threshold' => 0, 'is_active' => true]);
    $svc = app(WithholdingService::class);
    $a = $svc->applyToBill(p3taxBill(10000, 1500), $rule);
    $b = $svc->applyToBill(p3taxBill(20000, 3000), $rule);

    $deposit = $svc->deposit([$a->id, $b->id], ['period' => now()->startOfMonth()->toDateString(), 'deposit_date' => now()->toDateString(), 'challan_no' => 'CH-001']);

    expect($deposit->amount)->toBe('3000.00')
        ->and($a->fresh()->status)->toBe('deposited')
        ->and($b->fresh()->status)->toBe('deposited')
        ->and($a->fresh()->deposit_id)->toBe($deposit->id)
        ->and(TaxDeposit::count())->toBe(1);
});

test('a single challan cannot mix TDS and VDS', function () {
    $tds = WithholdingRule::create(['name' => 'TDS', 'type' => 'tds', 'rate' => 10, 'threshold' => 0, 'is_active' => true]);
    $vds = WithholdingRule::create(['name' => 'VDS', 'type' => 'vds', 'rate' => 5, 'threshold' => 0, 'is_active' => true]);
    $svc = app(WithholdingService::class);
    $a = $svc->applyToBill(p3taxBill(10000, 1500), $tds);
    $b = $svc->applyToBill(p3taxBill(10000, 1500), $vds);

    $this->expectException(HttpException::class);
    $svc->deposit([$a->id, $b->id], ['period' => now()->toDateString(), 'deposit_date' => now()->toDateString()]);
});

/* ───────────────────────────── HTTP gates ───────────────────────────── */

test('the VAT return page requires accounts.reports.view', function () {
    $without = p3taxUser([], 1);
    $this->actingAs($without)->get(route('accounts.reports.vat-return'))->assertForbidden();

    $with = p3taxUser(['accounts.reports.view'], 2);
    $this->actingAs($with)->get(route('accounts.reports.vat-return'))->assertOk();
});

test('the withholding page requires accounts.withholding.view', function () {
    $without = p3taxUser([], 3);
    $this->actingAs($without)->get(route('accounts.tax.withholding.index'))->assertForbidden();

    $with = p3taxUser(['accounts.withholding.view'], 4);
    $this->actingAs($with)->get(route('accounts.tax.withholding.index'))->assertOk();
});

test('the Mushak 9.1 PDF exports for an authorised user', function () {
    p3taxInvoice(1000, 150, 15);
    $user = p3taxUser(['accounts.reports.view'], 5);
    $response = $this->actingAs($user)->get(route('accounts.reports.vat-return.pdf', ['month' => now()->format('Y-m')]));
    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});
