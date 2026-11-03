<?php

namespace Modules\SALES\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Support\DataTableQuery;
use Modules\SALES\Interfaces\PosRepositoryInterface;
use Modules\SALES\Models\PosSale;
use Modules\SALES\Models\PosSession;

class PosRepository implements PosRepositoryInterface
{
    public function sessionsPaginate(DataTableQuery $dt): LengthAwarePaginator
    {
        return PosSession::with(['warehouse', 'opener'])->latest()->paginate($dt->perPage, ['*'], 'page', $dt->page)->withQueryString();
    }
    public function salesPaginate(DataTableQuery $dt): LengthAwarePaginator
    {
        $q = PosSale::with(['warehouse', 'customer'])->latest();
        if ($dt->search) $q->where('pos_no', 'like', '%' . $dt->search . '%');
        return $q->paginate($dt->perPage, ['*'], 'page', $dt->page)->withQueryString();
    }
    public function findSessionOrFail(int $id): PosSession { return PosSession::with(['warehouse', 'sales'])->findOrFail($id); }
    public function findSaleOrFail(int $id): PosSale { return PosSale::with(['warehouse', 'customer', 'items.product', 'invoice'])->findOrFail($id); }

    public function openSession(array $data): PosSession
    {
        return DB::transaction(function () use ($data): PosSession {
            if (PosSession::where('warehouse_id', $data['warehouse_id'])->where('status', 'open')->exists()) {
                throw new \DomainException('An open session already exists for this warehouse. Close it first.');
            }
            $data['session_no'] = $this->nextSessionNo();
            $data['opened_by'] = auth()->id();
            $data['status'] = 'open';
            return PosSession::create($data);
        });
    }

    public function closeSession(PosSession $s, array $data): PosSession
    {
        if (! $s->isOpen()) throw new \DomainException('Only open sessions can be closed.');
        $s->update($data + ['status' => 'closed', 'closed_by' => auth()->id(), 'closed_at' => now()]);
        return $s->fresh();
    }

    public function createSale(array $data): PosSale
    {
        return DB::transaction(function () use ($data): PosSale {
            $items = $data['items'] ?? [];
            unset($data['items']);
            $data['pos_no'] = $this->nextPosNo();
            $data['created_by'] = auth()->id();
            $sale = PosSale::create($data);
            foreach ($items as $it) $sale->items()->create($it);
            // update session aggregates
            if ($sale->session_id) {
                PosSession::where('id', $sale->session_id)->update([
                    'total_sales' => DB::raw('total_sales + ' . (float) $sale->total_amount),
                    'transactions_count' => DB::raw('transactions_count + 1'),
                ]);
            }
            return $sale->load('items');
        });
    }

    public function nextPosNo(): string
    {
        $pad = (int) config('sales.code.pad', 5);
        $pref = config('sales.code.pos_prefix', 'POS-');
        return DB::transaction(function () use ($pref, $pad): string {
            $last = PosSale::where('pos_no', 'like', $pref . '%')->lockForUpdate()->orderByDesc('id')->value('pos_no');
            $n = $last ? ((int) substr($last, strlen($pref)) + 1) : 1;
            return $pref . str_pad((string) $n, $pad, '0', STR_PAD_LEFT);
        });
    }
    public function nextSessionNo(): string
    {
        $pref = 'POS-SESSION-';
        $pad = 5;
        return DB::transaction(function () use ($pref, $pad): string {
            $last = PosSession::where('session_no', 'like', $pref . '%')->lockForUpdate()->orderByDesc('id')->value('session_no');
            $n = $last ? ((int) substr($last, strlen($pref)) + 1) : 1;
            return $pref . str_pad((string) $n, $pad, '0', STR_PAD_LEFT);
        });
    }
}
