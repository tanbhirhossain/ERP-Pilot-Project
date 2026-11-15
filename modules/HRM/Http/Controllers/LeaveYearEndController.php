<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Leave\HrmLeaveBalance;
use Modules\HRM\Services\LeaveWorkspaceService;
use Modules\HRM\Services\LeaveYearEndService;

/**
 * Year-end: carry forward, lapse, encash.
 *
 * Closing a leave year is the one irreversible action in this module, and the old screen offered it as
 * a button under a gradient banner whose confirmation text promised an "admin undo" that did not
 * exist. It now runs a dry run first — the exact same computation, written nowhere — and the close
 * itself is reversible from this page.
 */
class LeaveYearEndController extends Controller
{
    public function __construct(
        private readonly LeaveYearEndService $yearEnd,
        private readonly LeaveWorkspaceService $workspace,
    ) {}

    public function index(Request $r): Response
    {
        $year = (int) ($r->integer('year') ?: now()->format('Y'));

        return Inertia::render('HRM::Leave/YearEnd', [
            'year' => $year,
            'preview' => $this->yearEnd->preview($year),
            'summary' => $this->yearEnd->yearSummary($year),
            'types' => $this->workspace->typeOverview($year),
            'closed' => HrmLeaveBalance::query()->where('year', $year)->where('is_closed', true)->exists(),
            'next_year_prepared' => HrmLeaveBalance::query()->where('year', $year + 1)->exists(),
            'canManage' => (bool) auth()->user()?->can('hrm.leave-year-end.manage'),
        ]);
    }

    public function closeYear(Request $r): RedirectResponse
    {
        $data = $r->validate(['year' => ['required', 'integer', 'min:2020', 'max:2100']]);
        $res = $this->yearEnd->close((int) $data['year'], $r->user()?->id);

        return back()->with('success', sprintf(
            'Leave year %d closed: %d balance rows created, %d updated, %d already closed and left untouched, across %d employees and %d leave types. Carry-forward now opens %d.',
            $res['year'], $res['created'], $res['updated'], $res['skipped'], $res['employees'], $res['types'], $res['year'] + 1,
        ));
    }

    public function reopenYear(Request $r): RedirectResponse
    {
        $data = $r->validate(['year' => ['required', 'integer', 'min:2020', 'max:2100']]);
        $res = $this->yearEnd->reopen((int) $data['year']);

        return back()->with('success', "Leave year {$res['year']} reopened: {$res['reopened']} balance rows unfrozen and next year's opening balances cleared. Re-close it when the corrections are in.");
    }

    public function encashPreview(Request $r): Response
    {
        $data = $r->validate([
            'employee_id' => ['nullable', 'exists:employees,employee_id'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ]);
        $year = (int) ($data['year'] ?? now()->format('Y'));
        $employeeId = $data['employee_id'] ?? null;

        return Inertia::render('HRM::Leave/EncashPreview', [
            'year' => $year,
            'employeeId' => $employeeId,
            'preview' => $employeeId !== null ? $this->yearEnd->encashmentPreview($employeeId, $year) : null,
            'employeeOptions' => Employee::query()
                ->where('is_active', true)
                ->orderBy('employee_id')
                ->limit(500)
                ->get(['employee_id', 'full_name', 'first_name', 'last_name'])
                ->map(fn (Employee $e): array => [
                    'value' => $e->employee_id,
                    'label' => trim(($e->full_name ?: $e->first_name.' '.$e->last_name).' ('.$e->employee_id.')'),
                ])
                ->all(),
        ]);
    }
}
