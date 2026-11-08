<?php

/**
 * Voiding a posted document must not survive a failed journal reversal.
 *
 * `VendorBillService::void()` and `GoodsReceiptService::void()` both wrapped the ledger reversal
 * in `try { … } catch (\Throwable $e) {}` and then carried on to mark the document cancelled. Two
 * things were wrong with that, and they compound:
 *
 *   1. the AP/inventory journal stays **posted** against a document the system now calls
 *      cancelled, so the ledger and the purchase record disagree permanently; and
 *   2. both calls sit inside `DB::transaction()`, and swallowing the exception is precisely what
 *      stops the transaction rolling back — the one place the failure could have been undone for
 *      free was the place that discarded it.
 *
 * They now rethrow as `DomainException`, which the controllers already catch and flash, so the
 * document stays posted and the user is told why.
 *
 * This is the most consequential of the 27 empty catches recorded in §4 P1.1; the rest are listed
 * there and most are not on a money path.
 */

use Modules\ACCOUNTS\Interfaces\AccountsPostingProviderInterface;
use Modules\ACCOUNTS\Models\Journal;
use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\CORE\Models\User;
use Modules\INVENTORY\Models\Product;
use Modules\INVENTORY\Models\Warehouse;
use Modules\PARTNER\Models\Partner;
use Modules\PURCHASE\Models\VendorBill;

/** Prefixed: Pest loads every Feature file into one process. */
function voidAdmin(): User
{
    test()->seed(SuperAdminSeeder::class);

    $admin = User::where('email', config('core.super_admin.email', 'admin@example.com'))->first();
    expect($admin)->not->toBeNull('SuperAdminSeeder produced no admin user');

    return $admin;
}

/** A posted bill wired to a real journal — `purchase_bills.journal_id` is a FK to `acc_journals`. */
function voidPostedBill(): VendorBill
{
    $vendor = Partner::create(['partner_code' => 'VEN-VOID-1', 'type' => 'vendor', 'name' => 'Acme Supplies']);
    $warehouse = Warehouse::create(['name' => 'Main Warehouse', 'code' => 'WH-VOID-1']);
    Product::create([
        'name' => 'Widget',
        'sku' => 'WID-VOID-1',
        'type' => 'stockable',
        'status' => 'active',
        'valuation_method' => 'weighted',
        'cost_price' => 100.00,
        'selling_price' => 150.00,
    ]);

    $journal = Journal::create([
        'journal_no' => 'JV-VOID-1',
        'journal_date' => '2026-02-01',
        'type' => 'PV',
        'status' => 'posted',
        'narration' => 'Bill BILL-VOID-1',
        'total_debit' => 1000.00,
        'total_credit' => 1000.00,
    ]);

    return VendorBill::create([
        'bill_no' => 'BILL-VOID-1',
        'vendor_id' => $vendor->id,
        'warehouse_id' => $warehouse->id,
        'bill_date' => '2026-02-01',
        'subtotal' => 1000.00,
        'total_amount' => 1000.00,
        'status' => 'posted',
        'payment_status' => 'unpaid',
        'journal_id' => $journal->id,
    ]);
}

test('a failed journal reversal leaves the bill posted and says why', function () {
    $this->actingAs(voidAdmin());
    $bill = voidPostedBill();

    $this->mock(AccountsPostingProviderInterface::class, function ($mock) {
        $mock->shouldReceive('voidJournal')->andThrow(new RuntimeException('ledger connection lost'));
    });

    $response = $this->post(route('purchase.bills.void', $bill));

    // The controller catches DomainException and flashes it — not a 500, and not a false success.
    $response->assertRedirect();
    $response->assertSessionHas('error');
    $response->assertSessionMissing('success');

    expect((string) session('error'))
        ->toContain('BILL-VOID-1')
        ->toContain('left posted')
        ->toContain('ledger connection lost');

    // The transaction rolled back: the document is exactly as it was.
    expect($bill->fresh()->status)->toBe('posted');

    // And the ledger was not silently abandoned — the journal is still posted too, which is the
    // consistent state. The old code left this posted while calling the bill cancelled.
    expect(Journal::find($bill->journal_id)->status)->toBe('posted');
});

test('voiding still succeeds when the reversal succeeds', function () {
    $this->actingAs(voidAdmin());
    $bill = voidPostedBill();

    $this->mock(AccountsPostingProviderInterface::class, function ($mock) use ($bill) {
        $mock->shouldReceive('voidJournal')->andReturn(
            Journal::find($bill->journal_id)
        );
    });

    $this->post(route('purchase.bills.void', $bill))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($bill->fresh()->status)->toBe('cancelled');
});

test('voiding a paid bill is still refused before any ledger call', function () {
    $this->actingAs(voidAdmin());
    $bill = voidPostedBill();
    $bill->update(['paid_amount' => 500.00]);

    $this->mock(AccountsPostingProviderInterface::class, function ($mock) {
        $mock->shouldNotReceive('voidJournal');
    });

    $this->post(route('purchase.bills.void', $bill))
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($bill->fresh()->status)->toBe('posted');
});
