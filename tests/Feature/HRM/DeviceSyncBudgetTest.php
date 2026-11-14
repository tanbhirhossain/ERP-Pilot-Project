<?php

/**
 * A device sync must bound itself, not PHP.
 *
 * The reported symptom (2026-11-13): `php artisan queue:work` printed `SyncDeviceJob … RUNNING`, sat for
 * five minutes and died with "Maximum execution time of 300 seconds exceeded" inside
 * `HandleExceptions.php:261`. `SyncDeviceJob::handle()` does call `set_time_limit(0)` — and then
 * `AttendanceSyncService::syncDevice()` called `set_time_limit(300)`, un-doing it. On Windows that limit is
 * wall-clock, so a device with a large backlog was killed mid-transfer, the job was re-queued, and the next
 * worker start did it again (the log shows the same job RUNNING twice, 21 seconds apart).
 *
 * Two things made that fatal rather than slow, and both are pinned here:
 *
 *  1. The service set a *shorter* limit than the worker had asked for. Work-owning code must not manage the
 *     limit at all: the process that owns the loop owns the timeout.
 *  2. The ingest loop ran three queries per punch (Employee by biometric id, DeviceUser by device id, and an
 *     `exists()` duplicate check), so a 50 000-record first sync was ~150 000 statements. No time limit can
 *     rescue that, and 250s of self-imposed "processing guard" on top of an unbounded ingest loop added up to
 *     more than the 300s it had just armed.
 *
 * The fix is a budget (`hrm.sync.budget_seconds`) that stops the loop *between records* and marks the sync
 * `Partial` without advancing `last_sync_at`, plus set-based lookups. These tests cover all of it.
 */

use Tests\Paths;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Modules\HRM\Models\Attendance\AttendanceLog;
use Modules\HRM\Models\BiometricDevice;
use Modules\HRM\Models\DeviceUser;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Services\Attendance\AttendanceSyncService;
use Modules\HRM\Support\Attendance\DailyDateArgument;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Carbon::setTestNow();
});

function dsbDevice(): BiometricDevice
{
    // Unroutable address: the sync falls back to simulated punches, which is what the tests need it to read.
    return BiometricDevice::factory()->create([
        'ip_address' => '192.168.1.200',
        'port' => 4370,
        'is_enabled' => true,
        'auto_sync_enabled' => true,
        'status' => 'Active',
    ]);
}

/**
 * The punches the simulator produces are timestamped 09:05 and 18:02 today (and the day before), and the
 * default window is "last 24 hours" — so a test that runs at 06:00 would legitimately fetch nothing. Pin the
 * clock after both.
 */
function dsbTravelToEvening(): void
{
    test()->travelTo(Carbon::parse('2026-11-13 20:00:00'));
}

test('work-owning code never shortens PHP execution time', function (): void {
    // `set_time_limit(0)` is allowed (a worker or a cron command lifting its own cap); any other number in a
    // service or repository is the bug: it re-arms a shorter limit inside somebody else's process, and the
    // caller's `0` is gone by the time the first device read blocks.
    $codeLines = function (string $path): array {
        $out = [];
        $line = 1;

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $isComment = is_array($token) && in_array($token[0], [T_DOC_COMMENT, T_COMMENT], true);

            if (! $isComment) {
                foreach (explode("\n", $text) as $offset => $part) {
                    if (trim($part) !== '') {
                        $out[] = [$line + $offset, $part];
                    }
                }
            }

            $line += substr_count($text, "\n");
        }

        return $out;
    };

    $offences = [];

    foreach (['modules/HRM/Services', 'modules/HRM/Repositories', 'modules/HRM/Support'] as $dir) {
        foreach (File::allFiles(base_path($dir)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            foreach ($codeLines($file->getPathname()) as [$line, $text]) {
                if (preg_match('/set_time_limit\(\s*(?!0\)\s*;?\s*$)\d/', $text) || preg_match("/ini_set\(\s*'max_execution_time'\s*,\s*'(?!0')/", $text)) {
                    $offences[] = Paths::relativePath($file->getPathname()).':'.$line.' '.trim($text);
                }
            }
        }
    }

    expect($offences)->toBe([], "a service or repository must not manage the caller's PHP time limit:\n".implode("\n", $offences));
});

test('a sync that runs out of budget stops between records and leaves the rest for the next run', function (): void {
    dsbTravelToEvening();
    $device = dsbDevice();

    $result = app(AttendanceSyncService::class)->syncDevice($device, null, null, 0);

    expect($result['ok'])->toBeTrue()
        ->and($result['partial'])->toBeTrue()
        ->and($result['inserted'])->toBe(0)
        ->and($result['fetched'])->toBeGreaterThan(0)
        ->and($result['message'])->toContain('PARTIAL')
        // The point of the whole exercise: an unfinished sync must not move the incremental window past the
        // punches it never read, or the next run skips them for ever.
        ->and($device->refresh()->last_sync_at)->toBeNull()
        ->and(DB::table('attendance_logs')->where('device_id', $device->id)->count())->toBe(0);

    $log = DB::table('attendance_sync_logs')->where('device_id', $device->id)->orderByDesc('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->status)->toBe('Partial');
});

test('the ingest resolves employees and duplicates as sets, not per punch', function (): void {
    dsbTravelToEvening();
    $device = dsbDevice();
    $mapped = Employee::factory()->create(['biometric_id' => '101']);
    $other = Employee::factory()->create();
    DeviceUser::query()->create(['device_id' => $device->id, 'device_user_id' => '102', 'uid' => '2', 'name' => 'Rimon Mia', 'employee_id' => $other->employee_id]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $result = app(AttendanceSyncService::class)->syncDevice($device);
    $queries = implode("\n", array_map(fn (array $q) => $q['query'], DB::getQueryLog()));
    DB::disableQueryLog();

    $fetched = (int) $result['fetched'];

    // Assert the *shapes*, not a raw query count: `select exists(` is legitimately emitted elsewhere in a
    // sync (`updateOrInsert` in the reconciler compiles to one), so counting it would pin unrelated code.
    $perRowLogCheck = preg_match_all('/select\s+exists\s*\(.*?"attendance_logs".*?"punch_time"\s*=\s*\?/is', $queries);
    $perRowDeviceUserLookup = preg_match_all('/from\s+"device_users"\s+where\s+\(?\s*"device_user_id"\s*=\s*\?/i', $queries);
    $oneSetReadOfExistingLogs = preg_match_all('/from\s+"attendance_logs"\s+where\s+"device_id"\s*=\s*\?\s+and\s+"device_user_id"\s+in\s+\(/is', $queries);

    expect($fetched)->toBeGreaterThan(0)
        // The three per-record queries are gone; one set read per concern instead.
        ->and($perRowLogCheck)->toBe(0, 'the duplicate check was one query per punch')
        ->and($perRowDeviceUserLookup)->toBe(0, 'the device-user mapping was one query per punch')
        ->and(substr_count($queries, '"biometric_id" = ?'))->toBe(0)
        ->and($oneSetReadOfExistingLogs)->toBe(1, 'exactly one read of the punches this device already has, for all ids at once')
        ->and(substr_count($queries, '"biometric_id" in ('))->toBe(1)
        // twice, by design: the `device_users` mapping read and the existing-punches read both resolve the
        // same id set in one statement each.
        ->and(substr_count($queries, '"device_user_id" in ('))->toBe(2)
        // …and the mapping still resolves: one device id via employees.biometric_id, the other via device_users.
        ->and(AttendanceLog::query()->where('device_id', $device->id)->where('employee_id', $mapped->employee_id)->count())
        ->toBeGreaterThan(0)
        ->and(AttendanceLog::query()->where('device_id', $device->id)->where('employee_id', $other->employee_id)->count())
        ->toBeGreaterThan(0);

    // A second sync of the same window is a no-op — which is what the old per-row `exists()` guaranteed.
    $again = app(AttendanceSyncService::class)->syncDevice($device);
    $rows = AttendanceLog::query()->where('device_id', $device->id)->count();

    expect($again['inserted'])->toBe(0)
        ->and($again['skipped_duplicate'])->toBe($again['fetched'])
        ->and($again['partial'])->toBeFalse()
        ->and(AttendanceLog::query()->where('device_id', $device->id)->count())->toBe($rows)
        ->and($device->refresh()->last_sync_at)->not->toBeNull();
});

test('the raw stage honours the same budget', function (): void {
    dsbTravelToEvening();
    $device = dsbDevice();

    $result = app(AttendanceSyncService::class)->syncDeviceRawOnly($device, null, null, 0);

    expect($result['ok'])->toBeTrue()
        ->and($result['partial'])->toBeTrue()
        ->and($result['inserted'])->toBe(0)
        ->and($device->refresh()->last_sync_at)->toBeNull()
        ->and($result['message'])->toContain('PARTIAL');
});

it('refuses a --from that is not a day, before it touches a device', function (): void {
    dsbDevice();

    expect(DB::table('attendance_sync_logs')->count())->toBe(0);

    // `--from=2026` used to reach Carbon inside the ZK fetch, throw there, get caught by the sync's own
    // try/catch, and come back as `Stage1 Raw FAIL` on every device with the reason nowhere on screen.
    $this->artisan('hrm:pipeline', ['--from' => '2026'])
        ->assertExitCode(1)
        ->expectsOutputToContain('is not a day');

    expect(DB::table('attendance_sync_logs')->count())->toBe(0);
});

it('accepts a real day, a time, and the words it has always taken', function (): void {
    expect(DailyDateArgument::parse('from', '2026-11-01'))->toMatchArray(['ok' => true, 'value' => '2026-11-01'])
        ->and(DailyDateArgument::parse('to', '2026-11-01 07:00'))->toMatchArray(['ok' => true])
        ->and(DailyDateArgument::parse('from', null))->toMatchArray(['ok' => true, 'value' => null])
        ->and(DailyDateArgument::resolve('from', 'yesterday'))->toMatchArray([
            'ok' => true, 'value' => Carbon::yesterday()->toDateString(),
        ])
        // A month or a year alone is not a range; guessing one would pull months of punches.
        ->and(DailyDateArgument::parse('from', '2026'))->toMatchArray(['ok' => false])
        ->and(DailyDateArgument::parse('from', '2026-11'))->toMatchArray(['ok' => false])
        // 31 February is a string of the right shape and a date that does not exist.
        ->and(DailyDateArgument::parse('from', '2026-02-31'))->toMatchArray(['ok' => false]);
});

/**
 * The fake returns what `syncDeviceRawOnly()`'s `catch` returns on a real failure: `ok` and `message` only, no
 * counters. The console used to fill the gaps with `?? 0`, so every hard failure read as "the device answered
 * and had nothing", and every non-OK line read as `PARTIAL`.
 */
function dsbFailingSync(array $stage1): AttendanceSyncService
{
    return new class($stage1) extends AttendanceSyncService
    {
        public function __construct(private array $stage1) {}

        public function syncDeviceRawOnly(BiometricDevice $device, ?string $fromDate = null, ?string $toDate = null, ?int $budgetSeconds = null): array
        {
            return $this->stage1;
        }
    };
}

it('prints the reason a stage failed, and calls a failure a failure', function (): void {
    $device = dsbDevice();

    $this->instance(AttendanceSyncService::class, dsbFailingSync([
        'ok' => false,
        'message' => 'Device 192.168.1.200:4370 unreachable - no punches read',
    ]));

    $this->artisan('hrm:pipeline', ['--device' => (string) $device->id])
        ->assertExitCode(1)
        ->expectsOutputToContain('Stage 1 reason: Device 192.168.1.200:4370 unreachable')
        ->expectsOutputToContain('FAILED')
        ->doesntExpectOutputToContain('PARTIAL')
        // No invented measurements: a stage that threw never counted anything.
        ->doesntExpectOutputToContain('"fetched":0');
});

it('reserves the word PARTIAL for a run that stopped at its budget', function (): void {
    $device = dsbDevice();

    $this->instance(AttendanceSyncService::class, dsbFailingSync([
        'ok' => false,
        'fetched' => 51000,
        'inserted' => 480,
        'partial' => true,
        'message' => 'PARTIAL: time budget reached, 50 520 records left for the next run',
    ]));

    $this->artisan('hrm:pipeline', ['--device' => (string) $device->id])
        ->assertExitCode(1)
        ->expectsOutputToContain('PARTIAL')
        ->expectsOutputToContain('"fetched":51000');
});

it('reads what the device already holds in one statement per 500 candidates, not per stored punch', function (): void {
    dsbTravelToEvening();
    $device = dsbDevice();

    // 6 000 punches already on the server for this device — the "the device has lots of logs" case.
    $stored = [];

    for ($i = 0; $i < 6000; $i++) {
        $stored[] = [
            'device_user_id' => (string) (900 + ($i % 40)),
            'punch_time' => sprintf('2026-11-%02d %02d:15:00', 1 + ($i % 12), $i % 24),
            'device_id' => $device->id,
            'punch_type' => 'AUTO',
            'is_processed' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    // 6 000 rows × 7 columns = 42 000 bound values, above SQLite's SQLITE_MAX_VARIABLE_NUMBER
    // (32 766 in the bundled build). A single `insert()` blew the limit in the fixture itself,
    // before the code under test ran — see TestCase::insertChunked().
    test()->insertChunked('attendance_logs', $stored);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $result = app(AttendanceSyncService::class)->syncDeviceRawOnly($device);
    $log = array_map(fn (array $q) => $q['query'], DB::getQueryLog());
    DB::disableQueryLog();

    $keyReads = array_values(array_filter(
        $log,
        fn (string $q) => str_contains($q, 'select "device_user_id", "punch_time" from "attendance_logs"')
    ));
    $inserts = array_values(array_filter($log, fn (string $q) => str_contains($q, 'insert into "attendance_logs"')));

    expect($result['ok'])->toBeTrue()
        ->and($result['partial'])->toBeFalse()
        // ONE read for the batch, bounded by the keys the device actually returned. The device-wide `get()`
        // it replaces was one statement too, but it materialised every stored punch as an Eloquent model:
        // on a busy F18 that is the 100 000-row collection that ate the sync's own budget.
        ->and($keyReads)->toHaveCount(1)
        ->and($keyReads[0])->toContain('"punch_time" in (')
        ->and($inserts)->toHaveCount(1)
        ->and(substr_count(implode("\n", $log), 'select exists(select * from "attendance_logs"'))->toBe(0)
        ->and((int) $result['inserted'])->toBeGreaterThan(0)
        ->and(AttendanceLog::query()->where('device_id', $device->id)->count())->toBe(6000 + (int) $result['inserted']);
});

it('names an unusable environment before the first device, not after three failures', function (): void {
    // The Windows report this pins: three devices, each `{"ok":false,"fetched":0,"inserted":0}` in a blink.
    // `ext-sockets` is disabled in a stock Windows php.ini, and `fetchViaJmrashed` checks for it as its very
    // first act — so the run never reached the network. The message has to arrive once, before the loop, and
    // say how to fix it, instead of three times as an empty result.
    config(['hrm.sync.required_extensions' => ['hrm-not-real-ext']]);

    $this->artisan('hrm:pipeline', ['--device' => '1'])
        ->assertExitCode(1)
        ->expectsOutputToContain('is not enabled')
        // The whole point: the run stopped here. It did not open a device, so it did not print a device's
        // empty summary and leave "the devices are full of logs" as the only story on screen.
        ->doesntExpectOutputToContain('Running pipeline for');

    // A stage that never opens a socket must not be gated by a socket extension.
    $this->artisan('hrm:pipeline', ['--stage' => 'daily'])->assertExitCode(0);
});

it('lists the extension the ZKTeCo client actually needs', function (): void {
    expect(config('hrm.sync.required_extensions'))->toBe(['sockets'])
        ->and(extension_loaded('sockets'))->toBeTrue();
});
