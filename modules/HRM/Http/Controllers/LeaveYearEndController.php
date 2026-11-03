<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\HRM\Models\Leave\LeaveType;
use Modules\HRM\Models\Leave\LeaveTypeSetting;
use Modules\HRM\Services\LeaveYearEndService;

class LeaveYearEndController extends Controller
{
    public function __construct(private readonly LeaveYearEndService $yearEnd) {}

    public function index(Request $r): Response
    {
        $year = (int)($r->integer('year') ?: date('Y'));
        $types = LeaveType::with('setting')->get();
        $summary = $this->yearEnd->yearSummary($year);
        $balances = \Modules\HRM\Models\Leave\HrmLeaveBalance::with('leaveType')->where('year',$year)->paginate(25)->withQueryString();
        return Inertia::render('HRM::LeaveYearEnd/Index', [
            'year' => $year,
            'types' => $types,
            'summary' => $summary,
            'balances' => $balances,
        ]);
    }

    public function updateSettings(Request $r): RedirectResponse
    {
        $data = $r->validate([
            'settings' => ['required','array'],
            'settings.*.leave_type_id' => ['required','exists:leave_types,id'],
            'settings.*.is_carry_forward' => ['boolean'],
            'settings.*.max_carry_days' => ['nullable','numeric','min:0','max:365'],
            'settings.*.carry_expiry_months' => ['nullable','integer','min:1','max:12'],
            'settings.*.is_encashable' => ['boolean'],
            'settings.*.encash_max_days' => ['nullable','numeric','min:0','max:365'],
            'settings.*.encash_salary_component' => ['nullable','in:basic,gross'],
        ]);
        foreach ($data['settings'] as $row) {
            LeaveTypeSetting::updateOrCreate(['leave_type_id'=>$row['leave_type_id']], [
                'is_carry_forward' => (bool)($row['is_carry_forward'] ?? false),
                'max_carry_days' => $row['max_carry_days'] ?? null,
                'carry_expiry_months' => $row['carry_expiry_months'] ?? 3,
                'is_encashable' => (bool)($row['is_encashable'] ?? false),
                'encash_max_days' => $row['encash_max_days'] ?? null,
                'encash_salary_component' => $row['encash_salary_component'] ?? 'basic',
            ]);
        }
        return back()->with('success','Leave carry/encash policies updated.');
    }

    public function closeYear(Request $r): RedirectResponse
    {
        $data = $r->validate(['year'=>['required','integer','min:2020','max:2100']]);
        $res = $this->yearEnd->closeYear((int)$data['year'], $r->user()?->id);
        return back()->with('success',"Year {$res['year']} closed: {$res['created']} created, {$res['updated']} updated, {$res['employees']} employees × {$res['types']} leave types. Next year's opening seeded from carry.");
    }

    public function encashPreview(Request $r): Response
    {
        $r->validate(['employee_id'=>['required','exists:employees,employee_id']]);
        $preview = $this->yearEnd->encashableForSettlement($r->input('employee_id'));
        return Inertia::render('HRM::LeaveYearEnd/EncashPreview', ['employee_id'=>$r->input('employee_id'),'preview'=>$preview]);
    }
}
