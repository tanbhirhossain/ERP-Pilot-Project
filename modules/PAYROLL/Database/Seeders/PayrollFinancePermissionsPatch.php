<?php

// PATCH: Run via tinker or seeder to add finance perms without overwriting existing
// This will be merged into PAYROLLPermissionSeeder in next deploy
$perms = ['payroll.finance.view','payroll.finance.post','payroll.finance.void','payroll.bank-advices.view','payroll.bank-advices.manage'];
foreach ($perms as $name) {
    \Modules\CORE\Models\Permission::firstOrCreate(['name'=>$name,'guard_name'=>'web']);
}
foreach (\Modules\CORE\Models\Role::whereIn('name',['Super Admin','Administrator','Admin'])->get() as $role) {
    $role->givePermissionTo($perms);
}
