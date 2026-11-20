<?php

namespace Modules\HRM\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Interfaces\Roster\RosterShiftSwapRepositoryInterface;
use Modules\HRM\Models\Roster\RosterDuty;
use Modules\HRM\Models\Roster\RosterShift;
use Modules\HRM\Models\Roster\RosterShiftSwap;

class RosterShiftSwapService
{
    public function __construct(private readonly RosterShiftSwapRepositoryInterface $repo) {}

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        return $this->repo->paginate($dt);
    }

    public function findOrFail(int $id): RosterShiftSwap
    {
        return $this->repo->findOrFail($id);
    }

    public function create(array $data): RosterShiftSwap
    {
        return $this->repo->create($data);
    }

    public function update(RosterShiftSwap $swap, array $data): RosterShiftSwap
    {
        return $this->repo->update($swap, $data);
    }

    public function delete(RosterShiftSwap $swap): void
    {
        $this->repo->delete($swap);
    }

    public function approve(RosterShiftSwap $swap): RosterShiftSwap
    {
        return DB::transaction(function () use ($swap) {
            if ($swap->status !== 'Pending') {
                throw new \RuntimeException('Only Pending can be approved');
            }
            /*
             * The day the swap is about, as a calendar day.
             *
             * `swap_date` is cast to a date on the model, so passing it straight into a where() binds
             * it as "2026-10-13 00:00:00" while `roster_duties.roster_date` is stored as "2026-10-13".
             * The lookup therefore matched nothing, the whole exchange was skipped — and the swap was
             * still marked Approved, so the roster screen said the swap had happened while both
             * employees kept their original shift. Found on the clean rebuild: an approved F-0009 /
             * F-0010 swap on 2026-10-13 left both duties untouched and `Scheduled`.
             *
             * Matching with whereDate() makes the day match whichever shape the column holds, and an
             * approval that cannot find the duty it is meant to change now fails loudly instead of
             * reporting success.
             */
            $swapDay = $swap->swap_date instanceof \DateTimeInterface
                ? $swap->swap_date->format('Y-m-d')
                : (string) $swap->swap_date;

            // Swap logic: if target_employee_id set, exchange duties on swap_date; else change requester duty to requested_shift
            if ($swap->target_employee_id) {
                $reqDuty = RosterDuty::where('employee_id', $swap->requester_employee_id)->whereDate('roster_date', $swapDay)->first();
                $tgtDuty = RosterDuty::where('employee_id', $swap->target_employee_id)->whereDate('roster_date', $swapDay)->first();
                if ($reqDuty === null || $tgtDuty === null) {
                    throw new \DomainException(sprintf(
                        'The swap cannot be approved: %s has no roster duty on %s to exchange.',
                        $reqDuty === null ? $swap->requester_employee_id : $swap->target_employee_id,
                        $swapDay,
                    ));
                }
                if ($reqDuty && $tgtDuty) {
                    // exchange shift_id — cache both original shifts BEFORE mutation
                    $tmpShiftId = $reqDuty->shift_id;
                    $tmpStart = $reqDuty->scheduled_start_time;
                    $tmpEnd = $reqDuty->scheduled_end_time;
                    $tgtShiftId = $tgtDuty->shift_id;
                    $tgtStart = $tgtDuty->scheduled_start_time ?? $tgtDuty->shift?->start_time;
                    $tgtEnd = $tgtDuty->scheduled_end_time ?? $tgtDuty->shift?->end_time;
                    $reqDuty->update(['shift_id' => $tgtShiftId, 'scheduled_start_time' => $tgtStart, 'scheduled_end_time' => $tgtEnd, 'status' => 'Swapped']);
                    $tgtDuty->update(['shift_id' => $tmpShiftId, 'scheduled_start_time' => $tmpStart, 'scheduled_end_time' => $tmpEnd, 'status' => 'Swapped']);
                } elseif ($reqDuty && $swap->requested_shift_id) {
                    $reqDuty->update(['shift_id' => $swap->requested_shift_id, 'status' => 'Swapped']);
                }
            } elseif ($swap->requested_shift_id) {
                $reqDuty = RosterDuty::where('employee_id', $swap->requester_employee_id)->whereDate('roster_date', $swapDay)->first();
                if ($reqDuty) {
                    $shift = RosterShift::find($swap->requested_shift_id);
                    $reqDuty->update(['shift_id' => $swap->requested_shift_id, 'scheduled_start_time' => $shift?->start_time, 'scheduled_end_time' => $shift?->end_time, 'status' => 'Swapped']);
                } else {
                    // create duty if not exists
                    $shift = RosterShift::find($swap->requested_shift_id);
                    RosterDuty::create(['employee_id' => $swap->requester_employee_id, 'roster_date' => $swapDay, 'shift_id' => $swap->requested_shift_id, 'scheduled_start_time' => $shift?->start_time, 'scheduled_end_time' => $shift?->end_time, 'status' => 'Swapped']);
                }
            }
            $swap->update(['status' => 'Approved', 'approved_by' => auth()->id()]);

            return $swap->refresh();
        });
    }

    public function reject(RosterShiftSwap $swap, ?string $reason = null): RosterShiftSwap
    {
        if ($swap->status !== 'Pending') {
            throw new \RuntimeException('Only Pending can be rejected');
        }
        $swap->update(['status' => 'Rejected', 'approved_by' => auth()->id()]);

        return $swap;
    }
}
