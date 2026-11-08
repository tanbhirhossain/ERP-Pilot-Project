<?php

namespace Modules\SALES\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Repositories\Concerns\BuildsDatatableQueries;
use Modules\CORE\Support\DataTableQuery;
use Modules\SALES\Interfaces\InvoiceRepositoryInterface;
use Modules\SALES\Models\Invoice;

class InvoiceRepository implements InvoiceRepositoryInterface
{
    use BuildsDatatableQueries;

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        $q = $this->baseQuery();
        $this->applyDataTable($q, $dt, ['sales_invoices.invoice_no', 'partners.name'], $this->sortable(), $this->filters());

        return $q->paginate($dt->perPage, ['*'], 'page', $dt->page)->withQueryString();
    }

    public function exportBuilder(DataTableQuery $dt): Builder
    {
        $q = $this->baseQuery();
        $this->applyDataTable($q, $dt, ['sales_invoices.invoice_no'], $this->sortable(), $this->filters());

        return $q;
    }

    public function findOrFail(int $id): Invoice
    {
        return Invoice::with(['customer', 'warehouse', 'order', 'items.product', 'payments', 'journal'])->findOrFail($id);
    }

    public function create(array $data): Invoice
    {
        return DB::transaction(function () use ($data): Invoice {
            $items = $data['items'] ?? [];
            unset($data['items']);
            $data['invoice_no'] = $this->nextNo();
            $data['status'] = $data['status'] ?? 'draft';
            $data['payment_status'] = $data['payment_status'] ?? 'unpaid';
            $data['created_by'] = auth()->id();
            $inv = Invoice::create($data);
            foreach ($items as $it) {
                $inv->items()->create($it);
            }

            return $inv->load('items');
        });
    }

    public function update(Invoice $inv, array $data): Invoice
    {
        if (! $inv->isDraft()) {
            throw new \DomainException('Only draft invoices can be edited.');
        }

        return DB::transaction(function () use ($inv, $data): Invoice {
            $items = $data['items'] ?? null;
            unset($data['items']);
            if ($items !== null) {
                $inv->items()->delete();
                foreach ($items as $it) {
                    $inv->items()->create($it);
                }
            }
            $inv->update($data);

            return $inv->load('items');
        });
    }

    public function delete(Invoice $inv): void
    {
        if ($inv->isPosted()) {
            throw new \DomainException('Posted invoice cannot be deleted. Void it.');
        } $inv->items()->delete();
        $inv->delete();
    }

    public function nextNo(): string
    {
        $pad = (int) config('sales.code.pad', 5);
        $pref = config('sales.code.invoice_prefix', 'INV-');

        return DB::transaction(function () use ($pref, $pad): string {
            $last = Invoice::where('invoice_no', 'like', $pref.'%')->lockForUpdate()->orderByDesc('id')->value('invoice_no');
            $n = $last ? ((int) substr($last, strlen($pref)) + 1) : 1;

            return $pref.str_pad((string) $n, $pad, '0', STR_PAD_LEFT);
        });
    }

    private function baseQuery(): Builder
    {
        return Invoice::query()->leftJoin('partners', 'partners.id', '=', 'sales_invoices.customer_id')->with(['customer', 'warehouse'])->withCount('items')->select('sales_invoices.*');
    }

    private function sortable(): array
    {
        return ['invoice_no' => 'sales_invoices.invoice_no', 'invoice_date' => 'sales_invoices.invoice_date', 'status' => 'sales_invoices.status', 'created_at' => 'sales_invoices.created_at'];
    }

    private function filters(): array
    {
        return ['status' => fn (Builder $q, string $v) => $q->where('sales_invoices.status', $v), 'payment_status' => fn (Builder $q, string $v) => $q->where('sales_invoices.payment_status', $v)];
    }
}
