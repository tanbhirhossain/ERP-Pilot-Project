<?php

/**
 * Maker-checker (segregation of duties) guards for Purchase + Accounting.
 *
 * The two-door rule: makers draft, checkers decide. These tests pin the three
 * properties the rule needs:
 *
 *  1. The seed converges — re-running it changes nothing (safe on dev AND prod).
 *  2. The split holds — makers lack every decision grant, checkers are a strict
 *     superset of their makers, and no user keeps both halves of a pair.
 *  3. The split is enforced — the same Spatie PermissionMiddleware the routes use
 *     blocks makers from confirm/post and lets checkers through.
 */

use Database\Seeders\DatabaseSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Database\Seeders\MakerCheckerSeeder;
use Modules\CORE\Models\Permission;
use Modules\CORE\Models\Role;
use Modules\CORE\Models\User;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);

    // The seed ran inside this process; drop Spatie's cached permissions so the
    // middleware below resolves against the freshly seeded grants.
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

test('maker-checker seed is idempotent', function (): void {
    $snapshot = fn (): array => [
        'roles' => Role::whereIn('name', [
            'Purchase Maker', 'Purchase Checker', 'Accounts Maker', 'Accounts Checker',
        ])->count(),
        'users' => User::whereIn('email', [
            'purchase.maker@example.com', 'purchase.checker@example.com',
            'accounts.maker@example.com', 'accounts.checker@example.com',
        ])->count(),
        'permissions' => Permission::count(),
        'role_grants' => DB::table('role_has_permissions')->count(),
        'user_roles' => DB::table('model_has_roles')->count(),
    ];

    $before = $snapshot();

    expect($before['roles'])->toBe(4, 'four maker-checker roles seeded');
    expect($before['users'])->toBe(4, 'four demo users seeded');

    $this->seed(MakerCheckerSeeder::class);

    expect($snapshot())->toBe($before, 're-running the seed changes nothing');
});

test('makers lack every decision permission and checkers hold their stream decisions', function (): void {
    foreach (MakerCheckerSeeder::CONFLICT_PAIRS as [$makerName, $checkerName]) {
        $maker = Role::findByName($makerName);
        $checker = Role::findByName($checkerName);

        $stream = str_starts_with($makerName, 'Purchase') ? 'purchase.' : 'accounts.';

        foreach (MakerCheckerSeeder::DECISION_PERMISSIONS as $permission) {
            expect($maker->hasPermissionTo($permission))
                ->toBeFalse("{$makerName} must not hold {$permission}");

            if (str_starts_with($permission, $stream)) {
                expect($checker->hasPermissionTo($permission))
                    ->toBeTrue("{$checkerName} must hold {$permission}");
            }
        }

        // A checker reviews the same drafts, so it keeps every grant its maker has.
        foreach ($maker->permissions as $granted) {
            expect($checker->hasPermissionTo($granted->name))
                ->toBeTrue("{$checkerName} must keep maker grant {$granted->name}");
        }
    }
});

test('one user cannot keep both halves of a same-stream pair', function (): void {
    $maker = User::where('email', 'purchase.maker@example.com')->firstOrFail();

    // Simulate a manual role assignment (or an older seed) putting one person on
    // both sides of the door.
    $maker->assignRole('Purchase Checker');
    expect($maker->fresh()->hasAllRoles(['Purchase Maker', 'Purchase Checker']))->toBeTrue();

    $this->seed(MakerCheckerSeeder::class);

    // Converges back to segregated: maker half kept, checker half stripped.
    expect($maker->fresh()->hasRole('Purchase Maker'))->toBeTrue();
    expect($maker->fresh()->hasRole('Purchase Checker'))->toBeFalse();
});

test('permission middleware blocks makers from confirm-post and lets checkers through', function (): void {
    $middleware = new PermissionMiddleware;
    $next = fn () => 'ok';

    $attempt = function (string $email, string $permission) use ($middleware, $next) {
        $this->actingAs(User::where('email', $email)->firstOrFail());

        return $middleware->handle(Request::create('/probe', 'POST'), $next, $permission);
    };

    // Control: a maker passes its OWN grants, so a 403 below means the split — not
    // a broken user, role, or permission cache.
    expect($attempt('purchase.maker@example.com', 'purchase.orders.manage'))->toBe('ok');
    expect($attempt('accounts.maker@example.com', 'accounts.journals.create'))->toBe('ok');

    // Second door: makers are stopped, checkers pass.
    foreach ([
        ['purchase.maker@example.com', 'purchase.orders.confirm'],
        ['purchase.maker@example.com', 'purchase.bills.post'],
        ['accounts.maker@example.com', 'accounts.journals.post'],
    ] as [$email, $permission]) {
        expect(fn () => $attempt($email, $permission), "{$email} blocked from {$permission}")
            ->toThrow(UnauthorizedException::class);
    }

    expect($attempt('purchase.checker@example.com', 'purchase.orders.confirm'))->toBe('ok');
    expect($attempt('purchase.checker@example.com', 'purchase.bills.post'))->toBe('ok');
    expect($attempt('accounts.checker@example.com', 'accounts.journals.post'))->toBe('ok');
});

test('demo users authenticate with the seeded password', function (): void {
    $password = env(MakerCheckerSeeder::MAKER_PASSWORD_ENV, MakerCheckerSeeder::DEFAULT_PASSWORD);

    foreach ([
        'purchase.maker@example.com',
        'purchase.checker@example.com',
        'accounts.maker@example.com',
        'accounts.checker@example.com',
    ] as $email) {
        expect(Auth::attempt(['email' => $email, 'password' => $password]))
            ->toBeTrue("{$email} logs in");
        Auth::logout();
    }
});
