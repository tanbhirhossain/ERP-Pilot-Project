<?php

namespace Modules\HRM\Repositories\Employee;

use Illuminate\Support\Collection;
use Modules\HRM\Interfaces\Employee\EmployeeResignationRepositoryInterface;
use Modules\HRM\Models\Employee\EmployeeResignation;

class EmployeeResignationRepository implements EmployeeResignationRepositoryInterface
{
    public function find(int $id): ?EmployeeResignation
    {
        return EmployeeResignation::find($id);
    }

    public function listForEmployee(string $code): Collection
    {
        return EmployeeResignation::where('employee_id', $code)->orderByDesc('id')->get();
    }

    public function pendingCountForEmployee(string $code): int
    {
        return EmployeeResignation::where('employee_id', $code)->where('status', 'Pending')->count();
    }

    public function countApprovedBetween(string $from, string $to): int
    {
        return EmployeeResignation::where('status', 'Approved')
            ->whereBetween('approved_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->count();
    }
}
