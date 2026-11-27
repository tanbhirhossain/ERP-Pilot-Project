<?php

/**
 * Assign Supervisor, end to end through the web form.
 *
 * The form posts a `supervisor_kind` discriminator that has no column — the
 * kind is derived from which id is set — so the write path must strip it.
 * Before the fix every assignment 500'd on the INSERT with "no column named
 * supervisor_kind".
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\CORE\Models\Permission;
use Modules\CORE\Models\User;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Permission::firstOrCreate(['name' => 'hrm.employees.view', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'hrm.employees.manage', 'guard_name' => 'web']);

    $mkUser = function (string $email): User {
        return User::create([
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('secret123'),
            'email_verified_at' => now(),
        ]);
    };

    $this->hr = $mkUser('sv-hr@example.com');
    $this->hr->givePermissionTo(['hrm.employees.view', 'hrm.employees.manage']);
    $this->boss = $mkUser('sv-boss@example.com');

    foreach (['SV-A1', 'SV-B1'] as $code) {
        DB::table('employees')->insert([
            'employee_id' => $code,
            'first_name' => $code,
            'status' => 'Active',
            'joining_date' => '2024-06-01',
            'is_active' => true,
        ]);
    }
});

test('assigning a user supervisor stores the mapping', function (): void {
    $this->actingAs($this->hr)->post('/hrm/supervisors', [
        'employee_id' => 'SV-A1',
        'supervisor_kind' => 'user',
        'supervisor_id' => $this->boss->id,
        'supervisor_type' => 'Supervisor',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $row = DB::table('employee_supervisors')->where('employee_id', 'SV-A1')->firstOrFail();
    expect((int) $row->supervisor_id)->toBe($this->boss->id)
        ->and($row->supervisor_employee_id)->toBeNull();
});

test('assigning an employee supervisor stores the mapping', function (): void {
    $this->actingAs($this->hr)->post('/hrm/supervisors', [
        'employee_id' => 'SV-A1',
        'supervisor_kind' => 'employee',
        'supervisor_employee_id' => 'SV-B1',
        'supervisor_type' => 'Reporting Manager',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $row = DB::table('employee_supervisors')->where('employee_id', 'SV-A1')->firstOrFail();
    expect($row->supervisor_employee_id)->toBe('SV-B1')
        ->and($row->supervisor_id)->toBeNull();
});

test('switching kind on edit swaps the id columns', function (): void {
    $this->actingAs($this->hr)->post('/hrm/supervisors', [
        'employee_id' => 'SV-A1',
        'supervisor_kind' => 'user',
        'supervisor_id' => $this->boss->id,
        'supervisor_type' => 'Supervisor',
    ])->assertSessionHasNoErrors();
    $id = DB::table('employee_supervisors')->where('employee_id', 'SV-A1')->value('id');

    $this->actingAs($this->hr)->put("/hrm/supervisors/{$id}", [
        'employee_id' => 'SV-A1',
        'supervisor_kind' => 'employee',
        'supervisor_employee_id' => 'SV-B1',
        'supervisor_type' => 'Supervisor',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $row = DB::table('employee_supervisors')->where('id', $id)->firstOrFail();
    expect($row->supervisor_employee_id)->toBe('SV-B1')
        ->and($row->supervisor_id)->toBeNull();
});

test('an employee cannot supervise themselves', function (): void {
    $this->actingAs($this->hr)->post('/hrm/supervisors', [
        'employee_id' => 'SV-A1',
        'supervisor_kind' => 'employee',
        'supervisor_employee_id' => 'SV-A1',
        'supervisor_type' => 'Supervisor',
    ])->assertRedirect()->assertSessionHasErrors('supervisor_employee_id');

    expect(DB::table('employee_supervisors')->where('employee_id', 'SV-A1')->count())->toBe(0);
});
