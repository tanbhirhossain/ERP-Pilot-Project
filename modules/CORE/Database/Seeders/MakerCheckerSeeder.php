<?php

namespace Modules\CORE\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\CORE\Models\Permission;
use Modules\CORE\Models\Role;
use Modules\CORE\Models\User;

/**
 * Maker-checker (segregation of duties) for the Purchase + Accounting flows.
 *
 * Two-door rule, enforced by permission split (soft SoD):
 *   - Makers DRAFT: requisitions / RFQs / orders / receipts / bills / journals / vouchers.
 *   - Checkers DECIDE: approve / award / confirm / post / void.
 *
 * Four roles, each confined to one stream and one half of the door:
 *   - "Purchase Maker"    — draft side of Purchase (+ vendor/product lookups so forms open).
 *   - "Purchase Checker"  — everything the Purchase Maker holds, PLUS the six decision
 *                            grants (requisitions.approve, rfq.award, orders.confirm,
 *                            orders.approve, receipts.post, bills.post).
 *   - "Accounts Maker"    — draft side of Accounting (journals.create, vouchers, read-only
 *                            ledgers/reports).
 *   - "Accounts Checker"  — everything the Accounts Maker holds, PLUS the decision grants
 *                            (coa.manage, journals.post, journals.void, banks.manage,
 *                            fiscal.manage, withholding/assets/budgets.manage).
 *
 * One person must never hold both halves of a same-stream pair: re-runs strip the
 * Checker role from any user that also holds that stream's Maker role (with a warning),
 * so the segregation cannot silently rot after a manual role assignment.
 *
 * Idempotent: safe to run on the dev VPS database AND on production. Role grants are
 * additive only (givePermissionTo never removes), demo users are firstOrCreate, and the
 * conflict-strip only touches the four roles created here. Existing users (Nadia/Arif/Sara,
 * Super Admin, factories) are never modified.
 *
 * Demo logins (password via MAKER_CHECKER_DEMO_PASSWORD, default 'ChangeMe123!' on
 * non-production; in production the demo users are SKIPPED unless the env password
 * is explicitly set):
 *   - purchase.maker@example.com   / Purchase Maker
 *   - purchase.checker@example.com / Purchase Checker
 *   - accounts.maker@example.com   / Accounts Maker
 *   - accounts.checker@example.com / Accounts Checker
 */
class MakerCheckerSeeder extends Seeder
{
    public const MAKER_PASSWORD_ENV = 'MAKER_CHECKER_DEMO_PASSWORD';

    public const DEFAULT_PASSWORD = 'ChangeMe123!';

    /**
     * Same-stream role pairs one person must never hold together.
     *
     * @var list<array{0: string, 1: string}>
     */
    public const CONFLICT_PAIRS = [
        ['Purchase Maker', 'Purchase Checker'],
        ['Accounts Maker', 'Accounts Checker'],
    ];

    /**
     * Decision grants — the "second door". Makers must never hold these.
     *
     * @var list<string>
     */
    public const DECISION_PERMISSIONS = [
        'purchase.requisitions.approve',
        'purchase.rfq.award',
        'purchase.orders.confirm',
        'purchase.orders.approve',
        'purchase.receipts.post',
        'purchase.bills.post',
        'accounts.coa.manage',
        'accounts.journals.post',
        'accounts.journals.void',
        'accounts.banks.manage',
        'accounts.fiscal.manage',
        'accounts.withholding.manage',
        'accounts.assets.manage',
        'accounts.budgets.manage',
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            foreach ($this->rolePermissions() as $roleName => $perms) {
                foreach ($perms as $perm) {
                    Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
                }

                $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);

                // Additive only: a re-run grants what is missing but never strips a grant
                // a production admin added to these roles by hand.
                $role->givePermissionTo($perms);
            }

            // In production the demo logins are only created when an explicit password is
            // configured — never with the published default. Roles (the actual SoD) are
            // always seeded.
            $configuredPassword = env(self::MAKER_PASSWORD_ENV);

            if (($configuredPassword === null || $configuredPassword === '') && app()->isProduction()) {
                $this->command?->warn(
                    'Production: '.self::MAKER_PASSWORD_ENV.' not set — roles seeded, demo users skipped.'
                );
            } else {
                $password = Hash::make(
                    ($configuredPassword === null || $configuredPassword === '')
                        ? self::DEFAULT_PASSWORD
                        : (string) $configuredPassword
                );

                User::withoutEvents(function () use ($password): void {
                    foreach ($this->demoUsers() as $demo) {
                        $user = User::firstOrCreate(
                            ['email' => $demo['email']],
                            [
                                'name' => $demo['name'],
                                'password' => $password,
                                'email_verified_at' => now(),
                            ],
                        );

                        if (! $user->hasRole($demo['role'])) {
                            $user->assignRole($demo['role']);
                        }
                    }
                });
            }

            $this->stripConflicts();
        });

        $this->command?->info('Maker-checker ready: Purchase/Accounts Maker + Checker roles (+4 demo users).');
    }

    /**
     * One person must never hold both halves of a same-stream pair. If a manual role
     * assignment (or an older seed) put a user on both sides, keep the Maker half and
     * strip the Checker half so the second door keeps meaning something.
     */
    protected function stripConflicts(): void
    {
        foreach (self::CONFLICT_PAIRS as [$maker, $checker]) {
            $both = User::role($maker)
                ->whereHas('roles', fn ($q) => $q->where('name', $checker))
                ->get();

            foreach ($both as $user) {
                $user->removeRole($checker);
                $this->command?->warn(
                    "SoD: removed '{$checker}' from {$user->email} (already holds '{$maker}')."
                );
            }
        }
    }

    /**
     * @return array<string, list<string>> role name => exact permission names
     */
    protected function rolePermissions(): array
    {
        $purchaseMaker = [
            // Draft side.
            'purchase.requisitions.view', 'purchase.requisitions.manage',
            'purchase.rfq.view', 'purchase.rfq.manage',
            'purchase.orders.view', 'purchase.orders.manage',
            'purchase.receipts.view', 'purchase.receipts.manage',
            'purchase.bills.view', 'purchase.bills.manage',
            'purchase.payments.manage',
            'purchase.reports.view',
            // Vendor picker on order/bill/RFQ forms.
            'partner.vendors.view',
            // Product/warehouse lookups on order/receipt/bill lines (Inventory + Partner
            // OptionsControllers admit exactly these grants).
            'inventory.products.view', 'inventory.products.create', 'inventory.products.update',
            'inventory.categories.manage', 'inventory.brands.manage', 'inventory.uoms.manage',
            'inventory.stocks.view', 'inventory.movements.view',
        ];

        $accountsMaker = [
            // Draft side.
            'accounts.journals.view', 'accounts.journals.create',
            'accounts.vouchers.view', 'accounts.vouchers.manage',
            // Read-only ledgers / reports a draft needs open beside it.
            'accounts.coa.view',
            'accounts.banks.view',
            'accounts.reports.view', 'accounts.payables.view', 'accounts.receivables.view',
            'accounts.withholding.view',
            'accounts.assets.view',
            'accounts.budgets.view',
        ];

        return [
            'Purchase Maker' => $purchaseMaker,
            // A checker opens the same drafts to review them, so each checker is a strict
            // superset of its maker: same desk, plus the decision stamp.
            'Purchase Checker' => array_merge($purchaseMaker, [
                'purchase.requisitions.approve',
                'purchase.rfq.award',
                'purchase.orders.confirm',
                'purchase.orders.approve',
                'purchase.receipts.post',
                'purchase.bills.post',
            ]),
            'Accounts Maker' => $accountsMaker,
            'Accounts Checker' => array_merge($accountsMaker, [
                'accounts.coa.manage',
                'accounts.journals.post',
                'accounts.journals.void',
                'accounts.banks.manage',
                'accounts.fiscal.manage',
                'accounts.withholding.manage',
                'accounts.assets.manage',
                'accounts.budgets.manage',
            ]),
        ];
    }

    /**
     * @return list<array{name: string, email: string, role: string}>
     */
    protected function demoUsers(): array
    {
        return [
            ['name' => 'Purchase Maker', 'email' => 'purchase.maker@example.com', 'role' => 'Purchase Maker'],
            ['name' => 'Purchase Checker', 'email' => 'purchase.checker@example.com', 'role' => 'Purchase Checker'],
            ['name' => 'Accounts Maker', 'email' => 'accounts.maker@example.com', 'role' => 'Accounts Maker'],
            ['name' => 'Accounts Checker', 'email' => 'accounts.checker@example.com', 'role' => 'Accounts Checker'],
        ];
    }
}
