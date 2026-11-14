<?php

namespace Modules\ACCOUNTS\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\ACCOUNTS\Models\Budget;
use Modules\ACCOUNTS\Services\BudgetService;

/**
 * F14 — budget vs actual (variance) report.
 *
 * A read-only working report: choose a budget (and optionally one month), see planned vs the posted
 * ledger, the variance and the accounts running the wrong way. Because the actual column is a live
 * read of `acc_account_ledger`, the report is always reconciled to the books at the moment it is
 * opened — which is the whole point of not persisting actuals.
 */
class BudgetVarianceController extends Controller
{
    public function __construct(private readonly BudgetService $budgets) {}

    public function index(Request $r): Response
    {
        $budgets = Budget::with('fiscalYear:id,name')->orderByDesc('id')->get()
            ->map(fn (Budget $b) => ['id' => $b->id, 'name' => $b->name, 'code' => $b->code, 'status' => $b->status, 'fiscal_year' => $b->fiscalYear?->name]);

        $selectedId = $r->input('budget');
        $budget = $selectedId ? Budget::find((int) $selectedId) : ($budgets->first() ? Budget::find($budgets->first()['id']) : null);

        return Inertia::render('ACCOUNTS::Budgets/Variance', [
            'budgets' => $budgets,
            'selectedId' => $budget?->id,
            'period' => $r->input('period'),
            'report' => $budget ? $this->budgets->report($budget, $r->input('period')) : null,
        ]);
    }
}
