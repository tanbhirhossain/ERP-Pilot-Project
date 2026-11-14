<?php

/**
 * Guard for the bug that §18.5 of docs/PHASE3_IMPLEMENTATION_2026-11-08.md records.
 *
 * The pairing repair keeps a snapshot of every punch-less day before it clears a range, so it can put back
 * what the rebuild cannot derive. The clear used `whereDate('duty_date', '>=', $from)` and the snapshot used
 * `whereBetween('duty_date', [$from.' 00:00:00', $to.' 23:59:59'])`. `attendance_dailies.duty_date` is a
 * `date` column, so for the range's first day that comparison is `'2026-10-01' >= '2026-10-01 00:00:00'` —
 * false. Day one was deleted and never snapshotted, which is the exact loss the repair exists to prevent,
 * and no assertion about statuses, counts or reinstatement could see it: both queries were "obviously" the
 * same range.
 *
 * So the shape is pinned here instead: the repair's policy file writes no SQL, the range is expressed once,
 * and no date column in any module is ever compared against a time-suffixed bound (or the reverse, which
 * loses the last day of a range on a `dateTime` column).
 */

use Illuminate\Support\Facades\File;

/**
 * Every non-migration PHP file under `modules/`, `app/` and `routes/`.
 *
 * @return array<int, string>
 */
function archSourceFiles(): array
{
    $root = base_path();
    $files = [];

    foreach (['modules', 'app', 'routes'] as $dir) {
        if (! is_dir("$root/$dir")) {
            continue;
        }

        foreach (File::allFiles("$root/$dir") as $file) {
            if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), 'Database'.DIRECTORY_SEPARATOR.'Migrations')) {
                continue;
            }

            $files[] = $file->getPathname();
        }
    }

    return $files;
}

/**
 * Code lines with their line numbers, comments removed.
 *
 * The comments in this module *describe* both mistakes at length, so a scan that read them would report the
 * documentation as a defect — which is exactly how a guard like this ends up ignored.
 *
 * @return array<int, array{0: int, 1: string}>
 */
function archCodeLines(string $path): array
{
    $out = [];
    $line = 1;

    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        $text = is_array($token) ? $token[1] : $token;
        $skipped = is_array($token) && in_array($token[0], [T_DOC_COMMENT, T_COMMENT, T_COMMENT], true);

        if (! $skipped) {
            foreach (explode("\n", $text) as $offset => $part) {
                if (trim($part) !== '') {
                    $out[] = [$line + $offset, $part];
                }
            }
        }

        $line += substr_count($text, "\n");
    }

    return $out;
}

/** The one file allowed to write these idioms: it is the single owner of the repair's range. */
function archScopeOwner(): string
{
    return base_path('modules/HRM/Support/Attendance/AttendanceRepairScope.php');
}

test('the repair service decides, it does not query', function (): void {
    $src = (string) file_get_contents(base_path('modules/HRM/Services/Attendance/AttendanceRepairService.php'));

    expect($src)->not->toMatch('/\bDB::/', 'the policy file must not reach for the facade — that is how a second, subtly different range predicate got written')
        ->and($src)->not->toMatch('/::query\(\)/', 'row reads belong to the repository')
        ->and($src)->not->toMatch('/whereDate\(|whereBetween\(|whereIn\(/', 'the range is built once, by AttendanceRepairScope')
        ->and($src)->toMatch('/AttendanceRepairRepositoryInterface \$rows/', 'the repair must depend on the port, not the driver');
});

test('a repair range has exactly one owner', function (): void {
    $owners = [
        'modules/HRM/Services/Attendance/AttendanceRepairService.php',
        'modules/HRM/Repositories/Attendance/AttendanceRepairRepository.php',
    ];

    foreach ($owners as $relative) {
        $src = (string) file_get_contents(base_path($relative));

        expect(basename($relative))->not->toMatch('/whereDate\(\s*[\'"]duty_date/', 'range bounds must come from AttendanceRepairScope');
    }

    $scope = (string) file_get_contents(base_path('modules/HRM/Support/Attendance/AttendanceRepairScope.php'));

    expect($scope)->toMatch('/whereDate\(\'duty_date\', \'>=\', \$this->from\)/')
        ->and($scope)->toMatch('/whereDate\(\'duty_date\', \'<=\', \$this->to\)/')
        ->and($scope)->toMatch('/whereBetween\(\'punch_time\', \[\$this->windowStart\(\), \$this->windowEnd\(\)\]\)/', 'punch_time is a dateTime column, so its bounds must carry the time of day');
});

test('no date column anywhere is compared against a time-suffixed bound', function (): void {
    // Columns declared `$table->date(...)` in a module migration. Text comparison makes `'Y-m-d'` smaller
    // than `'Y-m-d 00:00:00'`, so appending a time to the bound silently excludes the range's first day.
    $dateColumns = [];
    $ambiguous = [];

    foreach (File::allFiles(base_path('modules')) as $migration) {
        if ($migration->getExtension() !== 'php' || ! str_contains($migration->getPathname(), 'Migrations')) {
            continue;
        }

        $src = (string) file_get_contents($migration->getPathname());

        if (preg_match_all('/\$table->date\(\'([a-z0-9_]+)\'\)/', $src, $m)) {
            foreach ($m[1] as $column) {
                $dateColumns[$column] = true;
            }
        }

        // A name used as both `date` and `dateTime` across tables cannot be judged by name alone, so the
        // guard leaves it to a test that knows the table.
        if (preg_match_all('/\$table->(?:dateTime|timestamp)\(?:Tz\)?\(\'([a-z0-9_]+)\'\)/', $src, $m)) {
            foreach ($m[1] as $column) {
                $ambiguous[$column] = true;
            }
        }
    }

    $dateColumns = array_diff(array_keys($dateColumns), array_keys($ambiguous));

    $offences = [];

    foreach (archSourceFiles() as $path) {
        if ($path === archScopeOwner()) {
            continue;
        }

        foreach (archCodeLines($path) as [$line, $text]) {
            if (! str_contains($text, ' 00:00:00') && ! str_contains($text, ' 23:59:59')) {
                continue;
            }

            foreach ($dateColumns as $column) {
                if (preg_match('/whereBetween\(\s*[\'"]'.$column.'[\'"]/', $text)
                    || preg_match('/where\(\s*[\'"]'.$column.'[\'"]\s*,\s*[\'"][<>]=?[\'"]/', $text)) {
                    $offences[] = basename($path).':'.$line.' '.$column;
                }
            }
        }
    }

    expect($offences)->toBe([], "date columns must be bounded with whereDate, not a string range with a time suffix (AttendanceRepairScope is the owner and is exempt):\n".implode("\n", $offences));
});

test('no dateTime column is bounded by bare dates', function (): void {
    // The mirror-image mistake: `'2026-10-31 07:00:00' > '2026-10-31'`, so a `dateTime` column bounded by
    // two bare dates loses everything after midnight on the range's last day. `startOfDay()`/`endOfDay()`
    // (or an explicit time in the bound) is the correct form.
    $offences = [];

    foreach (archSourceFiles() as $path) {
        if ($path === archScopeOwner()) {
            continue;
        }

        foreach (archCodeLines($path) as [$line, $text]) {
            if (! preg_match("/whereBetween\(\s*'punch_time'\s*,\s*\[([^\]]*)\]/", $text, $m)) {
                continue;
            }

            $bounds = $m[1];

            if (! str_contains($bounds, 'startOfDay') && ! str_contains($bounds, 'endOfDay')
                && ! str_contains($bounds, '00:00:00') && ! str_contains($bounds, '23:59:59')) {
                $offences[] = basename($path).':'.$line.' '.$bounds;
            }
        }
    }

    expect($offences)->toBe([], "punch_time is a dateTime column; bound it with startOfDay()/endOfDay() or explicit times:\n".implode("\n", $offences));
});
