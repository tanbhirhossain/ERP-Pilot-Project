<?php

use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\CORE\Models\User;

test('the design system page renders for an authorised administrator', function () {
    $this->seed(SuperAdminSeeder::class);

    $admin = User::query()
        ->where('email', (string) config('core.super_admin.email', 'admin@example.com'))
        ->firstOrFail();

    $this->actingAs($admin)
        ->get(route('core.ui-kit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('CORE::UiKit', false)
            ->has('demoUsers.data')
            ->has('demoUsers.meta.current_page')
            ->has('roleOptions')
            ->has('filterDefs')
            ->has('environment.php')
            ->has('environment.laravel')
            ->has('environment.modules'));
});

test('the design system demo table is fed with real paginated users', function () {
    $this->seed(SuperAdminSeeder::class);

    User::factory()->count(3)->create();

    $admin = User::query()
        ->where('email', (string) config('core.super_admin.email', 'admin@example.com'))
        ->firstOrFail();

    $response = $this->actingAs($admin)->get(route('core.ui-kit'));

    $response->assertOk();

    $payload = $response->viewData('page')['props']['demoUsers'] ?? null;

    expect($payload)->toBeArray()
        ->and($payload['meta']['total'])->toBe(User::query()->count())
        ->and($payload['data'])->not->toBeEmpty();
});

test('guests are redirected away from the design system page', function () {
    $this->get(route('core.ui-kit'))->assertRedirect('/login');
});

test('a user without the dashboard permission is forbidden', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('core.ui-kit'))
        ->assertForbidden();
});
