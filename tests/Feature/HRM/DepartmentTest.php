<?php

use Illuminate\Http\Request;
use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Models\Department;
use Modules\HRM\Services\DepartmentService;
use Tests\Feature\HRM\HrmTestHelper;

uses(HrmTestHelper::class);

beforeEach(function () {
    $this->seed(SuperAdminSeeder::class);
});

test('guest cannot access departments index', function () {
    $this->get(route('hrm.departments.index'))->assertRedirect(route('login'));
});

test('can view departments data table', function () {
    Department::factory()->count(3)->create();
    $this->actingAs($this->adminUser())
        ->get(route('hrm.departments.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('HRM::Departments/Index')
            ->has('departments.data', 3)
        );
});

test('can search departments via datatable', function () {
    Department::factory()->create(['name' => 'Human Resources']);
    Department::factory()->create(['name' => 'Finance']);

    $this->actingAs($this->adminUser())
        ->get(route('hrm.departments.index', ['search' => 'Human']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('departments.meta.total', 1));
});

test('can create department', function () {
    $this->actingAs($this->adminUser())
        ->post(route('hrm.departments.store'), [
            'name' => 'Engineering',
            'code' => 'ENG-01',
            'description' => 'Tech team',
            'is_active' => true,
        ])
        ->assertRedirect(route('hrm.departments.index'));

    expect(Department::where('code', 'ENG-01')->exists())->toBeTrue();
});

test('can create sub-department with parent', function () {
    $parent = $this->createDepartment(['name' => 'Parent Dept']);
    $this->actingAs($this->adminUser())
        ->post(route('hrm.departments.store'), [
            'name' => 'Child Dept',
            'code' => 'CHILD-01',
            'parent_id' => $parent->id,
            'is_active' => true,
        ])
        ->assertRedirect();

    $child = Department::where('code', 'CHILD-01')->first();
    expect($child->parent_id)->toBe($parent->id);
    expect($child->parent->id)->toBe($parent->id);
});

test('department validation requires unique code', function () {
    Department::factory()->create(['code' => 'DUP-01']);
    $this->actingAs($this->adminUser())
        ->post(route('hrm.departments.store'), [
            'name' => 'Another',
            'code' => 'DUP-01',
        ])
        ->assertSessionHasErrors('code');
});

test('can update department', function () {
    $dept = $this->createDepartment();
    $this->actingAs($this->adminUser())
        ->put(route('hrm.departments.update', $dept->id), [
            'name' => 'Updated Name',
            'code' => $dept->code,
            'is_active' => false,
        ])
        ->assertRedirect();

    expect($dept->refresh()->name)->toBe('Updated Name');
    expect($dept->is_active)->toBeFalse();
});

test('can delete department', function () {
    $dept = $this->createDepartment();
    $this->actingAs($this->adminUser())
        ->delete(route('hrm.departments.destroy', $dept->id))
        ->assertRedirect();

    expect(Department::find($dept->id))->toBeNull();
});

test('department options endpoint returns id/label', function () {
    $this->createDepartment(['name' => 'HR']);
    $this->actingAs($this->adminUser())
        ->get(route('hrm.api.options', ['type' => 'departments']).'?search=HR')
        ->assertOk()
        ->assertJsonFragment(['label' => 'HR']);
});

test('department service paginates with filters', function () {
    Department::factory()->create(['is_active' => true, 'name' => 'Active Dept']);
    Department::factory()->create(['is_active' => false, 'name' => 'Inactive Dept']);

    $service = app(DepartmentService::class);
    $dt = DataTableQuery::fromRequest(
        new Request(['filters' => ['is_active' => '1']]),
        allowedFilters: ['is_active']
    );
    $paginated = $service->paginate($dt);
    expect($paginated->total())->toBe(1);
});
