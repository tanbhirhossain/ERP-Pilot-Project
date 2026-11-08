<?php

/**
 * Voiding a POS sale: what it may undo, and what it must not.
 *
 * This file used to pin the old model, in which every counter sale carried a `sales_invoices` row and
 * a posted journal, so voiding meant reversing that journal — and its real subject was a swallowed
 * `catch (\Throwable) {}` around the reversal that marked the sale void anyway (report item 26). The
 * day-end redesign (item 39) removed the per-sale invoice and journal: a POS sale is a sale, and the
 * ledger is written once when the drawer closes. Voiding therefore has a different contract, and this
 * file pins that one over HTTP, where `PosDayEndPostingTest` pins it at the service.
 *
 * The two invariants that survive the redesign, and are the reason this file still exists:
 *
 *  - **A failure while undoing must not half-apply.** If the stock cannot be put back, the sale stays
 *    completed and the operator is told why — the original defect, in its only remaining shape.
 *  - **A posted day cannot be edited.** Once the session has closed, its revenue, VAT and cash are in
 *    the ledger. Voiding then would return the stock and leave the money booked, with nothing
 *    reversing it, so it is refused and the operator is pointed at a sales return instead.
 */

use Modules\ACCOUNTS\Models\Journal;
use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\CORE\Models\User;
use Modules\INVENTORY\Interfaces\InventoryStockProviderInterface;
use Modules\INVENTORY\Models\Warehouse;
use Modules\PARTNER\Models\Partner;
use Modules\SALES\Models\PosSale;
use Modules\SALES\Models\PosSession;

/** Prefixed: Pest loads every Feature file into one process, and PosTerminalTest already owns `posAdmin`. */
function posVoidAdmin(): User
{
    test()->seed(SuperAdminSeeder::class);

    $admin = User::where('email', config('core.super_admin.email', 'admin@example.com'))->first();
    expect($admin)->not->toBeNull('SuperAdminSeeder produced no admin user');

    return $admin;
}

/**
 * A completed 500.00 cash sale inside a drawer that has already counted it.
 *
 * `$posted` decides the state of the day. A posted day gets a real `acc_journals` row, because
 * `pos_sessions.journal_id` is a foreign key: a made-up id is a constraint violation, not a fixture.
 */
function posVoidFixture(User $admin, bool $posted = false): PosSale
{
    $customer = Partner::create(['partner_code' => 'CUS-POSVOID-1', 'type' => 'customer', 'name' => 'Walk-in']);
    $warehouse = Warehouse::create(['name' => 'Main Warehouse', 'code' => 'WH-POSVOID-1', 'type' => 'main']);

    $sessionOverrides = [];

    if ($posted) {
        $journal = Journal::create([
            'journal_no' => 'JV-POSVOID-DAY-1',
            'journal_date' => '2026-02-01',
            'type' => 'SV',
            'status' => 'posted',
            'narration' => 'POS day-end POS-SESSION-VOID-1',
            'total_debit' => 500.00,
            'total_credit' => 500.00,
        ]);

        $sessionOverrides = [
            'status' => 'closed',
            'journal_id' => $journal->id,
            'posted_at' => '2026-02-01 21:00:00',
            'closed_at' => '2026-02-01 21:00:00',
            'closed_by' => $admin->id,
        ];
    }

    $session = PosSession::create($sessionOverrides + [
        'session_no' => 'POS-SESSION-VOID-1',
        'warehouse_id' => $warehouse->id,
        'opened_by' => $admin->id,
        'opening_cash' => 0,
        'status' => 'open',
        'total_sales' => 500.00,
        'transactions_count' => 1,
    ]);

    return PosSale::create([
        'pos_no' => 'POS-VOID-1',
        'session_id' => $session->id,
        'warehouse_id' => $warehouse->id,
        'customer_id' => $customer->id,
        'sale_date' => '2026-02-01',
        'payment_method' => 'cash',
        'status' => 'completed',
        'subtotal' => 500.00,
        'total_amount' => 500.00,
        'paid_amount' => 500.00,
        'cash_amount' => 500.00,
        'cost_total' => 300.00,
    ]);
}

/** Stock reversal is a separate concern; stub it so a provider failure cannot mask the guard under test. */
function stubRestock(): void
{
    test()->mock(InventoryStockProviderInterface::class, function ($mock) {
        $mock->shouldReceive('restock')->andReturnNull();
    });
}

test('voiding a sale in an open drawer succeeds, puts the stock back and takes the day down with it', function () {
    $this->actingAs($admin = posVoidAdmin());
    $sale = posVoidFixture($admin);
    stubRestock();

    $this->post(route('sales.pos.void', $sale))
        ->assertRedirect()
        ->assertSessionHas('success')
        ->assertSessionMissing('error');

    expect($sale->fresh()->status)->toBe('void');

    // The live counters come back down and the void is *counted*, not erased: the Z-report a supervisor
    // reads shows what was rung up and what was cancelled, which is the pair worth auditing.
    $session = PosSession::find($sale->session_id);
    expect((string) $session->total_sales)->toBe('0.00')
        ->and((int) $session->transactions_count)->toBe(0)
        ->and((int) $session->void_count)->toBe(1);
});

test('a failed stock reversal leaves the sale completed and says why', function () {
    $this->actingAs($admin = posVoidAdmin());
    $sale = posVoidFixture($admin);

    test()->mock(InventoryStockProviderInterface::class, function ($mock) {
        $mock->shouldReceive('restock')->andThrow(new RuntimeException('stock ledger unavailable'));
    });

    $this->post(route('sales.pos.void', $sale))
        ->assertRedirect()
        ->assertSessionHas('error')
        ->assertSessionMissing('success');

    expect((string) session('error'))->toContain('stock ledger unavailable');

    // Nothing half-applied: the sale is still completed and the drawer still carries it.
    expect($sale->fresh()->status)->toBe('completed')
        ->and((string) PosSession::find($sale->session_id)->total_sales)->toBe('500.00')
        ->and((int) PosSession::find($sale->session_id)->void_count)->toBe(0);
});

test('voiding after the day has been posted is refused, and does not put the stock back', function () {
    $this->actingAs($admin = posVoidAdmin());
    $sale = posVoidFixture($admin, posted: true);

    // The whole point: no provider may be touched, because a restock here is stock back on the shelf
    // against revenue and cash that are already in a posted journal.
    test()->mock(InventoryStockProviderInterface::class, function ($mock) {
        $mock->shouldNotReceive('restock');
    });

    $this->post(route('sales.pos.void', $sale))
        ->assertRedirect()
        ->assertSessionHas('error')
        ->assertSessionMissing('success');

    expect((string) session('error'))
        ->toContain('POS-SESSION-VOID-1')
        ->toContain('posted')
        ->toContain('sales return');

    expect($sale->fresh()->status)->toBe('completed');
});

test('voiding twice is refused before any provider is touched', function () {
    $this->actingAs($admin = posVoidAdmin());
    $sale = posVoidFixture($admin);
    $sale->update(['status' => 'void']);

    test()->mock(InventoryStockProviderInterface::class, function ($mock) {
        $mock->shouldNotReceive('restock');
    });

    $this->post(route('sales.pos.void', $sale))
        ->assertRedirect()
        ->assertSessionHas('error');

    expect((string) session('error'))->toContain('Already voided');
});
