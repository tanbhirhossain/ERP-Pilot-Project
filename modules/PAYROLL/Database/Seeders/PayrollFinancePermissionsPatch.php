<?php

use Modules\CORE\Models\Permission;
use Modules\CORE\Models\Role;

// PATCH: Run via tinker or seeder to add finance perms without overwriting existing
// This will be merged into PAYROLLPermissionSeeder in next deploy
$perms = ['payroll.finance.view', 'payroll.finance.post', 'payroll.finance.void', 'payroll.bank-advices.view', 'payroll.bank-advices.manage'];
foreach ($perms as $name) {
    Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
}
foreach (Role::whereIn('name', ['Super Admin', 'Administrator', 'Admin'])->get() as $role) {
    $role->givePermissionTo($perms);
}
