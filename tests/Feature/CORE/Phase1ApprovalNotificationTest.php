<?php

/**
 * Phase 1 — F2 notifications + F3 approval matrix / maker-checker.
 *
 * These exercise the real services against a migrated database: the gate must
 * refuse an over-threshold document and raise a pending request, segregation of
 * duties must stop the raiser approving their own request, a second authorised
 * user must be able to decide, and the gate must then pass. Notifications must
 * be scoped to the recipient.
 *
 * Helper names are prefixed `p1an*` — Pest loads every Feature file into one process.
 */

use Modules\CORE\Models\ApprovalPolicy;
use Modules\CORE\Models\ApprovalRequest;
use Modules\CORE\Models\User;
use Modules\CORE\Services\ApprovalService;
use Modules\CORE\Services\NotificationService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function p1anUser(string $permission, int $salt): User
{
    Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    $role = Role::create(['name' => "P1AN Role {$salt}", 'guard_name' => 'web']);
    $role->givePermissionTo($permission);

    $user = User::create([
        'name' => "P1AN User {$salt}",
        'email' => "p1an{$salt}@example.test",
        'password' => bcrypt('secret-password'),
        'email_verified_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

function p1anPolicy(bool $active = true): ApprovalPolicy
{
    // The approver permission always exists in production (it is seeded); the
    // notification fan-out queries its holders, so mirror that here.
    Permission::firstOrCreate(['name' => 'purchase.bills.post', 'guard_name' => 'web']);

    return ApprovalPolicy::create([
        'name' => 'P1AN vendor bills',
        'document_type' => 'vendor_bill',
        'min_amount' => 100000,
        'approver_permission' => 'purchase.bills.post',
        'require_segregation' => true,
        'priority' => 10,
        'is_active' => $active,
    ]);
}

test('an inactive policy is a no-op — the gate never blocks', function () {
    p1anPolicy(false);
    $svc = app(ApprovalService::class);

    // No exception, no request raised.
    $svc->assertAllowed('vendor_bill', 1, 500000, 'PB-1');
    expect(ApprovalRequest::count())->toBe(0);
});

test('a below-threshold document passes without a request', function () {
    p1anPolicy(true);
    $svc = app(ApprovalService::class);

    $svc->assertAllowed('vendor_bill', 1, 50000, 'PB-1');
    expect(ApprovalRequest::count())->toBe(0);
});

test('an over-threshold document is refused and a pending request is raised', function () {
    p1anPolicy(true);
    $raiser = p1anUser('purchase.bills.create', 1);
    $this->actingAs($raiser);

    $svc = app(ApprovalService::class);

    try {
        $svc->assertAllowed('vendor_bill', 42, 150000, 'PB-42');
        $this->fail('the gate should have refused an over-threshold document');
    } catch (DomainException $e) {
        expect($e->getMessage())->toContain('approval');
    }

    $request = ApprovalRequest::where('document_type', 'vendor_bill')->where('document_id', 42)->first();
    expect($request)->not->toBeNull()
        ->and($request->status)->toBe('pending')
        ->and($request->requested_by)->toBe($raiser->id)
        ->and((float) $request->amount)->toBe(150000.0);

    // Calling again reuses the same pending request rather than duplicating it.
    try {
        $svc->assertAllowed('vendor_bill', 42, 150000, 'PB-42');
    } catch (DomainException) {
    }
    expect(ApprovalRequest::where('document_id', 42)->count())->toBe(1);
});

test('segregation of duties stops the raiser approving their own request', function () {
    p1anPolicy(true);
    // The raiser also holds the approver permission — SoD must still stop them.
    $raiser = p1anUser('purchase.bills.post', 2);
    $this->actingAs($raiser);

    $svc = app(ApprovalService::class);
    try {
        $svc->assertAllowed('vendor_bill', 7, 200000, 'PB-7');
    } catch (DomainException) {
    }
    $request = ApprovalRequest::where('document_id', 7)->firstOrFail();

    $this->expectException(DomainException::class);
    $svc->approve($request, $raiser);
});

test('a second authorised user approves and the gate then passes', function () {
    p1anPolicy(true);
    $raiser = p1anUser('purchase.bills.create', 3);
    $approver = p1anUser('purchase.bills.post', 4);
    $this->actingAs($raiser);

    $svc = app(ApprovalService::class);
    try {
        $svc->assertAllowed('vendor_bill', 9, 200000, 'PB-9');
    } catch (DomainException) {
    }
    $request = ApprovalRequest::where('document_id', 9)->firstOrFail();

    $approved = $svc->approve($request, $approver, 'Looks correct');
    expect($approved->status)->toBe('approved')
        ->and($approved->decided_by)->toBe($approver->id)
        ->and($approved->decision_note)->toBe('Looks correct');

    // The gate now passes for the same document + amount.
    $svc->assertAllowed('vendor_bill', 9, 200000, 'PB-9');
    expect(true)->toBeTrue();
});

test('a user without the approver permission cannot decide', function () {
    p1anPolicy(true);
    $raiser = p1anUser('purchase.bills.create', 5);
    $bystander = p1anUser('dashboard.view', 6);
    $this->actingAs($raiser);

    $svc = app(ApprovalService::class);
    try {
        $svc->assertAllowed('vendor_bill', 11, 200000, 'PB-11');
    } catch (DomainException) {
    }
    $request = ApprovalRequest::where('document_id', 11)->firstOrFail();

    $this->expectException(DomainException::class);
    $svc->approve($request, $bystander);
});

test('rejection requires a note and closes the request', function () {
    p1anPolicy(true);
    $raiser = p1anUser('purchase.bills.create', 7);
    $approver = p1anUser('purchase.bills.post', 8);
    $this->actingAs($raiser);

    $svc = app(ApprovalService::class);
    try {
        $svc->assertAllowed('vendor_bill', 13, 200000, 'PB-13');
    } catch (DomainException) {
    }
    $request = ApprovalRequest::where('document_id', 13)->firstOrFail();

    $rejected = $svc->reject($request, $approver, 'Missing supporting documents');
    expect($rejected->status)->toBe('rejected')
        ->and($rejected->decision_note)->toBe('Missing supporting documents');

    // A rejected request does not let the document through.
    $this->expectException(DomainException::class);
    $svc->assertAllowed('vendor_bill', 13, 200000, 'PB-13');
});

test('notifications are scoped to the recipient and mark read', function () {
    $alice = p1anUser('dashboard.view', 9);
    $bob = p1anUser('dashboard.view', 10);

    $svc = app(NotificationService::class);
    $count = $svc->notifyUsers([$alice->id], 'Hello Alice', 'Body', '/core/notifications', 'info');
    expect($count)->toBe(1)
        ->and($alice->fresh()->notifications()->count())->toBe(1)
        ->and($bob->fresh()->notifications()->count())->toBe(0);

    $notification = $alice->notifications()->first();
    expect($notification->data['title'])->toBe('Hello Alice');

    $notification->markAsRead();
    expect($alice->fresh()->unreadNotifications()->count())->toBe(0);
});
