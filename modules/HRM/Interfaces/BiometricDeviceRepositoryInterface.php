<?php

namespace Modules\HRM\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Models\BiometricDevice;

interface BiometricDeviceRepositoryInterface
{
    public function paginate(DataTableQuery $dt): LengthAwarePaginator;

    public function exportBuilder(DataTableQuery $dt): Builder;

    public function options(?string $search = null, int $limit = 25): Collection;

    public function findOrFail(int $id): BiometricDevice;

    public function create(array $data): BiometricDevice;

    public function update(BiometricDevice $d, array $data): BiometricDevice;

    public function delete(BiometricDevice $d): void;

    public function testConnection(BiometricDevice $device): array;
}
