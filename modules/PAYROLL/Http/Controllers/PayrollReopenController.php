<?php

namespace Modules\PAYROLL\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
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
        return Inertia::render('PAYROLL::Reopens/Index', [
            'pending' => $this->reopens->pending(),
            'recent' => PayrollSheetReopen::with('sheet')->latest()->limit(20)->get(),
        ]);
    }

    public function request(Request $r, PayrollSalarySheet $sheet): RedirectResponse
    {
        $data = $r->validate(['reason'=>['required','string','min:10','max:500']]);
        $this->reopens->requestReopen($sheet, $data['reason'], $r->user()?->id);
        return back()->with('success','Reopen requested. Finance must approve to reverse JV and unlock sheet.');
    }

    public function approve(Request $r, PayrollSheetReopen $reopen): RedirectResponse
    {
        $this->reopens->approveReopen($reopen, $r->user()?->id);
        return back()->with('success','Sheet reopened: accrual JV voided (reversal posted), status back to ready.');
    }
}
