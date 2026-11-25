<?php

namespace Modules\HRM\Repositories\Notice;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\HRM\Interfaces\Notice\NoticeRepositoryInterface;
use Modules\HRM\Models\Notice\Notice;

class NoticeRepository implements NoticeRepositoryInterface
{
    public function audiences(): array
    {
        return Notice::AUDIENCES;
    }

    public function create(array $data): Notice
    {
        return DB::transaction(fn () => Notice::create($data));
    }

    public function update(Notice $notice, array $data): Notice
    {
        return DB::transaction(function () use ($notice, $data) {
            $notice->fill($data);
            $notice->save();

            return $notice;
        });
    }

    public function find(int $id): ?Notice
    {
        return Notice::find($id);
    }

    public function findWithPoster(int $id): ?Notice
    {
        return Notice::with('poster:id,name')->find($id);
    }

    public function paginatePostedBy(int $userId, int $perPage): LengthAwarePaginator
    {
        return Notice::where('posted_by', $userId)
            ->with('poster:id,name')
            ->latest('id')
            ->paginate($perPage);
    }

    public function liveForBoard(): Collection
    {
        return Notice::live()->with('poster:id,name')->latest('id')->limit(500)->get();
    }
}
