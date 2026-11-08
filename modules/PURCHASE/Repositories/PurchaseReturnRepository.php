<?php

namespace Modules\PURCHASE\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Repositories\Concerns\BuildsDatatableQueries;
use Modules\CORE\Support\DataTableQuery;
use Modules\PURCHASE\Interfaces\PurchaseReturnRepositoryInterface;
use Modules\PURCHASE\Models\PurchaseReturn;

class PurchaseReturnRepository implements PurchaseReturnRepositoryInterface
{
    use BuildsDatatableQueries;

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        $q = $this->baseQuery();
        $this->applyDataTable($q, $dt, ['purchase_returns.return_no'], $this->sortable(), $this->filters());

        return $q->paginate($dt->perPage, ['*'], 'page', $dt->page)->withQueryString();
    }

    public function exportBuilder(DataTableQuery $dt): Builder
    {
        $q = $this->baseQuery();
        $this->applyDataTable($q, $dt, ['purchase_returns.return_no'], $this->sortable(), $this->filters());

        return $q;
    }

    public function findOrFail(int $id): PurchaseReturn
    {
        return PurchaseReturn::with(['vendor', 'warehouse', 'order', 'receipt', 'bill', 'items.product', 'journal'])->findOrFail($id);
    }

    public function create(array $data): PurchaseReturn
    {
        return DB::transaction(function () use ($data): PurchaseReturn {
            $items = $data['items'] ?? [];
            unset($data['items']);
            $data['return_no'] = $this->nextNo();
            $data['status'] = $data['status'] ?? 'draft';
            $data['created_by'] = auth()->id();
            $r = PurchaseReturn::create($data);
            foreach ($items as $it) {
                $r->items()->create($it);
            }

            return $r->load('items');
        });
    }

    public function update(PurchaseReturn $r, array $data): PurchaseReturn
    {
        if (! $r->isDraft()) {
            throw new \DomainException('Only draft can be edited.');
        }

        return DB::transaction(function () use ($r, $data): PurchaseReturn {
            $items = $data['items'] ?? null;
            unset($data['items']);
            if ($items !== null) {
                $r->items()->delete();
                foreach ($items as $it) {
                    $r->items()->create($it);
                }
            }
            $r->update($data);

            return $r->load('items');
        });
    }

    public function delete(PurchaseReturn $r): void
    {
        if (! $r->isDraft()) {
            throw new \DomainException('Only draft can be deleted.');
        } $r->items()->delete();
        $r->delete();
    }

    public function nextNo(): string
    {
        $pad = (int) config('purchase.code.pad', 5);
        $pref = config('purchase.code.return_prefix', 'PR-');

        return DB::transaction(function () use ($pref, $pad): string {
            $last = PurchaseReturn::where('return_no', 'like', $pref.'%')->lockForUpdate()->orderByDesc('id')->value('return_no');
            $n = $last ? ((int) substr($last, strlen($pref)) + 1) : 1;

            return $pref.str_pad((string) $n, $pad, '0', STR_PAD_LEFT);
        });
    }

    private function baseQuery(): Builder
    {
        return PurchaseReturn::query()->leftJoin('partners', 'partners.id', '=', 'purchase_returns.vendor_id')->with(['vendor', 'warehouse'])->select('purchase_returns.*');
    }

    private function sortable(): array
    {
        return ['return_no' => 'purchase_returns.return_no', 'status' => 'purchase_returns.status', 'created_at' => 'purchase_returns.created_at'];
    }

    private function filters(): array
    {
        return ['status' => fn (Builder $q, string $v) => $q->where('purchase_returns.status', $v)];
    }
}
