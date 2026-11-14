<?php

/**
 * Phase 1 — F4 opening-balance take-on.
 *
 * Each tab posts a balanced journal against Opening Balance Equity (3900), a
 * reference can only be posted once (idempotent go-live), AR/AP also create the
 * sub-ledger row, and stock also creates the stock move. The chart of accounts is
 * seeded by AccountsDemoSeeder (never a migration), so 3900 exists here.
 *
 * Helper names are prefixed `p1ob*`.
 */

use Illuminate\Support\Facades\DB;
use Modules\ACCOUNTS\Database\Seeders\AccountsDemoSeeder;
use Modules\ACCOUNTS\Models\Account;
use Modules\ACCOUNTS\Models\Payable;
use Modules\ACCOUNTS\Models\Receivable;
use Modules\ACCOUNTS\Services\OpeningBalanceService;
use Modules\CORE\Models\OpeningBalanceBatch;
use Modules\INVENTORY\Models\Product;
use Modules\INVENTORY\Models\Warehouse;
use Modules\PARTNER\Models\Partner;

function p1obSeed(): void
{
    test()->seed(AccountsDemoSeeder::class);
}

function p1obAccount(string $code): Account
{
    return Account::where('code', $code)->firstOrFail();
}

function p1obBalanced(int $journalId): bool
{
    $dr = (float) DB::table('acc_account_ledger')->where('journal_id', $journalId)->sum('debit');
    $cr = (float) DB::table('acc_account_ledger')->where('journal_id', $journalId)->sum('credit');

    return abs($dr - $cr) < 0.005;
}

test('COA opening balances post a balanced journal to equity', function () {
    p1obSeed();
    $cash = p1obAccount('1001');
    $svc = app(OpeningBalanceService::class);

    $batch = $svc->postCoa('P1OB-COA-1', now()->toDateString(), [
        ['account_id' => $cash->id, 'debit' => 50000, 'credit' => 0],
    ]);

    expect($batch)->toBeInstanceOf(OpeningBalanceBatch::class)
        ->and($batch->type)->toBe('coa')
        ->and((float) $batch->total_amount)->toBe(50000.0)
        ->and($batch->journal_id)->not->toBeNull()
        ->and(p1obBalanced($batch->journal_id))->toBeTrue();

    // The offset landed in Opening Balance Equity (3900).
    $equity = p1obAccount('3900');
    $equityCredit = (float) DB::table('acc_account_ledger')
        ->where('journal_id', $batch->journal_id)->where('account_id', $equity->id)->sum('credit');
    expect($equityCredit)->toBe(50000.0);
});

test('a reference cannot be posted twice', function () {
    p1obSeed();
    $cash = p1obAccount('1001');
    $svc = app(OpeningBalanceService::class);

    $svc->postCoa('P1OB-COA-DUP', now()->toDateString(), [['account_id' => $cash->id, 'debit' => 100, 'credit' => 0]]);

    $this->expectException(DomainException::class);
    $svc->postCoa('P1OB-COA-DUP', now()->toDateString(), [['account_id' => $cash->id, 'debit' => 200, 'credit' => 0]]);
});

test('opening receivables post to AR and create the sub-ledger row', function () {
    p1obSeed();
    $customer = Partner::create(['partner_code' => 'P1OB-C-'.uniqid(), 'type' => 'customer', 'name' => 'P1OB Customer', 'is_active' => true, 'currency' => 'BDT']);
    $svc = app(OpeningBalanceService::class);

    $batch = $svc->postAr('P1OB-AR-1', now()->toDateString(), [
        ['partner_id' => $customer->id, 'invoice_no' => 'OPEN-INV-1', 'invoice_date' => now()->toDateString(), 'amount' => 7500],
    ]);

    expect(p1obBalanced($batch->journal_id))->toBeTrue();

    $receivable = Receivable::where('partner_id', $customer->id)->where('invoice_no', 'OPEN-INV-1')->first();
    expect($receivable)->not->toBeNull()
        ->and((float) $receivable->amount)->toBe(7500.0)
        ->and((float) $receivable->received_amount)->toBe(0.0)
        ->and((float) $receivable->balance)->toBe(7500.0);

    // Dr AR (1100), Cr equity (3900).
    $ar = p1obAccount('1100');
    $arDebit = (float) DB::table('acc_account_ledger')->where('journal_id', $batch->journal_id)->where('account_id', $ar->id)->sum('debit');
    expect($arDebit)->toBe(7500.0);
});

test('opening payables post to AP and create the sub-ledger row', function () {
    p1obSeed();
    $vendor = Partner::create(['partner_code' => 'P1OB-V-'.uniqid(), 'type' => 'vendor', 'name' => 'P1OB Vendor', 'is_active' => true, 'currency' => 'BDT']);
    $svc = app(OpeningBalanceService::class);

    $batch = $svc->postAp('P1OB-AP-1', now()->toDateString(), [
        ['partner_id' => $vendor->id, 'bill_no' => 'OPEN-BILL-1', 'bill_date' => now()->toDateString(), 'amount' => 4200],
    ]);

    expect(p1obBalanced($batch->journal_id))->toBeTrue();

    $payable = Payable::where('partner_id', $vendor->id)->where('bill_no', 'OPEN-BILL-1')->first();
    expect($payable)->not->toBeNull()
        ->and((float) $payable->amount)->toBe(4200.0)
        ->and((float) $payable->balance)->toBe(4200.0);
});

test('opening stock creates a stock move and a balanced inventory journal', function () {
    p1obSeed();
    $product = Product::create(['name' => 'P1OB Stock Item', 'sku' => 'P1OB-'.uniqid(), 'type' => 'stockable', 'status' => 'active', 'valuation_method' => 'weighted', 'cost_price' => 50, 'selling_price' => 80, 'is_active' => true]);
    $warehouse = Warehouse::create(['name' => 'P1OB WH', 'code' => 'P1OB-WH-'.uniqid()]);
    $svc = app(OpeningBalanceService::class);

    $batch = $svc->postStock('P1OB-STK-1', now()->toDateString(), [
        ['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'qty' => 10, 'unit_cost' => 50],
    ]);

    expect(p1obBalanced($batch->journal_id))->toBeTrue()
        ->and((float) $batch->total_amount)->toBe(500.0);

    // Stock landed in the warehouse.
    $onHand = (float) DB::table('inventory_stocks')->where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->sum('qty_on_hand');
    expect($onHand)->toBe(10.0);

    // Dr Inventory (1200), Cr equity (3900).
    $inventory = p1obAccount('1200');
    $invDebit = (float) DB::table('acc_account_ledger')->where('journal_id', $batch->journal_id)->where('account_id', $inventory->id)->sum('debit');
    expect($invDebit)->toBe(500.0);
});
