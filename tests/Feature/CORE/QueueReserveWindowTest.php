<?php

/**
 * A job must not outlive the window it is reserved for.
 *
 * `queue:work` marks a job "reserved" for `retry_after` seconds (400 in this app's database connection). If
 * the job is still running when that expires, the queue considers it lost and hands it to another worker —
 * not as a retry after a failure, but as a *second concurrent run of live work*. For a payroll batch that is
 * two workers computing the same sheet; for an attendance pairing repair, two clears of the same month
 * racing each other; for a device sync, two socket transfers of the same log and a `last_sync_at` written by
 * whichever one finishes last.
 *
 * The per-job `$timeout` is the declared ceiling of that work. So the reserve window has to be longer than
 * it — otherwise the ceiling is a promise the queue will not keep. Three jobs here had a timeout above 400
 * (`FixAttendancePairingJob` 1800, `ProcessSalarySheetJob` 900, `SyncDeviceJob` 420) and none had a
 * `$retryAfter`; they now do, and this pins the relationship so the next long-running job cannot be added
 * without one.
 */

use Illuminate\Support\Facades\File;

/**
 * Code lines only — the docblocks here quote `retry_after` and its numbers on purpose.
 *
 * @return array<int, string>
 */
function qrwCode(string $path): string
{
    $code = '';
    $tokens = token_get_all((string) file_get_contents($path));

    foreach ($tokens as $token) {
        if (is_array($token) && in_array($token[0], [T_DOC_COMMENT, T_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
}

/**
 * @return array<int, string>
 */
function qrwJobFiles(): array
{
    $files = [];

    foreach (['modules', 'app'] as $dir) {
        if (! is_dir(base_path($dir))) {
            continue;
        }

        foreach (File::allFiles(base_path($dir)) as $file) {
            $path = str_replace('\\', '/', $file->getPathname());

            if ($file->getExtension() === 'php' && preg_match('#/(Jobs|Queues)/[A-Za-z0-9]+\.php$#', $path)) {
                $files[] = $file->getPathname();
            }
        }
    }

    sort($files);

    return $files;
}

it('reserves every queue job for at least as long as its own ceiling', function (): void {
    // The production connection. `sync` has no reservation at all (the job runs inline), so asking it would
    // ask the wrong question in tests.
    $window = (int) config('queue.connections.database.retry_after');
    $offences = [];

    foreach (qrwJobFiles() as $path) {
        $code = qrwCode($path);

        if (! preg_match('/class\s+\w+[^{]*implements[^{]*ShouldQueue/', $code)) {
            continue;
        }

        $timeout = preg_match('/public\s+int\s+\$timeout\s*=\s*(\d+)/', $code, $m) ? (int) $m[1] : null;

        if ($timeout === null || $timeout === 0) {
            continue; // no declared ceiling means the worker's own --timeout governs, which is per-process
        }

        $retryAfter = preg_match('/public\s+int\s+\$retryAfter\s*=\s*(\d+)/', $code, $m) ? (int) $m[1] : $window;

        if ($retryAfter < $timeout + 60) {
            $offences[] = sprintf(
                '%s: timeout %ds but reserved for %ds — a job still running at %ds is handed to a second worker',
                str_replace(base_path().'/', '', $path),
                $timeout,
                $retryAfter,
                $retryAfter
            );
        }
    }

    expect($offences)->toBe([], implode("\n", $offences));
})->group('queue');

it('does not let a job rely on --timeout to avoid a double run', function (): void {
    // `--timeout` needs ext-pcntl (absent on a stock Windows PHP, where it silently does nothing), and it
    // *kills* the worker rather than stopping the unit of work. A job whose ceiling is that high must bound
    // its own work — see hrm.sync.budget_seconds — and widen its reservation, which is what this file is about.
    $window = (int) config('queue.connections.database.retry_after');
    $aboveWithoutOverride = [];

    foreach (qrwJobFiles() as $path) {
        $code = qrwCode($path);

        if (! preg_match('/class\s+\w+[^{]*implements[^{]*ShouldQueue/', $code)) {
            continue;
        }

        $timeout = preg_match('/public\s+int\s+\$timeout\s*=\s*(\d+)/', $code, $m) ? (int) $m[1] : 0;
        $declaresOverride = (bool) preg_match('/public\s+int\s+\$retryAfter\s*=/', $code);

        if ($timeout > $window && ! $declaresOverride) {
            $aboveWithoutOverride[] = str_replace(base_path().'/', '', $path)." (timeout {$timeout}s > {$window}s reserve)";
        }
    }

    expect($aboveWithoutOverride)->toBe([], "jobs that outlive the connection's reserve window must declare \$retryAfter:\n".implode("\n", $aboveWithoutOverride));
})->group('queue');
