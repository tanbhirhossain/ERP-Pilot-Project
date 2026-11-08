<?php

namespace Modules\ACCOUNTS\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\ACCOUNTS\Models\Journal;
use Modules\CORE\Support\DataTableQuery;

interface JournalRepositoryInterface
{
    public function paginate(DataTableQuery $dt): LengthAwarePaginator;

    public function exportBuilder(DataTableQuery $dt): Builder;

    public function findOrFail(int $id): Journal;

    public function create(array $data): Journal;

    public function update(Journal $journal, array $data): Journal;

    public function delete(Journal $journal): void;

    public function nextJournalNo(string $prefix): string;
}
