<?php

namespace Modules\HRM\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Modules\CORE\Support\Api\ApiEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HRM\Interfaces\Attendance\AttendanceDailyRepositoryInterface;
use Modules\HRM\Interfaces\Attendance\AttendanceLogRepositoryInterface;
use Modules\HRM\Interfaces\Attendance\AttendancePairRepositoryInterface;
use Modules\HRM\Support\Api\ApiResponse;
use Modules\HRM\Support\Api\ResolvesApiEmployee;

/**
 * Employee attendance self-service: raw punches, IN/OUT pairs, daily verdicts.
 *
 * GET /api/v1/attendance/logs?from=&to=    my punch log (default: this month)
 * GET /api/v1/attendance/pairs?from=&to=   my IN/OUT pairs
 * GET /api/v1/attendance/daily?from=&to=   my daily attendance
 * GET /api/v1/attendance/summary?month=YYYY-MM  month counts + hour totals
 */
class AttendanceApiController extends Controller
{
    use ResolvesApiEmployee;

    public function __construct(
        private readonly AttendanceLogRepositoryInterface $logs,
        private readonly AttendancePairRepositoryInterface $pairs,
        private readonly AttendanceDailyRepositoryInterface $dailies,
    ) {}

    #[ApiEndpoint(
        summary: 'My punch log',
        description: 'Raw IN/OUT punches, newest first. Defaults to this month; pages hold up to 200 rows.',
        query: [
            ['name' => 'from', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date']],
            ['name' => 'to', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date']],
            ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200]],
        ],
        response: 'MobilePage',
    )]
    public function logs(Request $request): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        [$from, $to, $perPage] = $this->range($request);

        $page = $this->logs->paginateForEmployee($employee->employee_id, $from, $to, $perPage);

        return ApiResponse::paginated($page, 'My attendance log.');
    }

    #[ApiEndpoint(
        summary: 'My IN/OUT pairs',
        description: 'Matched punch pairs per duty date, newest first. Defaults to this month.',
        query: [
            ['name' => 'from', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date']],
            ['name' => 'to', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date']],
            ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200]],
        ],
        response: 'MobilePage',
    )]
    public function pairs(Request $request): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        [$from, $to, $perPage] = $this->range($request);

        $page = $this->pairs->paginateForEmployee($employee->employee_id, $from, $to, $perPage);

        return ApiResponse::paginated($page, 'My attendance pairs.');
    }

    #[ApiEndpoint(
        summary: 'My daily verdicts',
        description: 'The per-day attendance verdict (Present, Late, Absent, On Leave, …), newest first. Defaults to this month.',
        query: [
            ['name' => 'from', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date']],
            ['name' => 'to', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date']],
            ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200]],
        ],
        response: 'MobilePage',
    )]
    public function daily(Request $request): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        [$from, $to, $perPage] = $this->range($request);

        $page = $this->dailies->paginateForEmployee($employee->employee_id, $from, $to, $perPage);

        return ApiResponse::paginated($page, 'My daily attendance.');
    }

    #[ApiEndpoint(
        summary: 'My monthly attendance summary',
        description: 'Counts by verdict plus worked, overtime and total hours for the month.',
        query: [
            ['name' => 'month', 'in' => 'query', 'schema' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}$'], 'description' => 'YYYY-MM, defaults to this month.'],
        ],
        response: 'MobileEnvelope',
    )]
    public function summary(Request $request): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);

        $month = $data['month'] ?? now()->format('Y-m');
        $from = $month.'-01';
        $to = date('Y-m-t', strtotime($from));

        $days = $this->dailies->listForEmployeeRange($employee->employee_id, $from, $to);

        return ApiResponse::ok([
            'month' => $month,
            'days' => $days->count(),
            'present' => $days->whereIn('status', ['Present', 'Late', 'Early Out', 'Late & Early Out'])->count(),
            'late' => $days->whereIn('status', ['Late', 'Late & Early Out'])->count(),
            'early_out' => $days->whereIn('status', ['Early Out', 'Late & Early Out'])->count(),
            'absent' => $days->where('status', 'Absent')->count(),
            'on_leave' => $days->where('status', 'On Leave')->count(),
            'holiday' => $days->where('status', 'Holiday')->count(),
            'missing_punch' => $days->whereIn('status', ['Missing In', 'Missing Out'])->count(),
            'extra_duty' => $days->where('status', 'Extra Duty')->count(),
            'worked_hours' => round((float) $days->sum('primary_worked_hours'), 2),
            'overtime_hours' => round((float) $days->sum('total_overtime_hours'), 2),
            'total_hours' => round((float) $days->sum('grand_total_hours'), 2),
        ], 'Attendance summary.');
    }

    /**
     * @return array{0: string, 1: string, 2: int}
     */
    private function range(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return [
            $data['from'] ?? now()->startOfMonth()->toDateString(),
            $data['to'] ?? now()->endOfMonth()->toDateString(),
            $data['per_page'] ?? 50,
        ];
    }
}
