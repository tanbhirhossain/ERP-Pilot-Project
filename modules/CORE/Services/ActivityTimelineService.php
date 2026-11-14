<?php

namespace Modules\CORE\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Modules\CORE\Models\Activity;

/**
 * Per-record activity timeline (Phase 2 — U14).
 *
 * Formats the Spatie activity log for one subject into a render-ready timeline:
 * who did what, when, and a field-level diff for status/amount changes. The
 * caller (a Show controller) has already authorised access to the record, so it
 * is safe to surface that record's history here. Read-only.
 */
class ActivityTimelineService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function for(Model $subject, int $limit = 50): array
    {
        $activities = Activity::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->with('causer')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $activities->map(function (Activity $activity): array {
            $properties = $activity->properties instanceof Collection
                ? $activity->properties->toArray()
                : (array) $activity->properties;

            return [
                'id' => $activity->id,
                'description' => $activity->description,
                'event' => $activity->event,
                'causer' => $activity->causer?->name ?? 'System',
                'created_at' => $activity->created_at?->toIso8601String(),
                'changes' => $this->diff($properties),
            ];
        })->all();
    }

    /**
     * Build a field-level diff from Spatie's `attributes` (new) + `old` values.
     *
     * @param  array<string, mixed>  $properties
     * @return array<int, array{field: string, old: mixed, new: mixed}>
     */
    private function diff(array $properties): array
    {
        $new = (array) ($properties['attributes'] ?? []);
        $old = (array) ($properties['old'] ?? []);

        $changes = [];
        foreach ($new as $field => $value) {
            // Skip noisy bookkeeping columns.
            if (in_array($field, ['updated_at', 'created_at'], true)) {
                continue;
            }
            $changes[] = [
                'field' => $field,
                'old' => $old[$field] ?? null,
                'new' => $value,
            ];
        }

        return $changes;
    }
}
