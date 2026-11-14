<?php

namespace Modules\HRM\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\HRM\Models\Attendance\AttendanceDaily;
use Modules\HRM\Models\AttendanceRegularization;
use Modules\PAYROLL\Models\PayrollCutlist;

class RegularizationService
{
    public function nextNo(): string
    {
        $last = AttendanceRegularization::withTrashed()->orderByDesc('id')->value('regularization_no');
        $n = $last ? (int) preg_replace('/\D/', '', $last) : 0;

        return 'REG-'.str_pad((string) ($n + 1), 5, '0', STR_PAD_LEFT);
    }

    public function request(array $data): AttendanceRegularization
    {
        return DB::transaction(function () use ($data) {
            // `whereDate`, and `withTrashed`, or the guard below never fires on SQLite: `duty_date` is a
            // `date` cast, which Eloquent writes as '2026-11-04 00:00:00', so comparing that column to the
            // bare '2026-11-04' from the form matched nothing. The duplicate then answered as a raw UNIQUE
            // constraint violation from `uniq_emp_date_type` — a 500 page on the second click of Submit.
            $existing = AttendanceRegularization::withTrashed()
                ->where('employee_id', $data['employee_id'])
                ->whereDate('duty_date', Carbon::parse($data['duty_date'])->toDateString())
                ->where('regularization_type', $data['regularization_type'])
                ->latest('id')
                ->first();

            if ($existing?->status === 'Pending' || $existing?->status === 'Approved') {
                throw new \DomainException('A pending/approved regularization already exists for this employee, date and type.');
            }

            if ($existing !== null) {
                // Rejected or Cancelled, so the employee is asking about the same day again. The unique
                // index says one row per (employee, date, type) may ever exist, so the old request is
                // reopened instead of a second one being inserted — the alternative is a permanent integrity
                // error for anyone whose first attempt was turned down. The earlier decision is replaced,
                // not appended to: once re-filed, this row no longer carries the previous remarks.
                $existing->restore();
                $existing->update([
                    'status' => 'Pending',
                    'assigned_shift_id' => $data['assigned_shift_id'] ?? null,
                    'requested_in_time' => $data['requested_in_time'] ?? null,
                    'requested_out_time' => $data['requested_out_time'] ?? null,
                    'reason' => $data['reason'],
                    'created_by' => auth()->id(),
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                    'review_remarks' => null,
                ]);

                return $existing->fresh();
            }

            return AttendanceRegularization::create([
                'regularization_no' => $this->nextNo(),
                'employee_id' => $data['employee_id'],
                'duty_date' => $data['duty_date'],
                'regularization_type' => $data['regularization_type'],
                'assigned_shift_id' => $data['assigned_shift_id'] ?? null,
                'requested_in_time' => $data['requested_in_time'] ?? null,
                'requested_out_time' => $data['requested_out_time'] ?? null,
                'reason' => $data['reason'],
                'created_by' => auth()->id(),
            ]);
        });
    }

    public function review(AttendanceRegularization $reg, string $decision, ?string $remarks = null): AttendanceRegularization
    {
        return DB::transaction(function () use ($reg, $decision, $remarks) {
            $locked = AttendanceRegularization::lockForUpdate()->findOrFail($reg->id);
            if (! $locked->isPending()) {
                throw new \DomainException('Only Pending can be reviewed.');
            }
            if (! in_array($decision, ['Approved', 'Rejected'], true)) {
                throw new \InvalidArgumentException('Invalid decision');
            }
            // Guard: warn if cutlist already generated for that month (payroll will need refresh)
            if ($decision === 'Approved') {
                $d = Carbon::parse($locked->duty_date);
                $cutlistExists = PayrollCutlist::where('pay_year', $d->year)->where('pay_month', $d->month)->whereIn('status', ['generated', 'approved'])->exists();
                if ($cutlistExists) {
                    Log::warning('Regularization approved after cutlist', ['reg_id' => $locked->id, 'duty_date' => $locked->duty_date, 'advice' => 'Refresh payroll cutlist']);
                }
            }
            $locked->update(['status' => $decision, 'reviewed_by' => auth()->id(), 'reviewed_at' => now(), 'review_remarks' => $remarks]);
            if ($decision === 'Approved') {
                // Patch attendance_dailies: mark regularized, update punch times if provided, recalc status
                $daily = AttendanceDaily::where('employee_id', $locked->employee_id)->whereDate('duty_date', $locked->duty_date)->first();
                if ($daily) {
                    $updates = ['is_regularized' => true, 'regularization_id' => $locked->id, 'remarks' => trim(($daily->remarks ? $daily->remarks.' | ' : '')."Regularized via {$locked->regularization_no}: {$locked->regularization_type}")];
                    // The type is a label, the times are the data. An approved request writes the times it
                    // carries, whatever label it was filed under — gating this on `Missing In`/`Missing Out`
                    // left a `Late Correction` or `Wrong Shift` approved on paper and untouched in the
                    // attendance record, which is exactly "regularization is not working".
                    $hadPunches = $daily->primary_punch_in !== null || $daily->primary_punch_out !== null;

                    if ($locked->requested_in_time) {
                        $updates['primary_punch_in'] = $this->stamp($locked, $locked->requested_in_time);
                    }
                    if ($locked->requested_out_time) {
                        $updates['primary_punch_out'] = $this->stamp($locked, $locked->requested_out_time);
                    }
                    // Recalc worked hours if both punches now exist
                    if (($updates['primary_punch_in'] ?? $daily->primary_punch_in) && ($updates['primary_punch_out'] ?? $daily->primary_punch_out)) {
                        $in = Carbon::parse($updates['primary_punch_in'] ?? $daily->primary_punch_in);
                        $out = Carbon::parse($updates['primary_punch_out'] ?? $daily->primary_punch_out);
                        if ($out->lt($in)) {
                            $out->addDay();
                        }
                        $updates['primary_worked_hours'] = round($in->floatDiffInHours($out), 2);

                        // A day with no punches of its own becomes Present on the strength of the approved
                        // times. A day that already had punches keeps whatever the engine called it — an
                        // approved missing-punch request is not a retroactive amnesty for being late.
                        if (! $hadPunches) {
                            $updates['status'] = 'Present';
                        }
                    }
                    $daily->update($updates);
                } else {
                    // No daily record yet, so the approved request is the only account of the day. Its
                    // status follows the times it carries rather than being stamped Present unconditionally.
                    AttendanceDaily::create([
                        'employee_id' => $locked->employee_id,
                        'duty_date' => $locked->duty_date,
                        'primary_shift_id' => $locked->assigned_shift_id,
                        'primary_punch_in' => $locked->requested_in_time ? $this->stamp($locked, $locked->requested_in_time) : null,
                        'primary_punch_out' => $locked->requested_out_time ? $this->stamp($locked, $locked->requested_out_time) : null,
                        'status' => $this->statusFor($locked->requested_in_time !== null, $locked->requested_out_time !== null),
                        'primary_worked_hours' => $this->workedHours($locked),
                        'is_regularized' => true,
                        'regularization_id' => $locked->id,
                        'remarks' => "Created via regularization {$locked->regularization_no}",
                    ]);
                }
            }

            return $locked->fresh();
        });
    }

    /**
     * A requested HH:mm, anchored to the day it belongs to, as a real timestamp.
     *
     * The obvious `$locked->duty_date.' '.$locked->requested_in_time` is the reason approvals fell over:
     * both attributes are cast to Carbon, so the string it built was
     * `2026-11-04 00:00:00 2026-11-10 09:00:00` — `Carbon::parse` throws on that (a 500 page on Approve),
     * and on the create path the same garbage went straight into the daily's punch column. Format both
     * halves instead of trusting a concat on cast attributes.
     */
    private function stamp(AttendanceRegularization $locked, Carbon $time): string
    {
        return Carbon::parse($locked->duty_date)->toDateString().' '.$time->format('H:i:s');
    }

    /**
     * Hours an approved pair of requested times spans, never negative: an out-punch earlier than the in one
     * is the overnight case the punch path already handles, and 0 when only one side was supplied.
     */
    private function workedHours(AttendanceRegularization $locked): float
    {
        if (! $locked->requested_in_time || ! $locked->requested_out_time) {
            return 0.0;
        }

        $in = Carbon::parse($this->stamp($locked, $locked->requested_in_time));
        $out = Carbon::parse($this->stamp($locked, $locked->requested_out_time));

        if ($out->lt($in)) {
            $out->addDay();
        }

        return round($in->floatDiffInHours($out), 2);
    }

    /**
     * The day-status an approved request implies when it has to create the record from nothing.
     *
     * Mirrors the punch-completeness rule the reconciliation engine uses, so the two do not disagree about
     * what "no in-punch" means.
     */
    private function statusFor(bool $hasIn, bool $hasOut): string
    {
        if ($hasIn && $hasOut) {
            return 'Present';
        }

        if ($hasIn) {
            return 'Missing Out';
        }

        return $hasOut ? 'Missing In' : 'Present';
    }

    public function cancel(AttendanceRegularization $reg): AttendanceRegularization
    {
        if (! $reg->isPending()) {
            throw new \DomainException('Only Pending can be cancelled.');
        }
        $reg->update(['status' => 'Cancelled']);

        return $reg->fresh();
    }
}
