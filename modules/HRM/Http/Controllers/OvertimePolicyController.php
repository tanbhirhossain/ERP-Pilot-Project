<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\HRM\Models\OvertimePolicy;

class OvertimePolicyController extends Controller
{
    public function index(Request $r): Response
    {
        return Inertia::render('HRM::OvertimePolicies/Index', [
            'policies' => OvertimePolicy::latest()->paginate(20)->withQueryString(),
        ]);
    }

    public function store(Request $r)
    {
        $data = $r->validate([
            'name'=>['required','string','max:120'],
            'max_daily_hours'=>['required','numeric','min:0','max:24'],
            'max_monthly_hours'=>['required','numeric','min:0','max:500'],
            'ot_rate'=>['required','numeric','min:0','max:10'],
            'holiday_rate'=>['required','numeric','min:0','max:10'],
            'extra_duty_rate'=>['required','numeric','min:0','max:10'],
            'requires_approval'=>['boolean'],
            'is_active'=>['boolean'],
        ]);
        OvertimePolicy::create($data);
        return back()->with('success','OT policy saved.');
    }

    public function toggle(OvertimePolicy $overtimePolicy)
    {
        $overtimePolicy->update(['is_active'=>!$overtimePolicy->is_active]);
        return back()->with('success','Toggled.');
    }

    public function seed()
    {
        app(\Modules\HRM\Services\OvertimePolicyService::class)->seedDefault();
        return back()->with('success','Seeded default OT policy: 3h daily, 36h monthly, 1x/2x rates.');
    }
}
