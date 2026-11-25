<?php

namespace Modules\HRM\Interfaces\Employee;

use Illuminate\Support\Collection;
use Modules\HRM\Models\Employee\EmployeeResignation;

interface EmployeeResignationRepositoryInterface
{
    public function find(int $id): ?EmployeeResignation;

    /** @return Collection<int, EmployeeResignation> */
    public function listForEmployee(string $code): Collection;

    public function pendingCountForEmployee(string $code): int;

    public function countApprovedBetween(string $from, string $to): int;
}
