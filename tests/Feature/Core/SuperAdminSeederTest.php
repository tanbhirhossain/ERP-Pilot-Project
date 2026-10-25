<?php

use Illuminate\Support\Facades\Hash;
use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\CORE\Models\Permission;
use Modules\CORE\Models\User;

test('super admin seeder provisions an omnipotent verified administrator', function () {
    $this->seed(SuperAdminSeeder::class);

    $email = (string) config('core.super_admin.email', 'admin@example.com');
    $admin = User::query()->where('email', $email)->first();

    expect($admin)->not->toBeNull()
        ->and($admin->hasVerifiedEmail())->toBeTrue()
        ->and($admin->hasRole('Super Admin'))->toBeTrue()
        ->and(Hash::check((string) config('core.super_admin.password', 'password'), $admin->password))->toBeTrue();

    // The role holds exactly the wildcard permission, and the wildcard makes
    // every other check pass through spatie's enable_wildcard_permission.
    expect($admin->roles()->first()->permissions->pluck('name')->all())->toBe(['*'])
        ->and($admin->can('users.delete'))->toBeTrue()
        ->and($admin->can('anything.at.all'))->toBeTrue();
});

test('super admin seeder is idempotent', function () {
    $this->seed(SuperAdminSeeder::class);
    $this->seed(SuperAdminSeeder::class);

    expect(User::query()->where('email', 'admin@example.com')->count())->toBe(1)
        ->and(User::query()->where('email', 'admin@example.com')->first()->roles()->count())->toBe(1)
        ->and(Permission::query()->where('name', '*')->count())->toBe(1);
});
