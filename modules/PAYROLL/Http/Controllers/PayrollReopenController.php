<?php

namespace Modules\PAYROLL\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\PAYROLL\Models\PayrollSalarySheet;
use Modules\PAYROLL\Models\PayrollSheetReopen;
use Modules\PAYROLL\Services\PayrollReopenService;

class PayrollReopenController extends Controller
{
    public function __construct(private readonly PayrollReopenService $reopens) {}

    public function index(): Response
    {
        // Only released sheets can be reopened (PayrollReopenService asserts it),
        // so the picker offers exactly those, and marks the ones that already
        // have a request in flight so finance cannot double-submit.
        $pendingSheetIds = PayrollSheetReopen::query()
            ->whereNull('approved_at')
            ->pluck('salary_sheet_id')
            ->all();

        $sheets = PayrollSalarySheet::query()
            ->where('status', 'released')
            ->latest('id')
            ->limit(100)
            ->get(['id', 'pay_year', 'pay_month', 'status'])
            ->map(fn (PayrollSalarySheet $sheet) => [
                'id' => $sheet->id,
                'label' => sprintf('%d-%02d  (sheet #%d)', $sheet->pay_year, (int) $sheet->pay_month, $sheet->id),
                'has_pending_request' => in_array($sheet->id, $pendingSheetIds, true),
            ])
            ->values();

        return Inertia::render('PAYROLL::Reopens/Index', [
            'pending' => $this->reopens->pending(),
            'recent' => PayrollSheetReopen::with('sheet')->latest()->limit(20)->get(),
            'sheets' => $sheets,
        ]);
    }

    public function request(Request $r, PayrollSalarySheet $sheet): RedirectResponse
    {
        $data = $r->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);

        // "Not released" and "already pending" are ordinary user errors, not
        // server faults — without this the service's DomainException 500s.
        try {
            $this->reopens->requestReopen($sheet, $data['reason'], $r->user()?->id);
        } catch (\DomainException $e) {
            return back()->withErrors(['reason' => $e->getMessage()])->withInput();
        }

        return back()->with('success', 'Reopen requested. Finance must approve to reverse JV and unlock sheet.');
    }

    public function approve(Request $r, PayrollSheetReopen $reopen): RedirectResponse
    {
        try {
            $this->reopens->approveReopen($reopen, $r->user()?->id);
        } catch (\DomainException $e) {
            return back()->withErrors(['reopen' => $e->getMessage()]);
        }

        return back()->with('success', 'Sheet reopened: accrual JV voided (reversal posted), status back to ready.');
    }
}
