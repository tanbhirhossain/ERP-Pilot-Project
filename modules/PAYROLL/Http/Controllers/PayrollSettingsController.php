<?php

namespace Modules\PAYROLL\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Models\Role;
use Modules\PAYROLL\Services\PayrollSettingsService;

final class PayrollSettingsController extends Controller
{
    public function index(PayrollSettingsService $settings): Response
    {
        return Inertia::render('PAYROLL::Settings/Index', ['settings' => $settings->current(), 'availableRoles' => Role::query()->where('guard_name', 'web')->orderBy('name')->pluck('name')]);
    }

    public function update(Request $request, PayrollSettingsService $settings): RedirectResponse
    {
        $data = $request->validate([
            'currency' => ['required', 'string', 'size:3'],
            'punishment_days_per_salary_day' => ['required', 'integer', 'min:1', 'max:30'],
            'overtime_multiplier' => ['required', 'numeric', 'min:0', 'max:20'],
            'extra_duty_multiplier' => ['required', 'numeric', 'min:0', 'max:20'],
            'missing_punch_penalty_amount' => ['required', 'numeric', 'min:0'],
            'unpaid_leave_day_divisor' => ['required', 'numeric', 'gt:0', 'lte:31'],
            'loan_approval_steps_text' => ['nullable', 'string', 'max:300'],
        ]);
        $settings->update($data);

        return back()->with('success', 'Payroll policy settings saved.');
    }
}
