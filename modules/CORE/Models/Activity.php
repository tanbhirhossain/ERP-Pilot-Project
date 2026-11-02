<?php

namespace Modules\CORE\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * JCI-style audit trail record. Extends the spatie activity model and stamps
 * the request context (IP / user agent) on every entry that is written during
 * an HTTP request — whether it came from automatic model logging or a manual
 * AuditTrailService call.
 *
 * Audit entries are intentionally never editable or deletable through the UI.
 */
class Activity extends SpatieActivity
{
    protected static function booted(): void
    {
        static::saving(function (Activity $activity): void {
            $context = self::requestContext();

            if ($context === []) {
                return;
            }

            $properties = $activity->properties;

            $properties = $properties instanceof Collection
                ? $properties->toArray()
                : (array) $properties;

            $activity->properties = collect(array_merge($context, $properties));
        });
    }

    /**
     * @return array<string, string>
     */
    public static function requestContext(): array
    {
        if (! app()->bound('request')) {
            return [];
        }

        $request = request();

        if ($request->server('APP_RUNNING_IN_CONSOLE')) {
            return [];
        }

        $context = [];

        if ($ip = $request->ip()) {
            $context['ip'] = $ip;
        }

        if ($userAgent = (string) $request->userAgent()) {
            $context['user_agent'] = mb_substr($userAgent, 0, 512);
        }

        return $context;
    }

    /** @return MorphTo<Model, $this> */
    public function causer(): MorphTo
    {
        return $this->morphTo('causer');
    }

    /**
     * Only the light-weight columns are selected for list views — the
     * properties / attribute_changes payloads stay on the detail page so a
     * billion row audit table remains cheap to paginate.
     */
    public function scopeForList(Builder $query): Builder
    {
        return $query->select([
            'id',
            'log_name',
            'description',
            'subject_type',
            'subject_id',
            'event',
            'causer_type',
            'causer_id',
            'created_at',
            'updated_at',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toTableRow(): array
    {
        return [
            'id' => $this->id,
            'log_name' => $this->log_name,
            'description' => $this->description,
            'event' => $this->event,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'causer' => $this->causer instanceof User
                ? ['id' => $this->causer->id, 'name' => $this->causer->name, 'email' => $this->causer->email]
                : null,
            'subject' => $this->subject instanceof Model
                ? [
                    'type' => class_basename($this->subject_type),
                    'id' => $this->subject->getKey(),
                    'label' => (string) ($this->subject->name ?? $this->subject->title ?? $this->subject->getKey()),
                ]
                : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
