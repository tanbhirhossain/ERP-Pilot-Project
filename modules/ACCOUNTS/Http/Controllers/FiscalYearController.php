<?php

namespace Modules\ACCOUNTS\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\ACCOUNTS\Models\FiscalYear;
use Modules\ACCOUNTS\Requests\StoreFiscalYearRequest;
use Modules\ACCOUNTS\Services\FiscalYearService;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;

class FiscalYearController extends Controller
{
    public function __construct(private readonly FiscalYearService $svc) {}

    public function index(Request $r): Response
    {
        $dt = DataTableQuery::fromRequest($r, ['name', 'start_date'], []);

        return Inertia::render('ACCOUNTS::FiscalYears/Index', [
            'fiscalYears' => PaginatedResource::from($this->svc->paginate($dt)->through(fn (FiscalYear $f) => [
                'id' => $f->id, 'name' => $f->name, 'code' => $f->code, 'start_date' => $f->start_date->format('Y-m-d'), 'end_date' => $f->end_date->format('Y-m-d'),
                'status' => $f->status, 'periods_count' => $f->periods_count, 'created_at' => $f->created_at?->toIso8601String(),
            ])),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('ACCOUNTS::FiscalYears/Create');
    }

    public function store(StoreFiscalYearRequest $r): RedirectResponse
    {
        $fy = $this->svc->create($r->validated());

        return redirect()->route('accounts.fiscal-years.show', $fy->id)->with('success', "Fiscal year {$fy->name} created with 12 periods.");
    }

    public function show(FiscalYear $fiscalYear): Response
    {
        $fiscalYear->load(['periods', 'journals']);

        return Inertia::render('ACCOUNTS::FiscalYears/Show', ['fiscalYear' => $fiscalYear]);
    }

    public function edit(FiscalYear $fiscalYear): Response
    {
        return Inertia::render('ACCOUNTS::FiscalYears/Edit', ['fiscalYear' => $fiscalYear]);
    }

    public function update(StoreFiscalYearRequest $r, FiscalYear $fiscalYear): RedirectResponse
    {
        $this->svc->update($fiscalYear, $r->validated());

        return redirect()->route('accounts.fiscal-years.show', $fiscalYear->id)->with('success', 'Fiscal year updated.');
    }

    public function close(FiscalYear $fiscalYear): RedirectResponse
    {
        try {
            $this->svc->close($fiscalYear);

            return back()->with('success', "Fiscal year {$fiscalYear->name} closed.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function reopen(FiscalYear $fiscalYear): RedirectResponse
    {
        try {
            $this->svc->reopen($fiscalYear);

            return back()->with('success', "Fiscal year {$fiscalYear->name} reopened.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
