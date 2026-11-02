<?php
namespace Modules\HRM\Interfaces\Roster;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Models\Roster\RosterOvertime;
interface RosterOvertimeRepositoryInterface {
    public function paginate(DataTableQuery $dt): LengthAwarePaginator;
    public function attendanceApprovalQueue(DataTableQuery $dt, string $kind): LengthAwarePaginator;
    public function exportBuilder(DataTableQuery $dt): Builder;
    public function findOrFail(int $id): RosterOvertime;
}
