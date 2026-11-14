<?php

/*
|--------------------------------------------------------------------------
| HRM configuration
|--------------------------------------------------------------------------
|
| Registered by HRMServiceProvider as config('hrm.*').
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Attendance device sync
    |--------------------------------------------------------------------------
    |
    | `allow_simulation` is the ONLY way to make the sync service invent attendance
    | data, and it defaults to false. Outside a local/testing/development environment
    | the service either returns what a device reported or it throws. It used to also
    | answer "is this a demo device?" by comparing the device's IP address against a
    | hardcoded list of three 192.168.10.9x addresses that came from a seeder — so on
    | a production host, any customer whose LAN happened to use that extremely common
    | private range got fabricated punch rows written into the attendance table that
    | payroll reads. An IP address is where a device lives, not what a device is.
    | See AUDIT_REPORT.md item 46 / P1.8.
    |
    */

    'sync' => [
        'allow_simulation' => env('HRM_ALLOW_ATTENDANCE_SIMULATION', false),

        /*
        |----------------------------------------------------------------------
        | How long one device sync may keep working
        |----------------------------------------------------------------------
        |
        | A sync stops between records once it has spent this many seconds, and
        | says so (`partial` in the result, `Partial` in the sync log). Whatever
        | it did not read is left for the next run, because `last_sync_at` is
        | only advanced by a sync that finished.
        |
        | This is the number that keeps a queue worker alive. The code used to
        | call `set_time_limit(300)` instead, which *lowered* the limit a job had
        | just removed, and on Windows (wall-clock) killed the worker mid-sync —
        | and a budget that a fatal error enforces is not a budget.
        |
        | Keep it under whatever `php -d max_execution_time` / FPM `request_
        | timeouts` the caller has, and under the job's own `$timeout`.
        */
        'budget_seconds' => (int) env('HRM_SYNC_BUDGET_SECONDS', 240),

        /*
        |----------------------------------------------------------------------
        | PHP extensions a device sync cannot work without
        |----------------------------------------------------------------------
        |
        | `hrm:pipeline` checks these before it contacts a device. The ZKTeCo
        | client opens a UDP socket through `ext-sockets`, which a stock Windows
        | PHP ships disabled in php.ini — so every device in the fleet fails in
        | under a second, identically, and the run looks like a device problem.
        | Failing once, loudly, with the fix in the message is worth more than
        | three timeouts. Listed as configuration so the guard can be tested
        | with an extension that really is absent.
        |
        */
        'required_extensions' => ['sockets'],
    ],

    'attendance' => [

        /*
        |----------------------------------------------------------------------
        | Fallback off day vs. roster duty
        |----------------------------------------------------------------------
        |
        | Every employee may carry a "fallback off day" (a Carbon weekday,
        | 0=Sunday .. 6=Saturday) in `employee_fallback_off_days`. It was
        | originally documented as "used only when no roster duty exists for
        | the date".
        |
        | That precondition does not survive contact with bulk rostering:
        | RosterDutyRepository::bulkAssign() writes a duty for EVERY calendar
        | day in the range with status 'Scheduled' and a shift — it has no
        | weekly pattern and does not skip weekends. So once a month is
        | bulk-rostered, a duty exists for the employee's off day too, the
        | `!$duty` guard fails, and the off day is classified 'Absent'.
        |
        | Default (false): the fallback off day wins over a roster duty that
        | merely exists. An explicit roster status of 'Off' is always honoured
        | either way, and any day with real punches is still classified from
        | the punches (Extra Duty), so no worked hours are ever relabelled.
        |
        | Set true to restore strict roster authority — use this if you
        | deliberately roster people onto their off day and want a no-show on
        | such a day recorded as Absent.
        |
        */

        'roster_overrides_fallback_off_day' => (bool) env('HRM_ROSTER_OVERRIDES_FALLBACK_OFF_DAY', false),

    ],

];
