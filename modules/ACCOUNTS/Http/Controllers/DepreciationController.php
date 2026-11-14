<?php

namespace Modules\ACCOUNTS\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\ACCOUNTS\Models\DepreciationRun;
use Modules\ACCOUNTS\Models\FixedAsset;
use Modules\ACCOUNTS\Services\DepreciationService;

/**
 * F15 — the monthly depreciation run.
 *
 * `index` previews what the selected period *would* charge (no writes) and lists past runs with the
 * journal each posted. `store` executes the run. The preview uses the same `computeCharge` the run
 * uses, so "what you see is what posts" — an accountant can eyeball a month before committing it.
 */
class DepreciationController extends Controller
{
    public function __construct(private readonly DepreciationService $depreciation) {}

    public function index(Request $r): Response
    {
        $month = $this->period($r);

        $preview = FixedAsset::depreciable()->with('category')->orderBy('id')->get()
            ->map(fn (FixedAsset $a) => ['asset' => $a->only(['id', 'asset_code', 'name']), 'charge' => $this->depreciation->computeCharge($a, $month)])
            ->filter(fn ($row) => $row['charge']['ok'])
            ->values();

        $alreadyRun = DepreciationRun::where('period', $month)->exists();

        return Inertia::render('ACCOUNTS::Assets/Depreciation', [
            'month' => $month,
            'alreadyRun' => $alreadyRun,
            'preview' => $preview,
            'previewTotal' => round($preview->sum(fn ($row) => $row['charge']['charge']), 2),
            'runs' => DepreciationRun::with('journal:id,journal_no,status')->latest('period')->limit(48)->get(),
            'summary' => $this->depreciation->summary(),
            'canManage' => (bool) $r->user()?->can('accounts.assets.manage'),
        ]);
    }

    public function store(Request $r)
    {
        $month = $this->period($r);

        $run = $this->depreciation->run($month, $r->user()?->id);

        return back()->with('success', $run->line_count > 0
            ? "Depreciation for {$month} posted: ".$run->line_count.' assets, '.number_format((float) $run->total_amount, 2).' to the ledger.'
            : "Depreciation for {$month}: nothing due — the period is closed off.");
    }

    private function period(Request $r): string
    {
        $month = (string) $r->input('period', now()->format('Y-m'));
        abort_unless(preg_match('/^\d{4}-\d{2}$/', $month) === 1, 422, 'Period must be YYYY-MM.');

        return $month;
    }
}
