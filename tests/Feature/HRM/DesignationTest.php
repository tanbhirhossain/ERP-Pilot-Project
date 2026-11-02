<?php

use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\HRM\Models\Designation;
use Tests\Feature\HRM\HrmTestHelper;

uses(HrmTestHelper::class);

beforeEach(function () {
    $this->seed(SuperAdminSeeder::class);
});

test('can view designations index', function () {
    $this->actingAs($this->adminUser())
        ->get(route('hrm.designations.index'))
        ->assertOk()
        ->assertInertia(fn($page) => $page->component('HRM::Designations/Index'));
});

test('can create designation with department', function () {
    $dept = $this->createDepartment();
    $this->actingAs($this->adminUser())
        ->post(route('hrm.designations.store'), [
            'name' => 'Senior Engineer',
            'code' => 'SE-01',
            'department_id' => $dept->id,
            'grade' => 'G-08',
            'level' => 5,
            'is_active' => true,
        ])
        ->assertRedirect(route('hrm.designations.index'));

    $desig = Designation::where('code','SE-01')->first();
    expect($desig)->not->toBeNull();
    expect($desig->department_id)->toBe($dept->id);
    expect($desig->grade)->toBe('G-08');
});

test('designation requires name', function () {
    $this->actingAs($this->adminUser())
        ->post(route('hrm.designations.store'), ['name' => ''])
        ->assertSessionHasErrors('name');
});

test('designation unique code validation', function () {
    Designation::factory()->create(['code' => 'DUP-DES']);
    $this->actingAs($this->adminUser())
        ->post(route('hrm.designations.store'), [
            'name' => 'Another',
            'code' => 'DUP-DES',
        ])
        ->assertSessionHasErrors('code');
});

test('can update designation', function () {
    $desig = Designation::factory()->create();
    $this->actingAs($this->adminUser())
        ->put(route('hrm.designations.update', $desig->id), [
            'name' => 'Lead Engineer',
            'code' => $desig->code,
            'grade' => 'G-09',
            'level' => 9,
            'is_active' => true,
        ])
        ->assertRedirect();

    expect($desig->refresh()->name)->toBe('Lead Engineer');
});

test('can delete designation', function () {
    $desig = Designation::factory()->create();
    $this->actingAs($this->adminUser())
        ->delete(route('hrm.designations.destroy', $desig->id))
        ->assertRedirect();

    expect(Designation::find($desig->id))->toBeNull();
});

test('designation service returns options filtered by search', function () {
    Designation::factory()->create(['name' => 'HR Manager']);
    Designation::factory()->create(['name' => 'Finance Officer']);

    $service = app(\Modules\HRM\Services\DesignationService::class);
    $options = $service->options('HR', 10);
    expect($options->count())->toBe(1);
    expect($options->first()['label'])->toContain('HR Manager');
});

test('designation belongs to department relation', function () {
    $dept = $this->createDepartment(['name' => 'IT']);
    $desig = $this->createDesignation($dept);
    expect($desig->department->id)->toBe($dept->id);
    expect($dept->designations->contains($desig))->toBeTrue();
});
