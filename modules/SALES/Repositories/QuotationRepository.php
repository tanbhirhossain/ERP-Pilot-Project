<?php

namespace Modules\SALES\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Repositories\Concerns\BuildsDatatableQueries;
use Modules\CORE\Support\DataTableQuery;
use Modules\SALES\Interfaces\QuotationRepositoryInterface;
use Modules\SALES\Models\Quotation;

class QuotationRepository implements QuotationRepositoryInterface
{
    use BuildsDatatableQueries;

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        $q = $this->baseQuery();
        $this->applyDataTable($q, $dt, ['sales_quotations.quotation_no', 'partners.name'], $this->sortable(), $this->filters());
        return $q->paginate($dt->perPage, ['*'], 'page', $dt->page)->withQueryString();
    }
    public function exportBuilder(DataTableQuery $dt): Builder { $q = $this->baseQuery(); $this->applyDataTable($q, $dt, ['sales_quotations.quotation_no'], $this->sortable(), $this->filters()); return $q; }
    public function findOrFail(int $id): Quotation { return Quotation::with(['customer', 'items.product', 'convertedOrder'])->findOrFail($id); }

    public function create(array $data): Quotation
    {
        return DB::transaction(function () use ($data): Quotation {
            $items = $data['items'] ?? [];
            unset($data['items']);
            $data['quotation_no'] = $this->nextNo();
            $data['status'] = $data['status'] ?? 'draft';
            $data['created_by'] = auth()->id();
            $q = Quotation::create($data);
            foreach ($items as $idx => $it) { $it['sort_order'] = $idx; $q->items()->create($it); }
            return $q->load('items');
        });
    }

    public function update(Quotation $q, array $data): Quotation
    {
        if (! $q->isDraft()) throw new \DomainException('Only draft quotations can be edited.');
        return DB::transaction(function () use ($q, $data): Quotation {
            $items = $data['items'] ?? null;
            unset($data['items']);
            if ($items !== null) { $q->items()->delete(); foreach ($items as $idx => $it) { $it['sort_order'] = $idx; $q->items()->create($it); } }
            $q->update($data);
            return $q->load('items');
        });
    }

    public function delete(Quotation $q): void
    {
        if ($q->converted_order_id) throw new \DomainException('Converted quotation cannot be deleted.');
        $q->items()->delete();
        $q->delete();
    }

    public function nextNo(): string
    {
        $pad = (int) config('sales.code.pad', 5);
        $pref = config('sales.code.quotation_prefix', 'QT-');
        return DB::transaction(function () use ($pref, $pad): string {
            $last = Quotation::where('quotation_no', 'like', $pref . '%')->lockForUpdate()->orderByDesc('id')->value('quotation_no');
            $n = $last ? ((int) substr($last, strlen($pref)) + 1) : 1;
            return $pref . str_pad((string) $n, $pad, '0', STR_PAD_LEFT);
        });
    }

    private function baseQuery(): Builder
    {
        return Quotation::query()->leftJoin('partners', 'partners.id', '=', 'sales_quotations.customer_id')->with(['customer'])->withCount('items')->select('sales_quotations.*');
    }
    private function sortable(): array { return ['quotation_no' => 'sales_quotations.quotation_no', 'quotation_date' => 'sales_quotations.quotation_date', 'status' => 'sales_quotations.status', 'created_at' => 'sales_quotations.created_at']; }
    private function filters(): array { return ['status' => fn(Builder $q, string $v) => $q->where('sales_quotations.status', $v), 'customer_id' => fn(Builder $q, string $v) => $q->where('sales_quotations.customer_id', $v)]; }
}
