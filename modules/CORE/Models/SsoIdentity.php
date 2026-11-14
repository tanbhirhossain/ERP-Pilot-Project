<?php

namespace Modules\CORE\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SSO — one linked external identity (Phase 3).
 *
 * Deliberately has no money columns (`MoneyColumnCastTest` sees the table and finds nothing to judge),
 * and no soft deletes: an unlinked identity is a fact that stopped being true, and `destroy` on the
 * service side is the only writer.
 */
class SsoIdentity extends Model
{
    protected $fillable = [
        'provider', 'provider_user_id', 'email', 'name', 'avatar_url', 'user_id', 'last_login_at',
    ];

    protected $casts = [
        'last_login_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
