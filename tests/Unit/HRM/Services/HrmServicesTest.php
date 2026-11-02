<?php

use Modules\HRM\Models\BiometricDevice;
use Modules\HRM\Models\Department;
use Modules\HRM\Models\Designation;
use Modules\HRM\Models\Organization;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Services\DepartmentService;
use Modules\HRM\Services\DesignationService;
use Modules\HRM\Services\BiometricDeviceService;
use Modules\HRM\Services\EmployeeService;
use Modules\HRM\Services\OrganizationService;
use Modules\HRM\Services\RosterShiftService;

test('OrganizationService get returns null when no org', function () {
    $svc=app(OrganizationService::class);
    expect($svc->get())->toBeNull();
    $org=$svc->upsert(['name'=>'Test Org']);
    expect($svc->get()->id)->toBe($org->id);
});

test('DepartmentService CRUD via service layer', function () {
    $svc=app(DepartmentService::class);
    $dept=$svc->create(['name'=>'HR','code'=>'HR-01','is_active'=>true]);
    expect($dept->name)->toBe('HR');
    $found=$svc->findOrFail($dept->id);
    expect($found->id)->toBe($dept->id);
    $updated=$svc->update($dept,['name'=>'Human Resources','code'=>'HR-01']);
    expect($updated->name)->toBe('Human Resources');
    $opts=$svc->options('Human');
    expect($opts->count())->toBe(1);
    $svc->delete($updated);
    expect(Department::find($dept->id))->toBeNull();
});

test('DesignationService CRUD', function () {
    $dept=Department::factory()->create();
    $svc=app(DesignationService::class);
    $desig=$svc->create(['name'=>'Engineer','code'=>'ENG-01','department_id'=>$dept->id,'is_active'=>true]);
    expect($desig->department_id)->toBe($dept->id);
    $svc->update($desig,['name'=>'Senior Engineer','code'=>'ENG-01','is_active'=>true]);
    expect($desig->refresh()->name)->toBe('Senior Engineer');
    $svc->delete($desig);
    expect(Designation::find($desig->id))->toBeNull();
});

test('BiometricDeviceService testConnection fails for unreachable host', function () {
    $svc=app(BiometricDeviceService::class);
    $device=BiometricDevice::factory()->create(['ip_address'=>'127.0.0.1','port'=>59999]);
    $result=$svc->testConnection($device);
    expect($result['ok'])->toBeFalse();
    expect($result['message'])->toContain('Failed');
});

test('BiometricDeviceService CRUD with auto sync flag', function () {
    $svc=app(BiometricDeviceService::class);
    $device=$svc->create([
        'name'=>'Gate','ip_address'=>'10.0.0.1','port'=>4370,'status'=>'Active',
        'is_enabled'=>true,'auto_sync_enabled'=>true,'sync_interval_minutes'=>10
    ]);
    expect($device->auto_sync_enabled)->toBeTrue();
    $svc->update($device,['name'=>'Gate Updated','ip_address'=>'10.0.0.1','port'=>4370,'status'=>'Inactive','is_enabled'=>false]);
    expect($device->refresh()->name)->toBe('Gate Updated');
    $svc->delete($device);
    expect(BiometricDevice::find($device->id))->toBeNull();
});

test('EmployeeService options uses employee_id as value', function () {
    $dept=Department::factory()->create();
    $desig=Designation::factory()->create(['department_id'=>$dept->id]);
    Employee::factory()->create(['department_id'=>$dept->id,'designation_id'=>$desig->id,'first_name'=>'Searchable']);
    $svc=app(EmployeeService::class);
    $opts=$svc->options('Searchable');
    expect($opts->first()['value'])->toStartWith('EMP-');
});

test('RosterShiftService paginate respects filters', function () {
    $svc=app(RosterShiftService::class);
    $svc->create(['shift_name'=>'Day','start_time'=>'09:00','end_time'=>'17:00','crosses_midnight'=>false,'max_duration_hours'=>8]);
    $svc->create(['shift_name'=>'Night','start_time'=>'22:00','end_time'=>'06:00','crosses_midnight'=>true,'max_duration_hours'=>8]);
    $dt=\Modules\CORE\Support\DataTableQuery::fromRequest(new \Illuminate\Http\Request(['filters'=>['crosses_midnight'=>'1']]),allowedFilters:['crosses_midnight']);
    $result=$svc->paginate($dt);
    expect($result->total())->toBe(1);
    expect($result->items()[0]->shift_name)->toBe('Night');
});

test('OrganizationRepository upsert handles concurrent single row', function () {
    $repo=app(\Modules\HRM\Interfaces\OrganizationRepositoryInterface::class);
    $o1=$repo->upsert(['name'=>'First','email'=>'first@test.com']);
    $o2=$repo->upsert(['name'=>'Second','email'=>'second@test.com']);
    expect($o1->id)->toBe($o2->id);
    expect(Organization::count())->toBe(1);
    expect(Organization::first()->name)->toBe('Second');
});

test('DepartmentRepository searchColumns and sortable', function () {
    $repo=app(\Modules\HRM\Interfaces\DepartmentRepositoryInterface::class);
    Department::factory()->create(['name'=>'Alpha','code'=>'A01']);
    Department::factory()->create(['name'=>'Beta','code'=>'B01']);
    $dt=\Modules\CORE\Support\DataTableQuery::fromRequest(new \Illuminate\Http\Request(['search'=>'Alpha']));
    $result=$repo->paginate($dt);
    expect($result->total())->toBe(1);
    expect($result->items()[0]->name)->toBe('Alpha');
});

