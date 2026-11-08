<?php

namespace Modules\PARTNER\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\CORE\Support\DataTableQuery;
use Modules\PARTNER\Models\Partner;

interface PartnerRepositoryInterface
{
    public function paginate(DataTableQuery $dt, ?string $type = null): LengthAwarePaginator;

    public function exportBuilder(DataTableQuery $dt, ?string $type = null): Builder;

    public function options(?string $search = null, int $limit = 25, ?string $type = null): Collection;

    public function findOrFail(int $id): Partner;

    public function findByCode(string $code): ?Partner;

    public function create(array $data): Partner;

    public function update(Partner $partner, array $data): Partner;

    public function delete(Partner $partner): void;

    public function toggleActive(Partner $partner): Partner;

    public function verify(Partner $partner, bool $approved, ?string $remarks = null): Partner;
}
