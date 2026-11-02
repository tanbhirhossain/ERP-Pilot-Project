<?php
namespace Modules\HRM\Interfaces\Roster;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Models\Roster\RosterShiftSwap;
interface RosterShiftSwapRepositoryInterface {
    public function paginate(DataTableQuery $dt): LengthAwarePaginator;
    public function exportBuilder(DataTableQuery $dt): Builder;
    public function findOrFail(int $id): RosterShiftSwap;
    public function create(array $data): RosterShiftSwap;
    public function update(RosterShiftSwap $swap, array $data): RosterShiftSwap;
    public function delete(RosterShiftSwap $swap): void;
}
