<?php

namespace Modules\PURCHASE\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Repositories\Concerns\BuildsDatatableQueries;
use Modules\CORE\Support\DataTableQuery;
use Modules\PURCHASE\Interfaces\VendorBillRepositoryInterface;
use Modules\PURCHASE\Models\VendorBill;

class VendorBillRepository implements VendorBillRepositoryInterface
{
    use BuildsDatatableQueries;

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        $q = $this->baseQuery();
        $this->applyDataTable($q, $dt, ['purchase_bills.bill_no'], $this->sortable(), $this->filters());

        return $q->paginate($dt->perPage, ['*'], 'page', $dt->page)->withQueryString();
    }

    public function exportBuilder(DataTableQuery $dt): Builder
    {
        $q = $this->baseQuery();
        $this->applyDataTable($q, $dt, ['purchase_bills.bill_no'], $this->sortable(), $this->filters());

        return $q;
    }

    public function findOrFail(int $id): VendorBill
    {
        return VendorBill::with(['vendor', 'warehouse', 'order', 'receipt', 'items.product', 'payments', 'journal'])->findOrFail($id);
    }

    public function create(array $data): VendorBill
    {
        return DB::transaction(function () use ($data): VendorBill {
            $items = $data['items'] ?? [];
            unset($data['items']);
            $data['bill_no'] = $this->nextNo();
            $data['status'] = $data['status'] ?? 'draft';
            $data['payment_status'] = $data['payment_status'] ?? 'unpaid';
            $data['created_by'] = auth()->id();
            $b = VendorBill::create($data);
            foreach ($items as $it) {
                $b->items()->create($it);
            }

            return $b->load('items');
        });
    }

    public function update(VendorBill $bill, array $data): VendorBill
    {
        if (! $bill->isDraft()) {
            throw new \DomainException('Only draft can be edited.');
        }

        return DB::transaction(function () use ($bill, $data): VendorBill {
            $items = $data['items'] ?? null;
            unset($data['items']);
            if ($items !== null) {
                $bill->items()->delete();
                foreach ($items as $it) {
                    $bill->items()->create($it);
                }
            }
            $bill->update($data);

            return $bill->load('items');
        });
    }

    public function delete(VendorBill $bill): void
    {
        if (! $bill->isDraft()) {
            throw new \DomainException('Only draft can be deleted.');
        } $bill->items()->delete();
        $bill->delete();
    }

    public function nextNo(): string
    {
        $pad = (int) config('purchase.code.pad', 5);
        $pref = config('purchase.code.bill_prefix', 'PB-');

        return DB::transaction(function () use ($pref, $pad): string {
            $last = VendorBill::where('bill_no', 'like', $pref.'%')->lockForUpdate()->orderByDesc('id')->value('bill_no');
            $n = $last ? ((int) substr($last, strlen($pref)) + 1) : 1;

            return $pref.str_pad((string) $n, $pad, '0', STR_PAD_LEFT);
        });
    }

    private function baseQuery(): Builder
    {
        return VendorBill::query()->leftJoin('partners', 'partners.id', '=', 'purchase_bills.vendor_id')->with(['vendor', 'warehouse'])->select('purchase_bills.*');
    }

    private function sortable(): array
    {
        return ['bill_no' => 'purchase_bills.bill_no', 'status' => 'purchase_bills.status', 'created_at' => 'purchase_bills.created_at'];
    }

    private function filters(): array
    {
        return ['status' => fn (Builder $q, string $v) => $q->where('purchase_bills.status', $v), 'payment_status' => fn (Builder $q, string $v) => $q->where('purchase_bills.payment_status', $v)];
    }
}
