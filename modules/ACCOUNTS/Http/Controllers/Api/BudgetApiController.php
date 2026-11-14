<?php

namespace Modules\ACCOUNTS\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\ACCOUNTS\Models\Budget;
use Modules\ACCOUNTS\Services\BudgetService;
use Modules\CORE\Support\Api\ApiEndpoint;

/**
 * F13 — budgets and plan-vs-actual variance over the API (F14's numbers, exposed).
 *
 * Same service, same live-from-the-ledger actuals: an integration reading variance here
 * sees what the screen shows, because both ask `BudgetService` and nothing else.
 */
class BudgetApiController extends Controller
{
    public function __construct(private readonly BudgetService $budgets) {}

    #[ApiEndpoint(
        summary: 'List budgets',
        description: 'Plan headers with line counts. The amounts live on the variance report, not here.',
        query: [
            ['name' => 'fiscal_year_id', 'in' => 'query', 'schema' => ['type' => 'integer']],
            ['name' => 'approved_only', 'in' => 'query', 'schema' => ['type' => 'boolean'], 'description' => 'Approved and locked budgets only — excludes drafts.'],
        ],
        response: 'BudgetPage',
    )]
    public function index(Request $request): JsonResponse
    {
        $budgets = Budget::with('fiscalYear:id,name')
            ->withCount('lines')
            ->latest('id')
            ->when($request->integer('fiscal_year_id') > 0, fn ($q) => $q->where('fiscal_year_id', $request->integer('fiscal_year_id')))
            ->when($request->boolean('approved_only'), fn ($q) => $q->approved())
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $budgets->map(fn (Budget $b) => $this->summary($b))->values(),
        ]);
    }

    #[ApiEndpoint(
        summary: 'Budget versus actual for one budget',
        description: 'Actuals are read from the posted ledger at request time and never cached, so this cannot drift from the trial balance. `favourable` is nature-dependent — do not derive it from the sign of `variance`.',
        path: [
            ['name' => 'budget', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer'], 'description' => 'Budget id.'],
        ],
        query: [
            ['name' => 'period', 'in' => 'query', 'schema' => ['type' => 'string', 'pattern' => '^[0-9]{4}-[0-9]{2}$'], 'description' => 'YYYY-MM. Omit to aggregate every month the budget carries lines for.'],
        ],
        response: 'BudgetVarianceReport',
    )]
    public function variance(Request $request, Budget $budget): JsonResponse
    {
        $data = $request->validate(['period' => ['nullable', 'date_format:Y-m']]);

        $report = $this->budgets->report($budget, $data['period'] ?? null);

        // The service does its arithmetic in integer paise and hands back **floats**
        // (`dollars()` divides by 100), which is right for the screen — `formatCurrency`
        // and the utilisation bar want numbers. It is wrong on the wire, where the v1
        // contract is that every amount is a two-decimal string. Converting here rather
        // than in the service keeps one boundary honest instead of making the page
        // re-parse what it is about to render.
        $report['rows'] = array_map([$this, 'money'], $report['rows']);
        $report['unfavorable'] = array_map([$this, 'money'], $report['unfavorable']);
        $report['totals'] = $this->money($report['totals']);

        return response()->json($report);
    }

    /** Only the keys that hold money; `pct` stays a number, because a percent is not an amount. */
    private function money(array $row): array
    {
        foreach (['budget', 'actual', 'variance'] as $key) {
            if (array_key_exists($key, $row) && is_numeric($row[$key])) {
                $row[$key] = number_format((float) $row[$key], 2, '.', '');
            }
        }

        return $row;
    }

    private function summary(Budget $b): array
    {
        return [
            'id' => $b->id,
            'name' => $b->name,
            'code' => $b->code,
            'department' => $b->department,
            'status' => $b->status,
            'line_count' => $b->lines_count,
            'fiscal_year' => $b->fiscalYear?->name,
        ];
    }
}
