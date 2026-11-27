<?php

namespace Modules\HRM\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Models\Permission;
use Modules\CORE\Models\Role;
use Modules\HRM\Models\Approval\ApprovalChain;
use Modules\HRM\Models\Approval\ApprovalChainStep;

/**
 * Approval engine bootstrap (Phase 2): approver roles + the two chains.
 *
 * Chains (locked scope):
 *   leave:    Supervisor → HR and Admin → DGM HR and Admin → General Manager → CEO
 *   overtime: Supervisor → HR and Admin → DGM HR and Admin → General Manager
 *
 * Idempotent AND admin-respecting: roles/permissions/grants converge with
 * firstOrCreate + givePermissionTo, and chain steps are seeded ONLY when the
 * chain has none — a re-run never overwrites steps an admin customized
 * through the chain admin API. (To reset a chain, delete its steps and reseed.)
 *
 * Assign real users afterwards: supervisors via the employee_supervisors
 * mapping, HR/DGM/GM/CEO by granting them the matching role.
 */
class ApprovalChainSeeder extends Seeder
{
    public const ROLES = ['Supervisor', 'HR Admin', 'DGM HR Admin', 'General Manager', 'CEO'];

    public const PERM_VIEW = 'hrm.approval-chains.view';

    public const PERM_MANAGE = 'hrm.approval-chains.manage';

    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (self::ROLES as $roleName) {
                Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            }

            // Also created by HRMPermissionSeeder; firstOrCreate keeps standalone
            // `db:seed --class=...ApprovalChainSeeder` runs working.
            Permission::firstOrCreate(['name' => self::PERM_VIEW, 'guard_name' => 'web']);
            Permission::firstOrCreate(['name' => self::PERM_MANAGE, 'guard_name' => 'web']);

            // HR owns the chains; the upper tiers may inspect them.
            if ($role = Role::where('name', 'HR Admin')->first()) {
                $role->givePermissionTo([self::PERM_VIEW, self::PERM_MANAGE]);
            }

            foreach (['DGM HR Admin', 'General Manager', 'CEO'] as $roleName) {
                if ($role = Role::where('name', $roleName)->first()) {
                    $role->givePermissionTo([self::PERM_VIEW]);
                }
            }

            // The chain roles exist to work the approvals screens: without the page
            // permissions every approver answers 403 on them and the chain stages can
            // never advance past the applicant.
            $workPerms = ['hrm.leaves.view', 'hrm.leaves.approve', 'hrm.overtimes.view', 'hrm.overtimes.approve'];

            foreach ($workPerms as $perm) {
                Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
            }

            foreach (self::ROLES as $roleName) {
                if ($role = Role::where('name', $roleName)->first()) {
                    $role->givePermissionTo($workPerms);
                }
            }

            // HR generates the OT rows under test from attendance.
            if ($role = Role::where('name', 'HR Admin')->first()) {
                Permission::firstOrCreate(['name' => 'hrm.overtimes.manage', 'guard_name' => 'web']);
                $role->givePermissionTo('hrm.overtimes.manage');
            }

            // The CEO approves leave and reads everything else: every `*.view`
            // permission in the register, whatever module it belongs to. Write
            // permissions stay untouched — viewing payroll is not running it.
            if ($role = Role::where('name', 'CEO')->first()) {
                $viewPerms = Permission::query()
                    ->where('guard_name', 'web')
                    ->where('name', 'like', '%.view')
                    ->pluck('name')->all();
                if ($viewPerms !== []) {
                    $role->givePermissionTo($viewPerms);
                }
            }

            $this->seedChain(ApprovalChain::LEAVE, 'Leave approval chain', [
                ['supervisor', 'Supervisor', 'supervisor', null],
                ['hr', 'HR and Admin', 'role', 'HR Admin'],
                ['dgm', 'DGM HR and Admin', 'role', 'DGM HR Admin'],
                ['gm', 'General Manager', 'role', 'General Manager'],
                ['ceo', 'CEO', 'role', 'CEO'],
            ]);

            $this->seedChain(ApprovalChain::OVERTIME, 'Overtime / Extra-Duty approval chain', [
                ['supervisor', 'Supervisor', 'supervisor', null],
                ['hr', 'HR and Admin', 'role', 'HR Admin'],
                ['dgm', 'DGM HR and Admin', 'role', 'DGM HR Admin'],
                ['gm', 'General Manager', 'role', 'General Manager'],
            ]);
        });

        $this->command?->info('Approval chains ready: leave (5 stages) + overtime (4 stages).');
    }

    /**
     * @param  list<array{0:string,1:string,2:string,3:?string}>  $steps
     */
    private function seedChain(string $key, string $name, array $steps): void
    {
        $chain = ApprovalChain::firstOrCreate(
            ['key' => $key],
            ['name' => $name, 'is_active' => true],
        );

        // Never overwrite: an admin-owned chain keeps its steps on re-seed.
        if (ApprovalChainStep::where('approval_chain_id', $chain->id)->exists()) {
            return;
        }

        foreach ($steps as $position => [$stageKey, $label, $resolver, $roleName]) {
            ApprovalChainStep::create([
                'approval_chain_id' => $chain->id,
                'position' => $position,
                'stage_key' => $stageKey,
                'stage_label' => $label,
                'resolver' => $resolver,
                'role_name' => $roleName,
            ]);
        }
    }
}
