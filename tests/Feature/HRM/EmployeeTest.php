<?php

use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Employee\EmployeePolicy;
use Modules\HRM\Models\Employee\EmployeeProfile;
use Modules\HRM\Models\Employee\EmployeeSkill;
use Modules\HRM\Services\EmployeeService;
use Tests\Feature\HRM\HrmTestHelper;

uses(HrmTestHelper::class);

beforeEach(function () {
    $this->seed(SuperAdminSeeder::class);
});

test('can view employees data table', function () {
    $this->createEmployee();
    $this->createEmployee();
    $this->actingAs($this->adminUser())
        ->get(route('hrm.employees.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('HRM::Employees/Index')
            ->has('employees.data', 2)
        );
});

test('can create employee with auto-generated ID', function () {
    $dept = $this->createDepartment();
    $desig = $this->createDesignation($dept);
    $response = $this->actingAs($this->adminUser())
        ->post(route('hrm.employees.store'), [
            'first_name' => 'Karim',
            'last_name' => 'Uddin',
            'email' => 'karim@test.com',
            'phone' => '01711111111',
            'department_id' => $dept->id,
            'designation_id' => $desig->id,
            'employment_type' => 'Permanent',
            'status' => 'Active',
            'joining_date' => '2024-01-01',
            'gender' => 'Male',
            'policy' => [
                'shift_type' => 'Fixed',
                'grace_time_in' => 10,
                'grace_time_out' => 10,
            ],
            'salary' => [
                'basic_salary' => 30000,
                'gross_salary' => 45000,
            ],
        ])
        ->assertRedirect(route('hrm.employees.index'));

    expect(Employee::where('email', 'karim@test.com')->exists())->toBeTrue();
    $emp = Employee::where('email', 'karim@test.com')->first();
    expect($emp->employee_id)->toStartWith('EMP-');
    expect($emp->full_name)->toBe('Karim Uddin');
    // Check nested creations
    expect(EmployeePolicy::where('employee_id', $emp->employee_id)->exists())->toBeTrue();
    expect(EmployeeProfile::where('employee_id', $emp->employee_id)->exists())->toBeFalse(); // empty profile not created
});

test('can create employee with profile', function () {
    $this->actingAs($this->adminUser())
        ->post(route('hrm.employees.store'), [
            'first_name' => 'Rina',
            'employment_type' => 'Permanent',
            'status' => 'Active',
            'profile' => [
                'father_name' => 'Abdul',
                'mother_name' => 'Salma',
                'present_address' => 'Dhaka',
            ],
            'policy' => [
                'shift_type' => 'Flexible',
                'grace_time_in' => 15,
            ],
        ])
        ->assertRedirect();

    $emp = Employee::where('first_name', 'Rina')->first();
    expect($emp->profile)->not->toBeNull();
    expect($emp->profile->father_name)->toBe('Abdul');
    expect($emp->policy->shift_type)->toBe('Flexible');
});

test('blank skill placeholders are ignored while named skills are saved', function () {
    $this->actingAs($this->adminUser())
        ->post(route('hrm.employees.store'), [
            'first_name' => 'Skill Test',
            'employment_type' => 'Permanent',
            'status' => 'Active',
            'skills' => [
                ['skill_name' => '', 'proficiency' => 'Intermediate', 'years_of_experience' => null, 'description' => ''],
                ['skill_name' => 'Payroll', 'proficiency' => 'Advanced', 'years_of_experience' => 4, 'description' => 'Payroll processing'],
            ],
        ])
        ->assertRedirect(route('hrm.employees.index'));

    $employee = Employee::where('first_name', 'Skill Test')->firstOrFail();
    expect(EmployeeSkill::where('employee_id', $employee->employee_id)->count())->toBe(1)
        ->and(EmployeeSkill::where('employee_id', $employee->employee_id)->value('skill_name'))->toBe('Payroll');
});

test('employee options endpoint supports live name and employee ID search', function () {
    $employee = $this->createEmployee([
        'employee_id' => 'LIVE-SEARCH-01',
        'first_name' => 'LiveSearch',
        'full_name' => 'LiveSearch Test Employee',
    ]);

    $this->actingAs($this->adminUser())
        ->get(route('hrm.api.options', ['type' => 'employees', 'search' => 'LIVE-SEARCH', 'limit' => 50]))
        ->assertOk()
        ->assertJsonPath('items.0.value', $employee->employee_id)
        ->assertJsonPath('items.0.label', 'LiveSearch Test Employee (LIVE-SEARCH-01)');

    $this->get(route('hrm.api.options', ['type' => 'employees', 'search' => 'LiveSearch Test', 'limit' => 50]))
        ->assertOk()
        ->assertJsonPath('items.0.value', $employee->employee_id)
        ->assertJsonPath('items.0.label', 'LiveSearch Test Employee (LIVE-SEARCH-01)');
});

test('employee validation requires first_name', function () {
    $this->actingAs($this->adminUser())
        ->post(route('hrm.employees.store'), [
            'first_name' => '',
            'employment_type' => 'Permanent',
            'status' => 'Active',
        ])
        ->assertSessionHasErrors('first_name');
});

test('auto employee_id increments correctly', function () {
    $emp1 = $this->createEmployee(['employee_id' => 'EMP-0001']);
    $emp2 = Employee::factory()->create(['employee_id' => 'EMP-0002']);
    $service = app(EmployeeService::class);
    $next = $service->create(['first_name' => 'Next', 'employment_type' => 'Permanent', 'status' => 'Active']);
    expect($next->employee_id)->toBe('EMP-0003');
});

test('can update employee and policy', function () {
    $emp = $this->createEmployee(['first_name' => 'OldName']);
    $dept2 = $this->createDepartment(['name' => 'New Dept']);
    $this->actingAs($this->adminUser())
        ->put(route('hrm.employees.update', $emp->id), [
            'first_name' => 'NewName',
            'last_name' => 'Last',
            'employment_type' => 'Contract',
            'status' => 'Active',
            'department_id' => $dept2->id,
            'policy' => ['shift_type' => 'Custom', 'grace_time_in' => 5],
        ])
        ->assertRedirect();

    expect($emp->refresh()->first_name)->toBe('NewName');
    expect($emp->department_id)->toBe($dept2->id);
    expect($emp->policy->refresh()->shift_type)->toBe('Custom');
});

test('can view single employee show page', function () {
    $emp = $this->createEmployee();
    $this->actingAs($this->adminUser())
        ->get(route('hrm.employees.show', $emp->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('HRM::Employees/Show'));
});

test('can delete employee', function () {
    $emp = $this->createEmployee();
    $this->actingAs($this->adminUser())
        ->delete(route('hrm.employees.destroy', $emp->id))
        ->assertRedirect();

    expect(Employee::find($emp->id))->toBeNull();
});

test('employee service options returns label with ID', function () {
    $emp = $this->createEmployee(['first_name' => 'SearchMe', 'full_name' => 'SearchMe Person']);
    $service = app(EmployeeService::class);
    $options = $service->options('SearchMe', 10);
    expect($options->count())->toBe(1);
    expect($options->first()['label'])->toContain('SearchMe');
    expect($options->first()['value'])->toBe($emp->employee_id);
});

test('employee soft deletes', function () {
    $emp = $this->createEmployee();
    $emp->delete();
    expect(Employee::find($emp->id))->toBeNull();
    expect(Employee::withTrashed()->find($emp->id))->not->toBeNull();
});
