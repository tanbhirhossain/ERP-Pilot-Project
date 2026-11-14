<?php

/**
 * Phase 2 — F21 scheduled jobs + U14 activity timeline.
 *
 * Each business job detects its condition, notifies the right permission holders
 * and is idempotent within a day (a second run is a no-op). The timeline service
 * turns the Spatie activity log into a field-level diff.
 *
 * Helper names are prefixed `p2st*`.
 */

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\ACCOUNTS\Models\Journal;
use Modules\ACCOUNTS\Models\Receivable;
use Modules\CORE\Models\User;
use Modules\CORE\Services\ActivityTimelineService;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Leave\HrmLeaveBalance;
use Modules\HRM\Models\Leave\LeaveType;
use Modules\INVENTORY\Models\Product;
use Modules\INVENTORY\Models\Warehouse;
use Modules\PARTNER\Models\Partner;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function p2stUser(string $permission, int $salt): User
{
    Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    $role = Role::create(['name' => "P2ST Role {$salt}", 'guard_name' => 'web']);
    $role->givePermissionTo($permission);

    $user = User::create([
        'name' => "P2ST User {$salt}", 'email' => "p2st{$salt}@example.test",
        'password' => bcrypt('secret-password'), 'email_verified_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

test('low-stock alert notifies once per day', function () {
    p2stUser('inventory.products.view', 1);
    Product::factory()->create(['reorder_level' => 100, 'is_active' => true]); // no stock => below reorder

    Artisan::call('erp:low-stock-alerts');
    $first = DB::table('notifications')->count();
    expect($first)->toBeGreaterThan(0);

    Artisan::call('erp:low-stock-alerts'); // same day => idempotent
    expect(DB::table('notifications')->count())->toBe($first);
});

test('overdue-invoice reminder notifies once per day', function () {
    p2stUser('sales.invoices.view', 2);
    $customer = Partner::create(['partner_code' => 'P2ST-'.uniqid(), 'type' => 'customer', 'name' => 'P2ST Customer', 'is_active' => true, 'currency' => 'BDT']);
    $warehouse = Warehouse::factory()->create();
    DB::table('sales_invoices')->insert([
        'invoice_no' => 'P2ST-OVD-1', 'customer_id' => $customer->id, 'warehouse_id' => $warehouse->id,
        'invoice_date' => now()->subDays(40)->toDateString(), 'due_date' => now()->subDays(5)->toDateString(),
        'status' => 'posted', 'total_amount' => 5000, 'paid_amount' => 1000, 'created_at' => now(), 'updated_at' => now(),
    ]);

    Artisan::call('erp:overdue-invoice-reminders');
    $first = DB::table('notifications')->count();
    expect($first)->toBeGreaterThan(0);

    Artisan::call('erp:overdue-invoice-reminders');
    expect(DB::table('notifications')->count())->toBe($first);
});

test('credit-limit review flags customers near their limit once per day', function () {
    p2stUser('sales.invoices.view', 3);
    $customer = Partner::create(['partner_code' => 'P2ST-'.uniqid(), 'type' => 'customer', 'name' => 'P2ST Debtor', 'credit_limit' => 1000, 'is_active' => true, 'currency' => 'BDT']);
    $journal = Journal::create(['journal_no' => 'P2ST-JV-'.uniqid(), 'journal_date' => now()->toDateString(), 'type' => 'JV', 'status' => 'posted', 'total_debit' => 900, 'total_credit' => 900]);
    Receivable::create(['partner_id' => $customer->id, 'journal_id' => $journal->id, 'invoice_no' => 'P2ST-AR-1', 'invoice_date' => now()->toDateString(), 'amount' => 900, 'received_amount' => 0, 'status' => 'open']);

    Artisan::call('erp:credit-limit-review');
    $first = DB::table('notifications')->count();
    expect($first)->toBeGreaterThan(0);

    Artisan::call('erp:credit-limit-review');
    expect(DB::table('notifications')->count())->toBe($first);
});

test('leave-balance accrual materialises entitlements idempotently', function () {
    Employee::factory()->create();
    LeaveType::create(['name' => 'P2ST Annual', 'code' => 'P2STAL', 'days_per_year' => 14, 'is_paid' => true, 'is_active' => true]);

    Artisan::call('erp:leave-balance-accrual');
    $created = HrmLeaveBalance::count();
    expect($created)->toBeGreaterThan(0);

    Artisan::call('erp:leave-balance-accrual'); // firstOrCreate => no duplicates
    expect(HrmLeaveBalance::count())->toBe($created);
});

test('the activity timeline produces a field-level diff', function () {
    $product = Product::factory()->create(['name' => 'P2ST Original Name']);
    $product->update(['name' => 'P2ST Renamed']);

    $timeline = app(ActivityTimelineService::class)->for($product);
    expect($timeline)->not->toBeEmpty();

    $rename = collect($timeline)->first(fn ($e) => collect($e['changes'])->contains(fn ($c) => $c['field'] === 'name'));
    expect($rename)->not->toBeNull();
    $change = collect($rename['changes'])->firstWhere('field', 'name');
    expect($change['old'])->toBe('P2ST Original Name')->and($change['new'])->toBe('P2ST Renamed');
});
