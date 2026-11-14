<?php

namespace Modules\CORE\Models;

use App\Models\User as AppUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;
use Modules\CORE\Database\Factories\UserFactory;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

/**
 * The application's authenticatable user, extended inside the CORE module so
 * the starter kit's app layer stays untouched.
 *
 * Registered via the auth provider swap performed in COREServiceProvider.
 */
class User extends AppUser
{
    /**
     * SSO — the external identities that may sign in as this account (Phase 3).
     *
     * A user may hold several (Google at work, Entra at work, a personal one they must not use here),
     * and an account with none of these and no usable password is what `SsoService::unlink` refuses to
     * create.
     */
    public function ssoIdentities(): HasMany
    {
        return $this->hasMany(SsoIdentity::class, 'user_id');
    }

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, LogsActivity;

    /**
     * Bind this model to the module's factory (the default name guesser would
     * look for Database\Factories\Modules\...).
     */
    protected static function newFactory(): Factory
    {
        return UserFactory::new();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('users')
            ->logOnly(['name', 'email', 'email_verified_at'])
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn (string $event): string => match ($event) {
                'created' => 'User account created',
                'updated' => 'User account updated',
                'deleted' => 'User account deleted',
                default => 'User '.$event,
            });
    }
}
