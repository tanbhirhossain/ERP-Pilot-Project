<?php

namespace Modules\INVENTORY\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\INVENTORY\Models\Transfer;
use Modules\INVENTORY\Requests\StoreTransferRequest;
use Modules\INVENTORY\Services\ProductService;
use Modules\INVENTORY\Services\TransferService;
use Modules\INVENTORY\Services\WarehouseService;

class TransferController extends Controller
{
    public function __construct(private readonly TransferService $svc, private readonly WarehouseService $warehouses, private readonly ProductService $products) {}

    public function index(Request $r): Response
    {
        $dt = DataTableQuery::fromRequest($r, ['transfer_no', 'status', 'created_at'], ['status', 'from_warehouse_id', 'to_warehouse_id']);

        return Inertia::render('INVENTORY::Transfers/Index', [
            'transfers' => PaginatedResource::from($this->svc->paginate($dt)->through(fn (Transfer $t) => [
                'id' => $t->id, 'transfer_no' => $t->transfer_no, 'from_warehouse' => $t->fromWarehouse ? ['name' => $t->fromWarehouse->name] : null,
                'to_warehouse' => $t->toWarehouse ? ['name' => $t->toWarehouse->name] : null, 'status' => $t->status, 'items_count' => $t->items_count, 'requester' => $t->requester?->name, 'created_at' => $t->created_at?->toIso8601String(),
            ])),
            'warehouseOptions' => $this->warehouses->options(null, 100),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('INVENTORY::Transfers/Create', ['warehouseOptions' => $this->warehouses->options(null, 100)]);
    }

    public function store(StoreTransferRequest $r): RedirectResponse
    {
        $t = $this->svc->create($r->validated());

        return redirect()->route('inventory.transfers.show', $t->id)->with('success', "Transfer {$t->transfer_no} created.");
    }

    public function show(Transfer $transfer): Response
    {
        $transfer->load(['fromWarehouse', 'toWarehouse', 'items.product', 'requester', 'approver']);

        return Inertia::render('INVENTORY::Transfers/Show', ['transfer' => $transfer]);
    }

    public function edit(Transfer $transfer): Response
    {
        $transfer->load('items');

        return Inertia::render('INVENTORY::Transfers/Edit', ['transfer' => $transfer, 'warehouseOptions' => $this->warehouses->options(null, 100)]);
    }

    public function update(StoreTransferRequest $r, Transfer $transfer): RedirectResponse
    {
        $this->svc->update($transfer, $r->validated());

        return redirect()->route('inventory.transfers.show', $transfer->id)->with('success', 'Transfer updated.');
    }

    public function destroy(Transfer $transfer): RedirectResponse
    {
        try {
            $this->svc->delete($transfer);

            return redirect()->route('inventory.transfers.index')->with('success', 'Transfer deleted.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function ship(Transfer $transfer): RedirectResponse
    {
        try {
            $this->svc->ship($transfer);

            return back()->with('success', "Transfer {$transfer->transfer_no} shipped.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function receive(Transfer $transfer): RedirectResponse
    {
        try {
            $this->svc->receive($transfer);

            return back()->with('success', "Transfer {$transfer->transfer_no} received.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function cancel(Request $r, Transfer $transfer): RedirectResponse
    {
        $this->svc->cancel($transfer, $r->input('reason'));

        return back()->with('success', 'Transfer cancelled.');
    }
}
