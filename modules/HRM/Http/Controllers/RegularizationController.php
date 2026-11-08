<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\HRM\Models\AttendanceRegularization;
use Modules\HRM\Services\RegularizationService;

class RegularizationController extends Controller
{
    public function __construct(private readonly RegularizationService $svc) {}

    public function index(Request $r): Response
    {
        $q = AttendanceRegularization::with(['employee:id,employee_id,full_name', 'reviewer:id,name'])->latest();
        if ($r->input('status')) {
            $q->where('status', $r->input('status'));
        }
        if ($r->input('employee_id')) {
            $q->where('employee_id', $r->input('employee_id'));
        }

        return Inertia::render('HRM::Regularizations/Index', [
            'regularizations' => $q->paginate(20)->withQueryString(),
            'filters' => $r->only(['status', 'employee_id']),
        ]);
    }

    public function store(Request $r)
    {
        $data = $r->validate([
            'employee_id' => ['required', 'exists:employees,employee_id'],
            'duty_date' => ['required', 'date', 'before_or_equal:today'],
            'regularization_type' => ['required', 'in:Missing In,Missing Out,Short Hours,Wrong Shift'],
            'assigned_shift_id' => ['nullable', 'exists:roster_shifts,id'],
            'requested_in_time' => ['nullable', 'date_format:H:i'],
            'requested_out_time' => ['nullable', 'date_format:H:i'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);
        $reg = $this->svc->request($data);

        return back()->with('success', "Regularization {$reg->regularization_no} submitted — pending review.");
    }

    public function review(Request $r, AttendanceRegularization $regularization)
    {
        $data = $r->validate([
            'decision' => ['required', 'in:Approved,Rejected'],
            'review_remarks' => ['nullable', 'string', 'max:1000'],
        ]);
        $this->svc->review($regularization, $data['decision'], $data['review_remarks'] ?? null);

        return back()->with('success', "Regularization {$regularization->regularization_no} {$data['decision']}. Attendance daily ".($data['decision'] === 'Approved' ? 'patched and recalculated to Present.' : 'unchanged.'));
    }

    public function cancel(AttendanceRegularization $regularization)
    {
        $this->svc->cancel($regularization);

        return back()->with('success', 'Cancelled.');
    }
}
