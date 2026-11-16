<?php

namespace Modules\PAYROLL\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The reads behind the cutlist review module.
 *
 * The cutlist is payroll's *review copy* of the month: the same employee rows the salary sheet will
 * consume, before they become a payslip. That makes its figures different in kind from the sheet's —
 * they are a proposal, and the whole point of the desk is to find the rows that are wrong before they
 * are paid.
 *
 * Three things this service exists to make visible, because the screen it replaces could not show
 * any of them:
 *
 *  - **A punishment day is not a deduction.** The cutlist stores `punishment_days` for anybody HRM
 *    says had late or early-out days, but only an employee whose HRM policy is `is_punishable` is
 *    actually charged. On this month's data 469 employees carry punishment days and 4 are chargeable,
 *    so a table that puts "8 punishment days" next to "0.00" reads like a bug. It is not a bug; it is
 *    two different facts in two columns with nothing explaining the relation.
 *  - **Warnings are not money.** `approved_overtime_minutes` and `missing_punch_count` are attendance
 *    facts. Only `overtime_pay` and `*_deduction` reach a payslip. Showing attendance counts beside
 *    amounts without saying which is which invites somebody to add the wrong pair.
 *  - **A sign-off covers a specific set of rows.** Counts here are computed from the items, so a
 *    cutlist that changes under a reviewer moves the numbers on the approval screen with it.
 */
final class PayrollCutlistWorkspaceService
{
    /** The columns a reviewer may override; each is money the calculation engine actually reads. */
    public const MONEY_COLUMNS = [
        'unpaid_leave_deduction' => 'Unpaid leave deduction',
        'late_penalty_deduction' => 'Punishment deduction',
        'missing_punch_deduction' => 'Missing-punch deduction',
        'overtime_pay' => 'Overtime pay',
        'extra_duty_pay' => 'Extra duty pay',
    ];

    /** The date part of a raw column, whether it arrives as a string or a Carbon instance. */
    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return substr((string) $value, 0, 10);
    }

    public function label(int $year, int $month): string
    {
        return CarbonImmutable::create($year, $month, 1)?->format('F Y') ?? sprintf('%04d-%02d', $year, $month);
    }

    /**
     * Every cutlist, with what it is worth and whether it is waiting for somebody.
     *
     * `at_stake` is the number a reviewer needs: the money that this month's review can still change.
     * It is deductions plus earnings, because both move when a row is adjusted and both end up on the
     * sheet.
     */
    public function overview(): array
    {
        $cutlists = DB::table('payroll_cutlists')->orderByDesc('pay_year')->orderByDesc('pay_month')->get();

        $sheets = DB::table('payroll_salary_sheets')
            ->whereIn('payroll_cutlist_id', $cutlists->pluck('id')->all() ?: [0])
            ->get(['id', 'payroll_cutlist_id', 'status'])
            ->keyBy('payroll_cutlist_id');

        $rows = $cutlists->map(function (object $cutlist) use ($sheets): array {
            $agg = $this->aggregate((int) $cutlist->id);
            $sheet = $sheets->get($cutlist->id);

            return [
                'id' => (int) $cutlist->id,
                'pay_year' => (int) $cutlist->pay_year,
                'pay_month' => (int) $cutlist->pay_month,
                'period' => $this->label((int) $cutlist->pay_year, (int) $cutlist->pay_month),
                'status' => (string) $cutlist->status,
                // `DB::table()` hands back the raw column, not a Carbon instance — the model does the
                // casting, and this service deliberately does not load models for a list query.
                'generated_at' => $this->date($cutlist->generated_at),
                'approved_at' => $this->date($cutlist->approved_at),
                'approval_note' => $cutlist->approval_note,
                'items' => $agg['items'],
                'deductions' => $agg['deductions'],
                'earnings' => $agg['earnings'],
                'at_stake' => round($agg['deductions'] + $agg['earnings'], 2),
                'chargeable' => $agg['chargeable'],
                'flagged' => $agg['flagged'],
                'adjustments' => $agg['adjustments'],
                'sheet' => $sheet ? ['id' => (int) $sheet->id, 'status' => (string) $sheet->status] : null,
                // A cutlist whose sheet has been computed can no longer be edited; that is a fact
                // about the row, not a permission, so both the list and the desk can show it.
                'consumed' => $sheet !== null,
                'awaiting_signoff' => (string) $cutlist->status === 'generated',
            ];
        });

        $awaiting = $rows->where('awaiting_signoff', true);

        return [
            'rows' => $rows->values()->all(),
            'counts' => [
                'cutlists' => $rows->count(),
                'generated' => $rows->where('status', 'generated')->count(),
                'approved' => $rows->where('status', 'approved')->count(),
                'locked' => $rows->where('status', 'locked')->count(),
            ],
            'totals' => [
                'employees' => (int) $rows->max('items'),
                'deductions' => round((float) $rows->sum('deductions'), 2),
                'earnings' => round((float) $rows->sum('earnings'), 2),
                'at_stake' => round((float) $awaiting->sum('at_stake'), 2),
                'awaiting' => $awaiting->count(),
                'adjustments' => (int) $rows->sum('adjustments'),
                'chargeable' => (int) $rows->sum('chargeable'),
            ],
            'latest' => $rows->first(),
        ];
    }

    /** Totals computed from the item rows, never from a stored summary — there isn't one. */
    private function aggregate(int $cutlistId): array
    {
        $row = DB::table('payroll_cutlist_items')->where('payroll_cutlist_id', $cutlistId)
            ->selectRaw('
                COUNT(*) as items,
                COALESCE(SUM(unpaid_leave_deduction),0) + COALESCE(SUM(late_penalty_deduction),0) + COALESCE(SUM(missing_punch_deduction),0) as deductions,
                COALESCE(SUM(overtime_pay),0) + COALESCE(SUM(extra_duty_pay),0) as earnings,
                COALESCE(SUM(late_penalty_deduction),0) as penalty,
                -- `chargeable` is the count of employees the policy makes punishable; it is *not* a
                -- subset of `punishment_days > 0`, which only counts people who actually had late days.
                -- The page printed "4 of 0 with attendance days recorded" because it used the
                -- punishable count as the numerator over the late-day count.
                SUM(CASE WHEN punishable = 1 THEN 1 ELSE 0 END) as chargeable,
                SUM(CASE WHEN punishment_days > 0 THEN 1 ELSE 0 END) as flagged,
                -- "With attendance days recorded" is any row that carries at least one piece of
                -- attendance evidence; the table has no attendance_days column, so the count is built
                -- from the counters that do exist rather than from a column that does not.
                SUM(CASE WHEN COALESCE(late_count,0) > 0
                          OR COALESCE(early_out_count,0) > 0
                          OR COALESCE(missing_punch_count,0) > 0
                          OR COALESCE(unpaid_leave_days,0) > 0
                          OR COALESCE(approved_overtime_minutes,0) > 0
                          OR COALESCE(approved_extra_duty_minutes,0) > 0
                     THEN 1 ELSE 0 END) as with_attendance,
                SUM(CASE WHEN approved_overtime_minutes > 0 OR approved_extra_duty_minutes > 0 THEN 1 ELSE 0 END) as with_ot
            ')
            ->first();

        $adjustments = DB::table('payroll_cutlist_items')->where('payroll_cutlist_id', $cutlistId)
            ->whereNotNull('source_breakdown')
            ->whereRaw('CAST(source_breakdown AS TEXT) LIKE ?', ['%"review"%'])
            ->count();

        return [
            'items' => (int) ($row->items ?? 0),
            'deductions' => round((float) ($row->deductions ?? 0), 2),
            'earnings' => round((float) ($row->earnings ?? 0), 2),
            'penalty' => round((float) ($row->penalty ?? 0), 2),
            'chargeable' => (int) ($row->chargeable ?? 0),
            'flagged' => (int) ($row->flagged ?? 0),
            'with_attendance' => (int) ($row->with_attendance ?? 0),
            'with_ot' => (int) ($row->with_ot ?? 0),
            'adjustments' => $adjustments,
        ];
    }

    /**
     * Everything the review desk needs about one month.
     *
     * `segments` are the answers a reviewer actually works through, in the order they matter: rows
     * where money moves, rows where attendance is flagged but no money moves (the confusing ones),
     * and rows somebody has already changed.
     */
    public function context(int $cutlistId): array
    {
        $cutlist = DB::table('payroll_cutlists')->find($cutlistId);
        if ($cutlist === null) {
            return [];
        }

        $agg = $this->aggregate($cutlistId);
        $sheet = DB::table('payroll_salary_sheets')->where('payroll_cutlist_id', $cutlistId)->first();
        $settings = DB::table('payroll_settings')->first();

        $period = $this->label((int) $cutlist->pay_year, (int) $cutlist->pay_month);

        $segments = [
            ['key' => 'all', 'label' => 'All rows', 'count' => $agg['items'], 'hint' => 'every employee in the month'],
            [
                'key' => 'money',
                'label' => 'Money moves',
                'count' => DB::table('payroll_cutlist_items')->where('payroll_cutlist_id', $cutlistId)
                    ->where(fn ($q) => $q->where('unpaid_leave_deduction', '>', 0)->orWhere('late_penalty_deduction', '>', 0)
                        ->orWhere('missing_punch_deduction', '>', 0)->orWhere('overtime_pay', '>', 0)->orWhere('extra_duty_pay', '>', 0))->count(),
                'hint' => 'rows that change what the payslip pays',
            ],
            [
                'key' => 'flagged',
                'label' => 'Flagged, not charged',
                'count' => DB::table('payroll_cutlist_items')->where('payroll_cutlist_id', $cutlistId)
                    ->where('punishment_days', '>', 0)->where('punishable', 0)
                    ->where(fn ($q) => $q->where('late_penalty_deduction', 0)->orWhereNull('late_penalty_deduction'))->count(),
                'hint' => 'attendance days recorded, but the HRM policy does not charge them',
            ],
            [
                'key' => 'adjusted',
                'label' => 'Adjusted by a reviewer',
                'count' => $agg['adjustments'],
                'hint' => 'rows somebody has already overridden, with a reason',
            ],
        ];

        return [
            'cutlist' => $cutlist,
            'period' => $period,
            'aggregate' => $agg,
            'segments' => $segments,
            'sheet' => $sheet ? ['id' => (int) $sheet->id, 'status' => (string) $sheet->status] : null,
            'consumed' => $sheet !== null,
            'review_required' => (bool) ($settings->cutlist_review_required ?? false),
            'editable' => (string) $cutlist->status === 'generated' && $sheet === null,
            'adjustments' => $this->recentAdjustments($cutlistId),
        ];
    }

    /**
     * The reviewer's trail, newest first, across the whole month.
     *
     * Kept separate from the rows because the question "what changed on this month" is not answerable
     * from a paginated table — the three changed rows can be on pages 3, 17 and 39.
     */
    public function recentAdjustments(int $cutlistId, int $limit = 50): array
    {
        $out = [];
        DB::table('payroll_cutlist_items')->where('payroll_cutlist_id', $cutlistId)
            ->whereNotNull('source_breakdown')
            ->whereRaw('CAST(source_breakdown AS TEXT) LIKE ?', ['%"review"%'])
            ->select('employee_id', 'employee_name', 'source_breakdown')
            ->orderBy('id')->chunk(300, function (Collection $items) use (&$out): void {
                foreach ($items as $item) {
                    $decoded = json_decode((string) $item->source_breakdown, true);
                    foreach (($decoded['review'] ?? []) as $entry) {
                        $out[] = [
                            'employee_id' => (string) $item->employee_id,
                            'employee_name' => (string) $item->employee_name,
                            'at' => $entry['at'] ?? null,
                            'name' => $entry['name'] ?? null,
                            'reason' => $entry['reason'] ?? null,
                            'changes' => collect($entry['changes'] ?? [])->map(fn ($c, $field) => [
                                'field' => self::MONEY_COLUMNS[$field] ?? $field,
                                'from' => (float) ($c['from'] ?? 0),
                                'to' => (float) ($c['to'] ?? 0),
                            ])->values()->all(),
                        ];
                    }
                }
            });

        usort($out, fn (array $a, array $b) => strcmp((string) $b['at'], (string) $a['at']));

        return array_slice($out, 0, $limit);
    }

    /**
     * Why one row says what it says.
     *
     * This is the piece the old table lacked entirely. Every number on a cutlist row has a reason —
     * a salary day rate, a policy flag, a set of dates — and the reason is already stored in
     * `source_breakdown` on every item. It was previously visible only as raw JSON in a `<pre>` tag.
     */
    public function explain(object $item): array
    {
        // `PayrollCutlistItem` casts this column to an array, so it arrives decoded when the caller
        // passes a model and as a JSON string when it passes a query row. Both are accepted here —
        // the alternative is a caller that breaks depending on how it fetched the row.
        $raw = $item->source_breakdown ?? null;
        $source = is_array($raw) ? $raw : (is_object($raw) ? (array) $raw : json_decode((string) $raw, true));
        $source = is_array($source) ? $source : [];
        $calc = $source['calculation_details'] ?? [];

        $punishment = $calc['punishment'] ?? [];
        $overtime = $calc['overtime'] ?? [];
        $extra = $calc['extra_duty'] ?? [];

        $lines = [];

        $punishable = (bool) ($source['punishable'] ?? $item->punishable ?? false);
        $days = (int) ($item->punishment_days ?? 0);

        if ($days > 0 && $punishable) {
            $lines[] = [
                'tone' => 'danger',
                'text' => sprintf(
                    '%d punishment day(s) charged at 3 salary days each: rate %s × %d = %s.',
                    $days,
                    number_format((float) ($punishment['salary_day_rate'] ?? 0), 2),
                    (int) ($punishment['days_per_salary_day'] ?? 3),
                    number_format((float) ($item->late_penalty_deduction ?? 0), 2),
                ),
            ];
        } elseif ($days > 0) {
            $lines[] = [
                'tone' => 'muted',
                'text' => sprintf(
                    '%d late/early-out day(s) recorded, but this employee\'s HRM policy is not punishable, so nothing is deducted. The days are kept so the attendance record stays intact.',
                    $days,
                ),
            ];
        } else {
            $lines[] = ['tone' => 'muted', 'text' => 'No late or early-out days recorded.'];
        }

        if ((int) ($item->missing_punch_count ?? 0) > 0) {
            $lines[] = [
                'tone' => 'muted',
                'text' => sprintf(
                    '%d missing punch(es) recorded; they are a warning, not a deduction (%s withheld).',
                    (int) $item->missing_punch_count,
                    number_format((float) ($item->missing_punch_deduction ?? 0), 2),
                ),
            ];
        }

        if ((int) ($item->unpaid_leave_days ?? 0) > 0 || (float) ($item->unpaid_leave_deduction ?? 0) > 0) {
            $lines[] = [
                'tone' => 'warn',
                'text' => sprintf(
                    '%s unpaid leave day(s) deducted at %s.',
                    number_format((float) $item->unpaid_leave_days, 2),
                    number_format((float) ($item->unpaid_leave_deduction ?? 0), 2),
                ),
            ];
        }

        $otMinutes = (int) ($item->approved_overtime_minutes ?? 0);
        if ($otMinutes > 0) {
            $lines[] = [
                'tone' => 'ok',
                'text' => sprintf(
                    '%.2f h approved overtime paid at %s/h × %s = %s.',
                    $otMinutes / 60,
                    number_format((float) ($overtime['hourly_basic'] ?? 0), 2),
                    $overtime['multiplier'] ?? 1,
                    number_format((float) ($item->overtime_pay ?? 0), 2),
                ),
            ];
        }

        $edMinutes = (int) ($item->approved_extra_duty_minutes ?? 0);
        if ($edMinutes > 0) {
            $lines[] = [
                'tone' => 'ok',
                'text' => sprintf(
                    '%.2f h approved extra duty paid at %s/h × %s = %s.',
                    $edMinutes / 60,
                    number_format((float) ($extra['hourly_basic'] ?? 0), 2),
                    $extra['multiplier'] ?? 1,
                    number_format((float) ($item->extra_duty_pay ?? 0), 2),
                ),
            ];
        }

        return [
            'lines' => $lines,
            'dates' => [
                'late' => array_slice($source['late_dates'] ?? [], 0, 12),
                'early_out' => array_slice($source['early_out_dates'] ?? [], 0, 12),
                'missing_punch' => array_slice($source['missing_punch_dates'] ?? [], 0, 12),
            ],
            'attendance_rows' => (int) ($source['attendance_daily_rows'] ?? 0),
            'duty_hours' => (float) ($source['duty_hours'] ?? 0),
            'adjustments' => $source['review'] ?? [],
        ];
    }

    /**
     * The sign-off checklist.
     *
     * Blocking means the approval would be false: a cutlist with no rows signs off nothing, and one
     * already consumed by a salary sheet signs off figures that payroll has already paid. Advisory
     * means somebody should look but the month is not wrong — unadjusted punished rows, or a rebuild
     * that wiped an earlier review.
     */
    public function readiness(int $cutlistId): array
    {
        $ctx = $this->context($cutlistId);
        if ($ctx === []) {
            return [];
        }

        $cutlist = $ctx['cutlist'];
        $agg = $ctx['aggregate'];
        $checks = [];

        $checks[] = [
            'key' => 'rows',
            'label' => 'The cutlist has rows',
            'ok' => $agg['items'] > 0,
            'blocking' => true,
            'detail' => $agg['items'] > 0
                ? number_format($agg['items']).' employee row(s) generated from HRM attendance'
                : 'Nothing was generated for this month, so there is nothing to sign off',
        ];

        $checks[] = [
            'key' => 'status',
            'label' => 'It is under review, not already signed',
            'ok' => (string) $cutlist->status === 'generated',
            'blocking' => true,
            'detail' => (string) $cutlist->status === 'generated'
                ? 'Status is generated — awaiting a reviewer'
                : 'Status is '.$cutlist->status.', so there is no sign-off to give',
        ];

        $checks[] = [
            'key' => 'consumed',
            'label' => 'No salary sheet has consumed it',
            'ok' => $ctx['sheet'] === null,
            'blocking' => true,
            'detail' => $ctx['sheet'] === null
                ? 'No sheet has been computed from these rows yet'
                : sprintf('Sheet #%d (%s) was computed from this cutlist; its figures are already being paid', $ctx['sheet']['id'], $ctx['sheet']['status']),
        ];

        $checks[] = [
            'key' => 'charges',
            'label' => 'Punishment charges were reviewed',
            // Always advisory: charging nobody is a legitimate outcome when nobody is punishable.
            'ok' => true,
            'blocking' => false,
            'detail' => $agg['chargeable'] > 0
                ? sprintf('%d employee(s) are chargeable; %s withheld in punishment deductions', $agg['chargeable'], number_format($agg['penalty'], 2))
                : sprintf('%d employee(s) recorded punishment days and none is chargeable — check the HRM policy if that is unexpected', $agg['flagged']),
        ];

        $checks[] = [
            'key' => 'adjustments',
            'label' => 'Reviewer adjustments',
            'ok' => true,
            'blocking' => false,
            'detail' => $agg['adjustments'] > 0
                ? sprintf('%d row(s) were overridden by a reviewer; each carries a reason', $agg['adjustments'])
                : 'No row was overridden; the proposal is exactly what HRM produced',
        ];

        $checks[] = [
            'key' => 'gate',
            'label' => 'Payroll gate',
            'ok' => true,
            'blocking' => false,
            'detail' => $ctx['review_required']
                ? 'Payroll settings require an approved cutlist before a salary sheet can be queued'
                : 'Payroll settings do not require this sign-off — the sheet can be queued without it',
        ];

        return [
            'checks' => $checks,
            'blocking_failures' => collect($checks)->where('blocking', true)->where('ok', false)->count(),
            'status' => (string) $cutlist->status,
        ];
    }
}
