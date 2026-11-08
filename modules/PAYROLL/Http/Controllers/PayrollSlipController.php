<?php

namespace Modules\PAYROLL\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\PAYROLL\Models\PayrollSlip;
use Modules\PAYROLL\Services\PayrollSalarySheetService;

final class PayrollSlipController extends Controller
{
    public function show(Request $request, PayrollSlip $slip): Response
    {
        return Inertia::render('PAYROLL::PaySlips/Show', ['slip' => $slip->load('salarySheet'), 'canPay' => (bool) $request->user()?->can('payroll.slips.pay')]);
    }

    public function setPaymentStatus(Request $request, PayrollSlip $slip, PayrollSalarySheetService $sheets): RedirectResponse
    {
        $data = $request->validate(['payment_status' => ['required', 'in:unpaid,paid,hold']]);
        $sheets->setSlipPaymentStatus($slip, $data['payment_status'], $request->user()?->id);

        return back()->with('success', 'Pay slip payment status updated.');
    }
}
