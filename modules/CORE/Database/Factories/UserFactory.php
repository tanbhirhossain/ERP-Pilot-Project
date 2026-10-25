<?php

namespace Modules\CORE\Database\Factories;

use Database\Factories\UserFactory as AppUserFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\CORE\Models\User;

/**
 * Resolves automatically for Modules\CORE\Models\User (HasFactory convention)
 * and produces module User instances — the authenticatable model that carries
 * HasRoles + LogsActivity.
 *
 * @extends Factory<User>
 */
class UserFactory extends AppUserFactory
{
    /** @var class-string<User> */
    protected $model = User::class;
}
