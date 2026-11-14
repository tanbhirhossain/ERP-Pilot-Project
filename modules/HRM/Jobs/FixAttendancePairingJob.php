<?php

namespace Modules\HRM\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\HRM\Services\Attendance\AttendanceRepairService;

/**
 * An attendance pairing repair, off the request.
 *
 * The repair is the longest-running write path in HRM: it clears and rebuilds every pair and daily row in
 * a scope, replays the pairing stage, recalculates and reconciles. In a month-sized department scope that is
 * minutes of work, which no web request should own — the previous synchronous version left the browser a
 * gateway timeout and the database a half-rebuilt month. This job runs the same
 * {@see AttendanceRepairService::repair()} and publishes its progress under
 * {@see AttendanceRepairService::STATUS_KEY} so the Fix page can poll it; the page and the queue worker are
 * different processes, so the status lives in the cache store (`database` in this app), not in memory.
 *
 * `tries = 1` is deliberate: a repair is not idempotent in the "safe to re-run blindly" sense (it clears a
 * range and re-derives it, and a second concurrent attempt would fight the first for the same rows).
 * ShouldBeUnique + WithoutOverlapping make that concrete — one repair at a time, and a crashed one frees
 * the lock after {@see self::$uniqueFor}.
 */
class FixAttendancePairingJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** A global month-sized repair is heavy; give it half an hour rather than let the worker kill it. */
    public int $timeout = 1800;

    /**
     * The worker re-reserves a job after `queue.connections.database.retry_after` (400s in this app), and a job
     * that is still legitimately running at that point is handed to a second worker while the first is mid
     * flight. So the reserve window for this job has to outlast its own ceiling — a month-sized repair clears and rebuilds ~31 000 rows, and a second concurrent attempt would fight it for the same rows.
     */
    public int $retryAfter = 1860;

    public int $uniqueFor = 3600;

    /**
     * @param  array<int, string>|null  $employeeIds  null = every employee in the range
     */
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly ?array $employeeIds = null,
        public readonly string $scopeLabel = 'all employees',
        public readonly ?int $requestedBy = null,
    ) {}

    public function uniqueId(): string
    {
        return 'attendance-pairing-fix';
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('attendance-pairing-fix'))
            ->expireAfter($this->timeout + 60)
            ->releaseAfter(60)];
    }

    public function handle(AttendanceRepairService $repair): void
    {
        @set_time_limit(0);

        $startedAt = now();
        $write = function (string $state, string $step, array $extra = []) use ($startedAt): void {
            Cache::put(AttendanceRepairService::STATUS_KEY, array_merge([
                'state' => $state,
                'step' => $step,
                'scope' => $this->scopeLabel,
                'from' => $this->from,
                'to' => $this->to,
                'requested_by' => $this->requestedBy,
                'started_at' => $startedAt->toIso8601String(),
                'updated_at' => now()->toIso8601String(),
            ], $extra), now()->addHour());
        };

        Log::info('[Job] FixAttendancePairingJob started', ['from' => $this->from, 'to' => $this->to, 'scope' => $this->scopeLabel]);
        $write('running', 'queued');

        $result = $repair->repair($this->from, $this->to, $this->employeeIds, function (string $step, array $extra) use ($write): void {
            $write('running', $step, $extra);
        });

        $write($result['ok'] ? 'done' : 'failed', (string) ($result['message'] ?? 'The repair did not run.'), ['result' => $result]);
        Log::info('[Job] FixAttendancePairingJob finished', $result);
    }

    /**
     * Called by the worker when the job dies. Two things must happen here, and the second one is easy to miss:
     * `ShouldBeUnique` holds its lock in the cache, and a job that fails without being released keeps it until
     * `uniqueFor` expires — so the operator's next click is silently swallowed while the page still reads
     * "Repair queued". Releasing it makes a dead repair retriable immediately and visibly.
     */
    public function failed(\Throwable $exception): void
    {
        (new UniqueLock(Cache::store()))->release($this);

        Cache::put(AttendanceRepairService::STATUS_KEY, [
            'state' => 'failed',
            'step' => 'The repair job stopped: '.$exception->getMessage(),
            'scope' => $this->scopeLabel,
            'from' => $this->from,
            'to' => $this->to,
            'updated_at' => now()->toIso8601String(),
            'error' => $exception->getMessage(),
        ], now()->addHour());

        Log::error('FixAttendancePairingJob failed', [
            'from' => $this->from,
            'to' => $this->to,
            'scope' => $this->scopeLabel,
            'error' => $exception->getMessage(),
        ]);
    }
}
