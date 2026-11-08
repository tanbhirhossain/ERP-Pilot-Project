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
            // Prevent duplicate pending for same employee+date+type
            if (AttendanceRegularization::where('employee_id', $data['employee_id'])->where('duty_date', $data['duty_date'])->where('regularization_type', $data['regularization_type'])->whereIn('status', ['Pending', 'Approved'])->exists()) {
                throw new \DomainException('A pending/approved regularization already exists for this employee, date and type.');
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
                $daily = AttendanceDaily::where('employee_id', $locked->employee_id)->where('duty_date', $locked->duty_date)->first();
                if ($daily) {
                    $updates = ['is_regularized' => true, 'regularization_id' => $locked->id, 'remarks' => trim(($daily->remarks ? $daily->remarks.' | ' : '')."Regularized via {$locked->regularization_no}: {$locked->regularization_type}")];
                    // If Missing In/Out, patch punch times
                    if ($locked->regularization_type === 'Missing In' && $locked->requested_in_time) {
                        $updates['primary_punch_in'] = $locked->duty_date.' '.$locked->requested_in_time;
                    }
                    if ($locked->regularization_type === 'Missing Out' && $locked->requested_out_time) {
                        $updates['primary_punch_out'] = $locked->duty_date.' '.$locked->requested_out_time;
                    }
                    // Recalc worked hours if both punches now exist
                    if (($updates['primary_punch_in'] ?? $daily->primary_punch_in) && ($updates['primary_punch_out'] ?? $daily->primary_punch_out)) {
                        $in = Carbon::parse($updates['primary_punch_in'] ?? $daily->primary_punch_in);
                        $out = Carbon::parse($updates['primary_punch_out'] ?? $daily->primary_punch_out);
                        if ($out->lt($in)) {
                            $out->addDay();
                        }
                        $updates['primary_worked_hours'] = round($in->floatDiffInHours($out), 2);
                        $updates['status'] = 'Present';
                    }
                    $daily->update($updates);
                } else {
                    // No daily record yet — create a Present entry from regularization
                    AttendanceDaily::create([
                        'employee_id' => $locked->employee_id,
                        'duty_date' => $locked->duty_date,
                        'primary_shift_id' => $locked->assigned_shift_id,
                        'primary_punch_in' => $locked->requested_in_time ? $locked->duty_date.' '.$locked->requested_in_time : null,
                        'primary_punch_out' => $locked->requested_out_time ? $locked->duty_date.' '.$locked->requested_out_time : null,
                        'status' => 'Present',
                        'is_regularized' => true,
                        'regularization_id' => $locked->id,
                        'remarks' => "Created via regularization {$locked->regularization_no}",
                    ]);
                }
            }

            return $locked->fresh();
        });
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
