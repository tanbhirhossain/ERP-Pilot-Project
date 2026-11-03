<?php

namespace Modules\PARTNER\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\PARTNER\Models\PriceList;
use Modules\PARTNER\Requests\StorePriceListRequest;
use Modules\PARTNER\Services\PriceListService;

class PriceListController extends Controller
{
    public function __construct(private readonly PriceListService $svc) {}

    public function index(Request $request): Response
    {
        $dt = DataTableQuery::fromRequest($request, ['name', 'code', 'currency', 'is_default', 'created_at'], ['is_active', 'currency']);
        return Inertia::render('PARTNER::PriceLists/Index', [
            'priceLists' => PaginatedResource::from($this->svc->paginate($dt)->through(fn (PriceList $pl) => [
                'id' => $pl->id, 'name' => $pl->name, 'code' => $pl->code, 'currency' => $pl->currency,
                'is_default' => $pl->is_default, 'is_active' => $pl->is_active,
                'effective_from' => $pl->effective_from?->toDateString(), 'effective_to' => $pl->effective_to?->toDateString(),
                'items_count' => $pl->items_count, 'partners_count' => $pl->partners_count,
                'created_at' => $pl->created_at?->toIso8601String(),
            ])),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('PARTNER::PriceLists/Create');
    }

    public function store(StorePriceListRequest $request): RedirectResponse
    {
        $pl = $this->svc->create($request->validated());
        return redirect()->route('partner.pricelists.index')->with('success', "Price list {$pl->code} created.");
    }

    public function show(PriceList $priceList): Response
    {
        $priceList->load(['items']);
        return Inertia::render('PARTNER::PriceLists/Show', [
            'priceList' => $priceList,
        ]);
    }

    public function edit(PriceList $priceList): Response
    {
        $priceList->load(['items']);
        return Inertia::render('PARTNER::PriceLists/Edit', [
            'priceList' => $priceList,
        ]);
    }

    public function update(StorePriceListRequest $request, PriceList $priceList): RedirectResponse
    {
        $this->svc->update($priceList, $request->validated());
        return redirect()->route('partner.pricelists.index')->with('success', "Price list {$priceList->code} updated.");
    }

    public function destroy(PriceList $priceList): RedirectResponse
    {
        try {
            $this->svc->delete($priceList);
            return redirect()->route('partner.pricelists.index')->with('success', 'Price list deleted.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
