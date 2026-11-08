<?php

use Modules\CORE\Models\Role;
use Modules\HRM\Database\Seeders\HRMPermissionSeeder;

test('HRM permission seeder grants overtime approval to the core Administrator role', function () {
    $administrator = Role::query()->create([
        'name' => 'Administrator',
        'guard_name' => 'web',
    ]);

    $this->seed(HRMPermissionSeeder::class);

    expect($administrator->fresh()->hasPermissionTo('hrm.overtimes.approve'))->toBeTrue();
});
