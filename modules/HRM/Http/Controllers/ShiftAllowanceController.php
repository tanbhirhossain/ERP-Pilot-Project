<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\HRM\Models\Roster\RosterShift;
use Modules\HRM\Models\ShiftAllowance;
use Modules\HRM\Services\ShiftAllowanceService;

class ShiftAllowanceController extends Controller
{
    public function index(Request $r): Response
    {
        return Inertia::render('HRM::ShiftAllowances/Index', [
            'allowances' => ShiftAllowance::with('shift:id,name,code')->latest()->paginate(20)->withQueryString(),
            'shifts' => RosterShift::select('id', 'name', 'code')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $r)
    {
        $data = $r->validate([
            'shift_id' => ['required', 'exists:roster_shifts,id'],
            'amount' => ['required', 'numeric', 'min:0', 'max:999999'],
            'type' => ['required', 'in:Fixed,Percent_Basic'],
            'applies_to' => ['nullable', 'in:All,Weekend,Holiday,Night'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_active' => ['boolean'],
        ]);
        ShiftAllowanceService::class; // ensure bound
        ShiftAllowance::create($data + ['created_by' => auth()->id()]);

        return back()->with('success', 'Shift allowance saved.');
    }

    public function destroy(ShiftAllowance $shiftAllowance)
    {
        $shiftAllowance->delete();

        return back()->with('success', 'Deleted.');
    }
}
