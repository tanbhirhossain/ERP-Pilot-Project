<?php

namespace Modules\HRM\Support\Attendance;

use Carbon\Carbon;

/**
 * A `--from` / `--to` command-line date, checked before any device is touched.
 *
 * `php artisan hrm:pipeline --from=2026` looked like a device fault: three F18s, each reporting
 * `{"ok":false,"fetched":0,"inserted":0}` under the label `PARTIAL`. The reason was a silently invented date.
 * `Carbon::parse('2026')` does not fail — it reads the digits as a *clock time* and returns today at 20:26, so
 * the sync asked each device for punches later than a moment in the future, and got none. `2026-11` becomes the
 * 1st of the month, and `2026-02-31` rolls over to 3 March. Anything that quietly rewrites a date is a bug the
 * operator has to discover sideways, so the shape is checked here instead — once, for both commands that take
 * these options.
 *
 * The web UI is not exposed to this: `AttendanceSyncController` already validates `from_date` / `to_date` with
 * the `date` rule, and an `<input type="date">` can only send `Y-m-d`.
 */
final class DailyDateArgument
{
    /**
     * @return array{ok: bool, value: string|null, error: string|null}
     */
    public static function parse(string $label, mixed $value): array
    {
        if ($value === null || $value === '') {
            return ['ok' => true, 'value' => null, 'error' => null];
        }

        $value = trim((string) $value);

        // The day is checked against the calendar from the digits themselves, not from a parsed date: Carbon is
        // lenient and rolls `2026-02-31` over to March 3, which is a perfectly valid day to `checkdate`.
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T]\d{2}:\d{2}(:\d{2})?)?$/', $value, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return ['ok' => true, 'value' => $value, 'error' => null];
        }

        return [
            'ok' => false,
            'value' => null,
            'error' => "--{$label}=\"{$value}\" is not a day. Give the whole date, e.g. --{$label}=2026-11-01".
                ' (a bare year or month is deliberately not guessed — a wrong start date pulls months of punches through the pairing stages).',
        ];
    }

    /**
     * Resolve the words the sync commands have always accepted, before the strict check.
     */
    public static function resolve(string $label, mixed $value): array
    {
        if (is_string($value) && in_array(strtolower(trim($value)), ['today', 'yesterday'], true)) {
            $day = strtolower(trim($value)) === 'today' ? Carbon::today() : Carbon::yesterday();

            return ['ok' => true, 'value' => $day->toDateString(), 'error' => null];
        }

        return self::parse($label, $value);
    }
}
