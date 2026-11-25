<?php

namespace Modules\HRM\Repositories\Attendance;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\HRM\Interfaces\Attendance\AttendanceRegularizationRepositoryInterface;
use Modules\HRM\Models\AttendanceRegularization;

class AttendanceRegularizationRepository implements AttendanceRegularizationRepositoryInterface
{
    public function types(): array
    {
        return AttendanceRegularization::TYPES;
    }

    public function find(int $id): ?AttendanceRegularization
    {
        return AttendanceRegularization::find($id);
    }

    public function findForEmployee(int $id, string $code, bool $withReviewer = false): ?AttendanceRegularization
    {
        $query = AttendanceRegularization::where('employee_id', $code);

        if ($withReviewer) {
            $query->with('reviewer:id,name');
        }

        return $query->find($id);
    }

    public function paginateForEmployee(string $code, ?string $status, int $perPage): LengthAwarePaginator
    {
        $query = AttendanceRegularization::where('employee_id', $code)
            ->with('reviewer:id,name')
            ->orderByDesc('duty_date')
            ->orderByDesc('id');

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        return $query->paginate($perPage);
    }

    public function pendingCountForEmployee(string $code): int
    {
        return AttendanceRegularization::where('employee_id', $code)->where('status', 'Pending')->count();
    }
}
