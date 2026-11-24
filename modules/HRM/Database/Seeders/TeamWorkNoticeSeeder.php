<?php

namespace Modules\HRM\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Models\Permission;
use Modules\CORE\Models\Role;

/**
 * Phase-3 team domains: work-assignment + notice permissions.
 *
 * hrm.tasks.manage / hrm.notices.manage gate posting and managing; reading
 * your own tasks/notices needs no permission (scope rules in the controllers
 * decide visibility). Granted to the five desk roles; run ApprovalChainSeeder
 * first (DatabaseSeeder orders it) so the roles exist.
 *
 * Idempotent: firstOrCreate + additive givePermissionTo.
 */
class TeamWorkNoticeSeeder extends Seeder
{
    public const PERM_TASKS = 'hrm.tasks.manage';

    public const PERM_NOTICES = 'hrm.notices.manage';

    public function run(): void
    {
        DB::transaction(function (): void {
            Permission::firstOrCreate(['name' => self::PERM_TASKS, 'guard_name' => 'web']);
            Permission::firstOrCreate(['name' => self::PERM_NOTICES, 'guard_name' => 'web']);

            foreach (['Supervisor', 'HR Admin', 'DGM HR Admin', 'General Manager', 'CEO'] as $roleName) {
                if ($role = Role::where('name', $roleName)->first()) {
                    $role->givePermissionTo([self::PERM_TASKS, self::PERM_NOTICES]);
                }
            }
        });

        $this->command?->info('Team work/notice permissions ready.');
    }
}
