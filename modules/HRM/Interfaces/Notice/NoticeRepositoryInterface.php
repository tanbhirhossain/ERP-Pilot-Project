<?php

namespace Modules\HRM\Interfaces\Notice;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\HRM\Models\Notice\Notice;

interface NoticeRepositoryInterface
{
    public function audiences(): array;

    public function create(array $data): Notice;

    public function update(Notice $notice, array $data): Notice;

    public function find(int $id): ?Notice;

    public function findWithPoster(int $id): ?Notice;

    public function paginatePostedBy(int $userId, int $perPage): LengthAwarePaginator;

    /** Live board rows for visibility filtering, capped. */
    public function liveForBoard(): Collection;
}
