<?php

namespace Modules\ACCOUNTS\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\ACCOUNTS\Models\Account;
use Modules\ACCOUNTS\Models\Budget;
use Modules\ACCOUNTS\Models\BudgetLine;
use Modules\ACCOUNTS\Models\FiscalYear;
use Modules\ACCOUNTS\Services\BudgetService;

/**
 * F14 — budget authoring.
 *
 * One screen holds the list, the create form and a per-budget line editor (pick a budget, add
 * account × month × amount lines). Approving needs a line and an open fiscal year; locking then
 * freezes the lines. Actuals are never entered here — they come from the posted ledger — so this
 * controller only ever touches the plan.
 */
class BudgetController extends Controller
{
    public function __construct(private readonly BudgetService $budgets) {}

    public function index(Request $r): Response
    {
        $selectedId = $r->input('budget');
        $period = $r->input('period');

        $budgets = Budget::withCount('lines')->with('fiscalYear:id,name')->latest('id')->get()
            ->map(fn (Budget $b) => [
                'id' => $b->id, 'name' => $b->name, 'code' => $b->code, 'department' => $b->department,
                'status' => $b->status, 'line_count' => $b->lines_count,
                'budgeted' => (float) $b->lines()->when($period, fn ($q) => $q->where('period', $period))->sum('amount'),
                'fiscal_year' => $b->fiscalYear?->name,
            ]);

        $selected = $selectedId ? Budget::find((int) $selectedId) : null;

        return Inertia::render('ACCOUNTS::Budgets/Index', [
            'budgets' => $budgets,
            'summary' => $this->budgets->summary($period),
            'fiscalYearOptions' => FiscalYear::orderByDesc('start_date')->get()
                ->map(fn ($f) => ['value' => $f->id, 'label' => $f->name.' · '.$f->start_date->format('M Y').'–'.$f->end_date->format('M Y').' ('.$f->status.')'])->values(),
            'accountOptions' => Account::where('is_posting', true)->where('is_active', true)
                ->orderBy('code')->limit(500)->get()
                ->map(fn ($a) => ['value' => $a->id, 'label' => $a->code.' · '.$a->name])->values(),
            'selectedId' => $selected?->id,
            'selectedLines' => $selected
                ? $selected->lines()->with('account:id,code,name')->orderBy('period')->orderBy('account_id')->get()
                    ->map(fn (BudgetLine $l) => ['id' => $l->id, 'account_id' => $l->account_id, 'account' => $l->account?->code.' · '.$l->account?->name, 'period' => $l->period, 'amount' => (float) $l->amount, 'notes' => $l->notes])
                : [],
            'report' => $selected ? $this->budgets->report($selected, $period) : null,
            'period' => $period,
            'canManage' => (bool) $r->user()?->can('accounts.budgets.manage'),
        ]);
    }

    public function store(Request $r)
    {
        $data = $r->validate([
            'name' => ['required', 'string', 'max:150'],
            'fiscal_year_id' => ['required', 'integer', 'exists:acc_fiscal_years,id'],
            'department' => ['nullable', 'string', 'max:120'],
            'currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $budget = Budget::create($data + ['code' => $this->nextCode($data['fiscal_year_id']), 'status' => 'draft', 'created_by' => $r->user()?->id]);

        return back()->with('success', 'Budget '.$budget->code.' created as a draft.')->with('select', $budget->id);
    }

    public function addLine(Request $r, Budget $budget)
    {
        $data = $r->validate([
            'account_id' => ['required', 'integer', 'exists:acc_accounts,id'],
            'period' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'amount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $this->budgets->upsertLine($budget, (int) $data['account_id'], $data['period'], (float) $data['amount'], $data['notes'] ?? null);

        return back()->with('success', 'Budget line saved.');
    }

    public function removeLine(Request $r, BudgetLine $line)
    {
        if ($line->budget && $line->budget->status === 'locked') {
            abort(422, 'This budget is locked.');
        }
        $line->delete();

        return back()->with('success', 'Budget line removed.');
    }

    public function approve(Request $r, Budget $budget)
    {
        $this->budgets->approve($budget, $r->user()?->id);

        return back()->with('success', 'Budget approved.');
    }

    public function lock(Request $r, Budget $budget)
    {
        $this->budgets->lock($budget);

        return back()->with('success', 'Budget locked against further edits.');
    }

    /** BDG-<fyid>-#### continuing from the highest existing code. */
    private function nextCode(int $fiscalYearId): string
    {
        $prefix = "BDG-{$fiscalYearId}-";
        $last = Budget::where('code', 'like', $prefix.'%')->orderByDesc('code')->value('code');
        $seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }
}
