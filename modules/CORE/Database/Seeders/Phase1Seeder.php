<?php

namespace Modules\CORE\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\CORE\Models\ApprovalPolicy;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Phase 1 — Enterprise Core provisioning.
 *
 * Seeds the new permissions and a starter approval matrix. The policies are
 * INACTIVE by default: the gate is a no-op until an administrator activates one,
 * so installing Phase 1 never changes the behaviour of an existing deployment
 * or breaks its tests. The operator opts in from Approvals → Policies.
 */
class Phase1Seeder extends Seeder
{
    public function run(): void
    {
        $perms = [
            'approvals.view',
            'approvals.manage',
            'imports.manage',
            'accounts.opening.manage',
        ];

        foreach ($perms as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // Super Admin holds everything (wildcard already covers it, but be explicit).
        if ($role = Role::where('name', 'Super Admin')->first()) {
            $role->givePermissionTo($perms);
        }

        // Administrator runs the business: approvals + imports + take-on.
        if ($role = Role::where('name', 'Administrator')->first()) {
            $role->givePermissionTo($perms);
        }

        // Manager decides approvals and imports, but does not own take-on.
        if ($role = Role::where('name', 'Manager')->first()) {
            $role->givePermissionTo(['approvals.view', 'approvals.manage', 'imports.manage']);
        }

        // Everyone can see their own approval inbox (requests they raised).
        if ($role = Role::where('name', 'Staff')->first()) {
            $role->givePermissionTo(['approvals.view']);
        }

        // Starter approval matrix — inactive until activated.
        $defaults = [
            [
                'name' => 'High-value purchase orders',
                'document_type' => 'purchase_order',
                'min_amount' => 50000,
                'approver_permission' => 'purchase.orders.approve',
                'require_segregation' => true,
                'priority' => 10,
                'description' => 'POs at or above 50,000 BDT need a manager approval before confirmation.',
            ],
            [
                'name' => 'High-value vendor bills',
                'document_type' => 'vendor_bill',
                'min_amount' => 100000,
                'approver_permission' => 'purchase.bills.post',
                'require_segregation' => true,
                'priority' => 10,
                'description' => 'Vendor bills at or above 100,000 BDT need a second pair of eyes before posting to AP.',
            ],
            [
                'name' => 'High-value sales invoices',
                'document_type' => 'sales_invoice',
                'min_amount' => 200000,
                'approver_permission' => 'sales.invoices.post',
                'require_segregation' => true,
                'priority' => 10,
                'description' => 'Sales invoices at or above 200,000 BDT need approval before posting to AR.',
            ],
        ];

        foreach ($defaults as $policy) {
            ApprovalPolicy::firstOrCreate(
                ['name' => $policy['name'], 'document_type' => $policy['document_type']],
                $policy + ['is_active' => false],
            );
        }
    }
}
