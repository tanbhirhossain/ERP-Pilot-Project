<?php

namespace Modules\CORE\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\CORE\Models\Permission;
use Modules\CORE\Models\Role;
use Modules\CORE\Models\User;
use Modules\CORE\Support\PublishedCredentialGuard;

/**
 * Seeds the full ACL matrix, roles and a ready-to-login super admin.
 *
 * Run it directly — seeders live inside the module by design:
 *
 *   php artisan db:seed --class="Modules\\CORE\\Database\\Seeders\\CoreSeeder"
 */
class CoreSeeder extends Seeder
{
    /**
     * The complete permission matrix (resource.action convention).
     *
     * @var list<string>
     */
    private const PERMISSIONS = [
        'dashboard.view',
        'users.view',
        'users.create',
        'users.update',
        'users.delete',
        'roles.view',
        'roles.create',
        'roles.update',
        'roles.delete',
        'permissions.view',
        'permissions.create',
        'permissions.update',
        'permissions.delete',
        'audit.view',
        // Item 42: the company-wide colour scheme and mode. Choosing your own
        // needs no permission, so there is deliberately no `.view` sibling.
        'settings.appearance.manage',
    ];

    /**
     * @return array<string, list<string>>
     */
    private const ROLE_MATRIX = [
        'Super Admin' => ['*'],
        'Administrator' => self::PERMISSIONS,
        'Manager' => [
            'dashboard.view',
            'users.view',
            'users.create',
            'users.update',
            'roles.view',
            'audit.view',
        ],
        'Auditor' => [
            'dashboard.view',
            'users.view',
            'roles.view',
            'permissions.view',
            'audit.view',
        ],
        'Staff' => [
            'dashboard.view',
            'users.view',
        ],
    ];

    public function run(): void
    {
        // A fresh installation should start with a clean audit trail — model
        // events (LogsActivity) are muted for the entire seed run.
        User::withoutEvents(function (): void {
            $this->seed();
        });

        // Never echo the password: seed output lands in CI logs and deploy
        // transcripts. Operators set it through CORE_SUPER_ADMIN_PASSWORD.
        $this->command?->info(sprintf(
            'CORE module seeded: %d permissions, %d roles, super admin (%s).',
            count(self::PERMISSIONS) + 1,
            count(self::ROLE_MATRIX),
            config('core.super_admin.email'),
        ));
    }

    private function seed(): void
    {
        // ----------------------------------------------------------- Permissions
        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
            );
        }

        // Wildcard permission — combined with enable_wildcard_permission it
        // makes the Super Admin role permanently omnipotent.
        Permission::firstOrCreate(['name' => '*', 'guard_name' => 'web']);

        // --------------------------------------------------------------- Roles
        foreach (self::ROLE_MATRIX as $roleName => $permissions) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $permissionIds = Permission::query()
                ->whereIn('name', $permissions)
                ->pluck('id')
                ->all();

            $role->permissions()->sync($permissionIds);
        }

        // ------------------------------------------------------- Super admin
        // Delegated so there is exactly one definition of what "the platform
        // administrator" is (also runnable standalone — see the class docblock).
        $this->call(SuperAdminSeeder::class);

        // ------------------------------------------------------- Demo dataset
        // A realistic multi-role team + bulk rows so the DataTable has
        // something meaningful to page through.

        $people = [
            ['name' => 'Nadia Rahman', 'email' => 'nadia@example.com', 'role' => 'Manager', 'verified' => true],
            ['name' => 'Arif Chowdhury', 'email' => 'arif@example.com', 'role' => 'Auditor', 'verified' => true],
            ['name' => 'Sara Ahmed', 'email' => 'sara@example.com', 'role' => 'Staff', 'verified' => false],
        ];

        // In production these demo accounts get an unknowable random password instead of the
        // published starter one this file used to hardcode. They keep their role assignments, so any seeded row that
        // points at them still resolves, but nobody can sign in until an administrator resets one.
        $demoPassword = PublishedCredentialGuard::seedersMayWritePublishedPassword()
            ? 'password'
            : Str::password(24);

        foreach ($people as $person) {
            $user = User::query()->firstOrCreate(
                ['email' => $person['email']],
                [
                    'name' => $person['name'],
                    'password' => Hash::make($demoPassword),
                    'email_verified_at' => $person['verified'] ? now() : null,
                ],
            );

            $user->syncRoles([$person['role']]);
        }

        $existing = User::query()->count();

        if ($existing < 200) {
            // UserFactory defaults to hashing the published starter password, so this loop would create up
            // to 200 production accounts that all share a credential published in the repository.
            // Hashed once and reused — 200 separate bcrypt rounds would dominate the seed run.
            User::factory()->count(200 - $existing)->create([
                'password' => Hash::make($demoPassword),
                'email_verified_at' => fn (array $attributes) => fake()->boolean(70) ? now() : null,
            ]);
        }

    }
}
