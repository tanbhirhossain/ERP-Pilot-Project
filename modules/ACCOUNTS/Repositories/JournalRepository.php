<?php

namespace Modules\ACCOUNTS\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\ACCOUNTS\Interfaces\JournalRepositoryInterface;
use Modules\ACCOUNTS\Models\Journal;
use Modules\CORE\Repositories\Concerns\BuildsDatatableQueries;
use Modules\CORE\Support\DataTableQuery;

class JournalRepository implements JournalRepositoryInterface
{
    use BuildsDatatableQueries;

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        $q = $this->baseQuery();
        $this->applyDataTable($q, $dt, $this->searchColumns(), $this->sortable(), $this->filters());
        return $q->paginate($dt->perPage, ['*'], 'page', $dt->page)->withQueryString();
    }

    public function exportBuilder(DataTableQuery $dt): Builder
    {
        $q = $this->baseQuery();
        $this->applyDataTable($q, $dt, $this->searchColumns(), $this->sortable(), $this->filters());
        return $q;
    }

    public function findOrFail(int $id): Journal { return Journal::with(['lines.account', 'fiscalYear', 'fiscalPeriod'])->findOrFail($id); }

    public function create(array $data): Journal
    {
        return DB::transaction(function () use ($data): Journal {
            $lines = $data['lines'] ?? [];
            unset($data['lines']);
            $data['journal_no'] = $this->nextJournalNo($data['type'] ?? 'JV');
            $data['created_by'] = auth()->id();
            $journal = Journal::create($data);
            foreach ($lines as $idx => $line) {
                $line['line_no'] = $idx + 1;
                $journal->lines()->create($line);
            }
            return $journal->load('lines');
        });
    }

    public function update(Journal $journal, array $data): Journal
    {
        if (! $journal->isDraft()) throw new \DomainException('Only draft journals can be edited. Void and recreate if needed.');
        return DB::transaction(function () use ($journal, $data): Journal {
            $lines = $data['lines'] ?? null;
            unset($data['lines']);
            if ($lines !== null) {
                $journal->lines()->delete();
                foreach ($lines as $idx => $line) { $line['line_no'] = $idx + 1; $journal->lines()->create($line); }
            }
            $journal->update($data);
            return $journal->load('lines');
        });
    }

    public function delete(Journal $journal): void
    {
        if ($journal->isPosted()) throw new \DomainException('Posted journal cannot be deleted. Void it instead.');
        $journal->lines()->delete();
        $journal->delete();
    }

    public function nextJournalNo(string $prefix): string
    {
        $pad = (int) config('accounts.code.pad', 5);
        $pref = strtoupper($prefix) . '-';
        return DB::transaction(function () use ($pref, $pad): string {
            $last = Journal::where('journal_no', 'like', $pref . '%')->lockForUpdate()->orderByDesc('id')->value('journal_no');
            $n = $last ? ((int) substr($last, strlen($pref)) + 1) : 1;
            return $pref . str_pad((string) $n, $pad, '0', STR_PAD_LEFT);
        });
    }

    private function baseQuery(): Builder
    {
        return Journal::query()->with(['lines.account'])->withCount('lines');
    }
    private function searchColumns(): array { return ['acc_journals.journal_no', 'acc_journals.reference_no', 'acc_journals.narration']; }
    private function sortable(): array { return ['journal_no' => 'acc_journals.journal_no', 'journal_date' => 'acc_journals.journal_date', 'type' => 'acc_journals.type', 'status' => 'acc_journals.status', 'created_at' => 'acc_journals.created_at']; }
    private function filters(): array
    {
        return [
            'type' => fn(Builder $q, string $v) => $q->where('acc_journals.type', $v),
            'status' => fn(Builder $q, string $v) => $q->where('acc_journals.status', $v),
            'fiscal_year_id' => fn(Builder $q, string $v) => $q->where('acc_journals.fiscal_year_id', $v),
            'date_from' => fn(Builder $q, string $v) => $q->whereDate('acc_journals.journal_date', '>=', $v),
            'date_to' => fn(Builder $q, string $v) => $q->whereDate('acc_journals.journal_date', '<=', $v),
        ];
    }
}
