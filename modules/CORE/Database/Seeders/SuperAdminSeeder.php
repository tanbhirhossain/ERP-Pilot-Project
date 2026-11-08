<?php

namespace Modules\CORE\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Modules\CORE\Models\Permission;
use Modules\CORE\Models\Role;
use Modules\CORE\Models\User;
use Modules\CORE\Support\PublishedCredentialGuard;

/**
 * Provisions the omnipotent platform administrator.
 *
 * Safe to run any number of times, on an empty database or a live one:
 *  - ensures the wildcard permission (*) exists,
 *  - ensures the Super Admin role holds exactly [*],
 *  - ensures the admin account exists, is verified and holds the role.
 *
 * Credentials are configurable (config/core.php → super_admin, overridable
 * via CORE_SUPER_ADMIN_* env vars) and default to admin@example.com /
 * password. Model events are muted so seeding never pollutes the JCI audit
 * trail — real usage (logins, CRUD) is what the trail is for.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $name = (string) config('core.super_admin.name', 'Super Admin');
        $email = (string) config('core.super_admin.email', 'admin@example.com');
        $password = (string) config('core.super_admin.password', 'password');

        // Refuse to create the hole rather than creating it and blocking it at login. This role
        // gets the `*` wildcard permission, so a published password here is administrative access
        // for anyone who has read the repository.
        if (! PublishedCredentialGuard::seedersMayWritePublishedPassword()
            && PublishedCredentialGuard::isPublishedPassword($password)) {
            throw new \RuntimeException(
                'Refusing to seed the super admin in production with a password published in this repository. '
                .'Set CORE_SUPER_ADMIN_PASSWORD to a real secret and run the seeder again, or set '
                .'CORE_BLOCK_PUBLISHED_CREDENTIALS=false to override deliberately.'
            );
        }

        User::withoutEvents(function () use ($name, $email, $password): void {
            // Wildcard permission — with enable_wildcard_permission this makes
            // the Super Admin role permanently omnipotent.
            Permission::query()->firstOrCreate(['name' => '*', 'guard_name' => 'web']);

            $role = Role::query()->firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
            $role->permissions()->sync(
                Permission::query()->where('name', '*')->pluck('id')->all()
            );

            $admin = User::query()->firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Hash::make($password),
                    'email_verified_at' => now(),
                ],
            );

            // Self-healing: re-runs restore any accidentally removed assignment.
            $admin->syncRoles(['Super Admin']);
        });

        // Password is deliberately not echoed: this line lands in CI output and
        // deploy transcripts. Set it via CORE_SUPER_ADMIN_PASSWORD.
        $this->command?->info("Super admin ready: {$email}");
    }
}
