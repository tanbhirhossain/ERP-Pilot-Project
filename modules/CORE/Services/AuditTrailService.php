<?php

namespace Modules\CORE\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Single, consistent entry point for every manually written audit entry.
 *
 * Automatic model logging (LogsActivity) covers plain CRUD; this service adds
 * the structured, JCI-relevant events around it: role/permission assignments,
 * authentication lifecycle, exports, etc. Request context (IP + user agent) is
 * stamped by the Activity model itself.
 */
class AuditTrailService
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function log(string $logName, ?Model $subject, string $description, ?string $event = null, array $properties = []): void
    {
        $logger = activity($logName);

        if ($causer = Auth::user()) {
            $logger->causedBy($causer);
        } else {
            $logger->causedByAnonymous();
        }

        if ($subject !== null) {
            $logger->performedOn($subject);
        }

        if ($event !== null) {
            $logger->event($event);
        }

        $logger->withProperties($properties)->log($description);
    }

    /**
     * Authentication lifecycle audit (login / logout / failed / register).
     *
     * @param  array<string, mixed>  $properties
     */
    public function authEvent(string $event, ?Authenticatable $user, string $description, array $properties = []): void
    {
        $logger = activity('auth')->event($event);

        if ($user instanceof Model) {
            $logger->causedBy($user);
        } else {
            $logger->causedByAnonymous();
        }

        $logger->withProperties($properties)->log($description);
    }
}
