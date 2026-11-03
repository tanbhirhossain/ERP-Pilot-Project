<?php

namespace Modules\PURCHASE\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Repositories\Concerns\BuildsDatatableQueries;
use Modules\CORE\Support\DataTableQuery;
use Modules\PURCHASE\Interfaces\GoodsReceiptRepositoryInterface;
use Modules\PURCHASE\Models\GoodsReceipt;

class GoodsReceiptRepository implements GoodsReceiptRepositoryInterface
{
    use BuildsDatatableQueries;
    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        $q = $this->baseQuery();
        $this->applyDataTable($q, $dt, ['purchase_receipts.receipt_no'], $this->sortable(), $this->filters());
        return $q->paginate($dt->perPage, ['*'], 'page', $dt->page)->withQueryString();
    }
    public function exportBuilder(DataTableQuery $dt): Builder { $q = $this->baseQuery(); $this->applyDataTable($q, $dt, ['purchase_receipts.receipt_no'], $this->sortable(), $this->filters()); return $q; }
    public function findOrFail(int $id): GoodsReceipt { return GoodsReceipt::with(['vendor','warehouse','order','items.product'])->findOrFail($id); }
    public function create(array $data): GoodsReceipt
    {
        return DB::transaction(function () use ($data): GoodsReceipt {
            $items = $data['items'] ?? []; unset($data['items']);
            $data['receipt_no'] = $this->nextNo();
            $data['status'] = $data['status'] ?? 'draft';
            $data['created_by'] = auth()->id();
            $r = GoodsReceipt::create($data);
            foreach ($items as $it) { $r->items()->create($it); }
            return $r->load('items');
        });
    }
    public function update(GoodsReceipt $receipt, array $data): GoodsReceipt
    {
        if (! $receipt->isDraft()) throw new \DomainException('Only draft can be edited.');
        return DB::transaction(function () use ($receipt, $data): GoodsReceipt {
            $items = $data['items'] ?? null; unset($data['items']);
            if ($items !== null) { $receipt->items()->delete(); foreach ($items as $it) { $receipt->items()->create($it); } }
            $receipt->update($data);
            return $receipt->load('items');
        });
    }
    public function delete(GoodsReceipt $receipt): void { if (! $receipt->isDraft()) throw new \DomainException('Only draft can be deleted.'); $receipt->items()->delete(); $receipt->delete(); }
    public function nextNo(): string
    {
        $pad = (int) config('purchase.code.pad', 5); $pref = config('purchase.code.receipt_prefix', 'GRN-');
        return DB::transaction(function () use ($pref, $pad): string {
            $last = GoodsReceipt::where('receipt_no', 'like', $pref.'%')->lockForUpdate()->orderByDesc('id')->value('receipt_no');
            $n = $last ? ((int) substr($last, strlen($pref)) + 1) : 1;
            return $pref . str_pad((string) $n, $pad, '0', STR_PAD_LEFT);
        });
    }
    private function baseQuery(): Builder { return GoodsReceipt::query()->leftJoin('partners','partners.id','=','purchase_receipts.vendor_id')->with(['vendor','warehouse'])->select('purchase_receipts.*'); }
    private function sortable(): array { return ['receipt_no'=>'purchase_receipts.receipt_no','status'=>'purchase_receipts.status','created_at'=>'purchase_receipts.created_at']; }
    private function filters(): array { return ['status'=>fn(Builder $q,string $v)=>$q->where('purchase_receipts.status',$v)]; }
}
