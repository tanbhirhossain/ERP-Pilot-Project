<?php

namespace Modules\HRM\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HRM\Models\Roster\RosterDuty;
use Modules\HRM\Models\Roster\RosterShift;
use Modules\HRM\Services\RosterDutyService;
use Modules\HRM\Support\Api\ApiResponse;
use Modules\HRM\Support\Api\ResolvesTeamScope;

/**
 * Roster writes for supervisors (Phase 3).
 *
 * GET    /api/v1/roster/shifts   shift catalogue (any signed-in user; forms need it)
 * POST   /api/v1/roster/duties/bulk  bulk assign (mirrors the web bulk rules + scope)
 * POST   /api/v1/roster/duties       single duty (scope-checked)
 * DELETE /api/v1/roster/duties/{id}  remove (scope-checked via the duty's employee)
 *
 * Authorization is team scope, deliberately NOT hrm.duties.manage: that
 * permission opens the whole web roster desk, and a supervisor must only ever
 * touch their own reports. All writes travel through RosterDutyService, so the
 * mobile roster and the web desk share behaviour.
 */
class RosterApiController extends Controller
{
    use ResolvesTeamScope;

    public function __construct(private readonly RosterDutyService $duties) {}

    public function shifts(): JsonResponse
    {
        $shifts = RosterShift::orderBy('shift_name')
            ->get(['id', 'shift_name', 'start_time', 'end_time', 'crosses_midnight']);

        return ApiResponse::ok($shifts, 'Roster shifts.');
    }

    public function bulkAssign(Request $request): JsonResponse
    {
        // Same rules as the web BulkRosterAssignRequest (incl. the 60-day cap).
        $data = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1', 'max:500'],
            'employee_ids.*' => ['string', 'exists:employees,employee_id'],
            'shift_id' => ['required', 'exists:roster_shifts,id'],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
        ]);

        $days = (new \DateTimeImmutable($data['from_date']))->diff(new \DateTimeImmutable($data['to_date']))->days;

        if ($days > 60) {
            return ApiResponse::error('Roster range may not exceed 60 days.', 422);
        }

        $outside = [];

        foreach (array_unique($data['employee_ids']) as $code) {
            if (! $this->inScope($request->user(), (string) $code)) {
                $outside[] = $code;
            }
        }

        if ($outside !== []) {
            return ApiResponse::error(
                'These employees are outside your team: '.implode(', ', array_slice($outside, 0, 10)).'.',
                403
            );
        }

        $count = $this->duties->bulkAssign(
            array_values(array_unique($data['employee_ids'])),
            (int) $data['shift_id'],
            $data['from_date'],
            $data['to_date'],
        );

        return ApiResponse::ok(['assigned' => $count], "Roster set for {$count} duty row(s).", [], 201);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'string', 'exists:employees,employee_id'],
            'roster_date' => ['required', 'date'],
            'shift_id' => ['nullable', 'integer', 'exists:roster_shifts,id'],
        ]);

        if (! $this->inScope($request->user(), $data['employee_id'])) {
            return ApiResponse::error('This employee is outside your team.', 403);
        }

        $duty = $this->duties->create($data);

        return ApiResponse::ok($duty->fresh(), 'Roster duty set.', [], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $duty = RosterDuty::find($id);

        if ($duty === null) {
            return ApiResponse::error('Roster duty not found.', 404);
        }

        if (! $this->inScope($request->user(), $duty->employee_id)) {
            return ApiResponse::error('This employee is outside your team.', 403);
        }

        $this->duties->delete($duty);

        return ApiResponse::ok(null, 'Roster duty removed.');
    }
}
