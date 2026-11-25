<?php

namespace Modules\HRM\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Modules\CORE\Support\Api\ApiEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HRM\Interfaces\DepartmentRepositoryInterface;
use Modules\HRM\Interfaces\Employee\EmployeeRepositoryInterface;
use Modules\HRM\Support\Api\ApiResponse;
use Modules\HRM\Support\Api\ResolvesTeamScope;

/**
 * Organisation directory (Phase 3).
 *
 * GET /api/v1/org/departments   every department (any signed-in user; forms need it)
 * GET /api/v1/org/employees     directory search — ALL-scope roles only
 *                               (HR/DGM/GM/CEO/Admins). Supervisors use
 *                               /team/members for their own people.
 */
class OrgApiController extends Controller
{
    use ResolvesTeamScope;

    public function __construct(
        private readonly DepartmentRepositoryInterface $departments,
        private readonly EmployeeRepositoryInterface $employees,
    ) {}

    #[ApiEndpoint(
        summary: 'Departments',
        description: 'Every department. Any signed-in user may read it — forms and filters need it.',
        response: 'MobileEnvelope',
    )]
    public function departments(): JsonResponse
    {
        return ApiResponse::ok($this->departments->catalog(), 'Departments.');
    }

    #[ApiEndpoint(
        summary: 'Organisation directory',
        description: 'Searchable employee directory for HR, DGM, GM, CEO and Admins only — supervisors use team/members for their own people.',
        query: [
            ['name' => 'search', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Code, name or phone fragment.'],
            ['name' => 'department_id', 'in' => 'query', 'schema' => ['type' => 'integer']],
            ['name' => 'status', 'in' => 'query', 'schema' => ['type' => 'string']],
            ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]],
        ],
        response: 'MobilePage',
    )]
    public function employees(Request $request): JsonResponse
    {
        if (! $this->hasAllScope($request->user())) {
            return ApiResponse::error('The organisation directory needs an HR, DGM, GM, CEO or Admin role.', 403);
        }

        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'status' => ['nullable', 'string', 'max:20'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $this->employees->directoryPaginate(
            $data['search'] ?? null,
            ! empty($data['department_id']) ? (int) $data['department_id'] : null,
            $data['status'] ?? null,
            $data['per_page'] ?? 20,
        )->through(fn ($e): array => [
            'employee_id' => $e->employee_id,
            'full_name' => $e->full_name ?? trim(($e->first_name ?? '').' '.($e->last_name ?? '')),
            'department' => $e->department?->name,
            'designation' => $e->designation?->name,
            'status' => $e->status,
            'employment_type' => $e->employment_type,
            'phone' => $e->phone,
            'photo' => $e->photo,
        ]);

        return ApiResponse::paginated($page, 'Organisation directory.');
    }
}
