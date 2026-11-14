<?php

namespace Modules\HRM\Services\Attendance;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Modules\HRM\Interfaces\Attendance\AttendanceRepairRepositoryInterface;
use Modules\HRM\Jobs\FixAttendancePairingJob;
use Modules\HRM\Models\Attendance\AttendancePair;
use Modules\HRM\Support\Attendance\AttendanceRepairScope;

/**
 * A scoped attendance rebuild, run as one pipeline-shaped operation.
 *
 * `AttendanceFixController` used to do the whole thing inline in the HTTP request: clear pairs and dailies
 * in the range, replay the pairing stage, recalculate, reconcile. Two things went wrong with that.
 *
 *  1. **The request could die mid-repair.** The clear was committed in its own transaction and the rebuild
 *     ran afterwards, synchronously, with no time limit relief — a department-sized repair runs for
 *     minutes. PHP-FPM/nginx cut the request, the rebuild never happened, and the operator was left with a
 *     month whose punch-less days (Off Day / Holiday / On Leave) had simply vanished: "attendance is 28 to
 *     31 rows a month, after Fix only the days with punches remain". The work now lives in
 *     {@see FixAttendancePairingJob} and the page polls a status key.
 *  2. **A rebuild can only rebuild what it can derive.** Anything the range's own evidence does not
 *     reproduce — a day past the reconciler's "finalise up to yesterday" line, a calendar row for an
 *     employee the reconciler does not walk, a row whose status came from a correction rather than the
 *     roster — was deleted and never came back. So the range is now clamped to what can be re-derived,
 *     and every punch-less calendar row that is still missing afterwards is restored verbatim from the
 *     snapshot taken before the clear.
 *
 * Deliberate: the service touches no session, no flash and no HTTP concern, so the job, the console and
 * the tests all exercise the same path the page uses. It also writes no SQL: the range is an
 * {@see AttendanceRepairScope} and the reads and writes are an
 * {@see AttendanceRepairRepositoryInterface}, which is what keeps "which rows are in scope" a single
 * definition shared with the bulk persistence, where a month of `duty_date` comparisons was lost by having
 * it twice.
 */
final class AttendanceRepairService
{
    /** Cross-process mailbox for the Fix page: written by the job, read by `AttendanceFixController::status()`. */
    public const STATUS_KEY = 'hrm:atten***:status';

    /** The statuses that carry no punch evidence and are therefore at risk from a rebuild. */
    private const CALENDAR_STATUSES = ['Off Day', 'Holiday', 'On Leave'];

    /** What the snapshot stamps on a day it had to put back, so the row explains itself in the UI. */
    private const KEPT_REMARK = '[kept by pairing repair: the rebuild could not derive this day]';

    public function __construct(
        private readonly AttendanceProcessingService $processor,
        private readonly AttendanceDayReconciliationService $reconciler,
        private readonly AttendanceRepairRepositoryInterface $rows,
    ) {}

    /**
     * Last day a repair may clear, because it is the last day the reconciler can rebuild (it never
     * finalises today — the nightly run owns it). Deleting further than this is how a Fix used to eat
     * rows permanently.
     */
    public function lastRepairableDate(): Carbon
    {
        return now()->subDay()->startOfDay();
    }

    /**
     * @param  array<int, string>|null  $employeeIds  null = every employee
     * @param  (callable(string, array<string, mixed>): void)|null  $onProgress
     * @return array<string, mixed>
     */
    public function repair(string $from, string $to, ?array $employeeIds = null, ?callable $onProgress = null): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $requestedEnd = Carbon::parse($to)->startOfDay();
        $ceiling = $this->lastRepairableDate();
        $end = $requestedEnd->min($ceiling);

        if ($start->gt($end)) {
            return [
                'ok' => false,
                'reason' => 'nothing_finalisable',
                'from' => $start->toDateString(),
                'to' => $requestedEnd->toDateString(),
                'ceiling' => $ceiling->toDateString(),
                'message' => 'Nothing to repair: a rebuild only finalises days up to yesterday ('.$ceiling->toDateString().'), and this range starts after that. Today and future days belong to the nightly pipeline.',
            ];
        }

        $fromDate = $start->toDateString();
        $toDate = $end->toDateString();
        $clipped = $requestedEnd->gt($end);
        $scope = new AttendanceRepairScope($fromDate, $toDate, $employeeIds);
        $report = function (string $step, array $extra = []) use ($onProgress, $fromDate, $toDate): void {
            $onProgress?->__invoke($step, array_merge(['from' => $fromDate, 'to' => $toDate], $extra));
        };

        // The safety net, taken BEFORE anything is deleted: every row in the range that no punch can
        // resurrect, with all of its columns. Two kinds: the calendar statuses the roster/leave/holiday
        // data *may* reproduce, and regularized days, which the reconciler never reproduces (its vocabulary
        // is Off Day / Holiday / On Leave / Absent) — an approved missing-punch correction with no punch on
        // it would otherwise be deleted and come back as plain Absent.
        $calendarRows = $this->rows->unreproducibleRows($scope, self::CALENDAR_STATUSES);

        $cleared = $this->rows->scopeCounts($scope);

        $report('clearing', $cleared);

        $this->rows->clear($scope);

        $report('replaying_pairs', $cleared);
        $reprocess = $this->processor->processPending(50000, $scope->windowStart(), $scope->windowEnd());

        $report('reconciling', ['processed' => $reprocess['processed'] ?? 0]);
        $recalculated = $this->processor->recalcForDateRange($fromDate, $toDate);
        $classified = $this->reconciler->reconcile($fromDate, $toDate);

        $report('restoring', ['reconciled' => array_sum($classified)]);
        $kept = $this->reinstateCalendarRows($calendarRows, $scope);

        $newPairs = $scope->pairs()
            ->orderBy('duty_date')->limit(50)->get()
            ->map(fn (AttendancePair $pair) => [
                'duty_date' => $pair->duty_date?->toDateString(),
                'punch_in' => $pair->punch_in?->format('d M H:i'),
                'punch_out' => $pair->punch_out?->format('d M H:i'),
                'hours' => $pair->worked_hours,
                'type' => $pair->duty_type,
            ]);

        $result = [
            'ok' => true,
            'from' => $fromDate,
            'to' => $toDate,
            'requested_to' => $requestedEnd->toDateString(),
            'clipped' => $clipped,
            'ceiling' => $ceiling->toDateString(),
            'cleared' => $cleared,
            'reprocess' => $reprocess,
            'recalculated' => $recalculated,
            'reconcile' => $classified,
            'restored' => $kept['restored'],
            'replaced' => $kept['replaced'],
            'calendar_rows_at_risk' => $calendarRows->count(),
            'new_pairs' => $newPairs->values()->all(),
        ];
        $result['message'] = self::summarise($result);

        return $result;
    }

    /** One human-readable line for both the queue status payload and the console. */
    public static function summarise(array $result): string
    {
        if (! ($result['ok'] ?? false)) {
            return (string) ($result['message'] ?? 'The repair did not run.');
        }

        $cleared = $result['cleared'] ?? [];
        $classified = $result['reconcile'] ?? [];
        $line = 'Repaired '.$result['from'].' through '.$result['to'].': '
            .($cleared['pairs'] ?? 0).' pairs and '.($cleared['dailies'] ?? 0).' daily rows rebuilt, '
            .($cleared['logs'] ?? 0).' logs replayed ('.($result['reprocess']['processed'] ?? 0).' processed), '
            .($result['recalculated'] ?? 0).' punch days recalculated. Roster calendar: '
            .($classified['off_day'] ?? 0).' off days, '.($classified['holiday'] ?? 0).' holidays, '
            .($classified['leave'] ?? 0).' leave days, '.($classified['absent'] ?? 0).' absent days.';

        $kept = (int) ($result['restored'] ?? 0) + (int) ($result['replaced'] ?? 0);

        if ($kept > 0) {
            $line .= ' Kept '.$kept.' calendar row(s) the rebuild could not derive ('
                .(int) ($result['restored'] ?? 0).' restored, '.(int) ($result['replaced'] ?? 0).' put back over the synthetic row).';
        }

        if ($result['clipped'] ?? false) {
            $line .= ' The range was cut short at '.$result['to'].' — today and later days finalise with the nightly pipeline.';
        }

        return $line;
    }

    /**
     * Decide what the rebuild owes the snapshot, and hand the rows to the store.
     *
     * Two outcomes per snapshotted row, and both are the guarantee the report asked for — "a month has 28
     * to 31 rows, and a Fix must not leave only the punched days":
     *
     *  - nothing exists for that employee-day afterwards  → the row is re-inserted exactly as it was
     *    (this is what a repair used to lose: a calendar day for an employee the reconciler does not walk,
     *    or a date it refuses to finalise);
     *  - the rebuild/reconciler wrote a **synthetic** row for it (punch-less `Absent`, the "no attendance
     *    logs" remarks the pipeline stamps) → the snapshot wins, because a day whose only prior evidence
     *    was an Off Day / Holiday / On Leave classification or an approved regularization must not be
     *    downgraded to Absent by a repair whose purpose was to fix a mis-paired punch.
     *
     * Anything the rebuild produced from real evidence (a punch, a shift, hours) is left alone: the repair
     * changes the days it can see, and nothing else. Which rows those are — and the columns each carries — is
     * the store's business, which is why this method returns attribute arrays instead of querying.
     *
     * @param  Collection<int, object>  $calendarRows
     * @return array{restored: int, replaced: int}
     */
    private function reinstateCalendarRows(Collection $calendarRows, AttendanceRepairScope $scope): array
    {
        if ($calendarRows->isEmpty()) {
            return ['restored' => 0, 'replaced' => 0];
        }

        $current = $this->rows->rowIndex($scope);
        $now = now()->toDateTimeString();
        $insert = [];
        $replace = [];

        foreach ($calendarRows as $row) {
            $date = substr((string) $row->duty_date, 0, 10);
            $attributes = (array) $row;
            unset($attributes['id'], $attributes['created_at'], $attributes['updated_at']);
            $attributes['remarks'] = trim((string) ($attributes['remarks'] ?? '')).' '.self::KEPT_REMARK;
            $attributes['updated_at'] = $now;

            $existing = $current[$row->employee_id.'|'.$date] ?? null;

            if ($existing === null) {
                $attributes['created_at'] = $now;
                $insert[] = $attributes;

                continue;
            }

            $synthetic = $existing->status === 'Absent'
                && $existing->primary_punch_in === null
                && $existing->primary_punch_out === null;

            if ($synthetic) {
                unset($attributes['employee_id'], $attributes['duty_date']);
                $replace[(int) $existing->id] = $attributes;
            }
        }

        return [
            'restored' => $insert === [] ? 0 : $this->rows->insertRows($insert),
            'replaced' => $replace === [] ? 0 : $this->rows->replaceRows($replace),
        ];
    }
}
