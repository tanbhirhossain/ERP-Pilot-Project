<?php

use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\HRM\Models\Organization;
use Modules\HRM\Services\OrganizationService;
use Tests\Feature\HRM\HrmTestHelper;

uses(HrmTestHelper::class);

beforeEach(function () {
    $this->seed(SuperAdminSeeder::class);
});

test('guest cannot view organization page', function () {
    $this->get(route('hrm.organizations.index'))->assertRedirect(route('login'));
});

test('authenticated user can view organization page', function () {
    $this->actingAs($this->adminUser())
        ->get(route('hrm.organizations.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('HRM::Organization/Index'));
});

test('can create organization via single-row upsert', function () {
    $this->actingAs($this->adminUser())
        ->post(route('hrm.organizations.store'), [
            'name' => 'Acme Corp Ltd',
            'email' => 'info@acme.test',
            'phone' => '01700000000',
            'address' => 'Dhaka, Bangladesh',
            'website' => 'https://acme.test',
        ])
        ->assertRedirect(route('hrm.organizations.index'))
        ->assertSessionHas('success');

    expect(Organization::count())->toBe(1);
    expect(Organization::first()->name)->toBe('Acme Corp Ltd');
});

test('organization validation requires name', function () {
    $this->actingAs($this->adminUser())
        ->post(route('hrm.organizations.store'), ['name' => ''])
        ->assertSessionHasErrors('name');
});

test('can update existing organization', function () {
    $org = Organization::factory()->create(['name' => 'Old Name']);
    $this->actingAs($this->adminUser())
        ->put(route('hrm.organizations.update'), [
            'name' => 'New Name',
            'email' => 'new@test.com',
        ])
        ->assertRedirect(route('hrm.organizations.index'));

    expect($org->refresh()->name)->toBe('New Name');
    // Ensure still single row
    expect(Organization::count())->toBe(1);
});

test('organization service upsert creates or updates single row', function () {
    $service = app(OrganizationService::class);
    $org1 = $service->upsert(['name' => 'First']);
    expect(Organization::count())->toBe(1);
    $org2 = $service->upsert(['name' => 'Second']);
    expect(Organization::count())->toBe(1);
    expect($org2->id)->toBe($org1->id);
    expect($org2->name)->toBe('Second');
});
