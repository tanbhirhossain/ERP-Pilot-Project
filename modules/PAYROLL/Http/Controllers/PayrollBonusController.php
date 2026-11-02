<?php

namespace Modules\PAYROLL\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\PAYROLL\Services\PayrollBonusService;
use Modules\PAYROLL\Models\FestivalBonusRun;
use Modules\CORE\Support\PaginatedResource;

final class PayrollBonusController extends Controller
{
    public function index(Request $request, PayrollBonusService $bonuses): Response
    {
        return Inertia::render('PAYROLL::Bonuses/Index', [
            'policies' => $bonuses->policies(),
            'runs' => PaginatedResource::from($bonuses->recentRuns($request->integer('per_page',25),$request->input('search'))->through(fn ($row) => $row->toArray())),
            'defaultYear' => (int) now()->format('Y'),
            'defaultMonth' => (int) now()->format('m'),
        ]);
    }

    public function storePolicy(Request $request, PayrollBonusService $bonuses): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'calculation_type' => ['required', 'in:fixed,percentage'],
            'amount' => ['required', 'numeric', 'min:0'],
            'calculation_base' => ['required', 'in:basic,gross'],
            'minimum_service_months' => ['required', 'integer', 'min:0', 'max:600'],
            'bonuses_per_year' => ['required', 'integer', 'between:1,12'],
        ]);
        $bonuses->savePolicy($data + ['is_active' => true]);
        return back()->with('success', 'Festival bonus policy saved.');
    }

    public function release(Request $request, FestivalBonusRun $run, PayrollBonusService $bonuses): RedirectResponse
    {
        $bonuses->release($run, $request->user()?->id);
        return back()->with('success', 'Festival bonus run released for payout.');
    }

    public function generate(Request $request, PayrollBonusService $bonuses): RedirectResponse
    {
        $data = $request->validate([
            'festival_bonus_policy_id' => ['required', 'exists:festival_bonus_policies,id'],
            'pay_year' => ['required', 'integer', 'between:2000,2100'],
            'pay_month' => ['required', 'integer', 'between:1,12'],
        ]);
        $run = $bonuses->generate((int) $data['festival_bonus_policy_id'], (int) $data['pay_year'], (int) $data['pay_month'], $request->user()?->id);
        return back()->with('success', 'Festival bonus run #'.$run->id.' generated for '.$run->employee_count.' employees.');
    }
}
