<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\PaginatedResource;
use Modules\HRM\Models\Employee\EmployeeResignation;
use Modules\HRM\Services\EmployeeResignationService;

final class EmployeeResignationController extends Controller
{
    public function index(Request $request, EmployeeResignationService $service): Response
    {
        return Inertia::render('HRM::Resignations/Index', [
            'requests' => PaginatedResource::from($service->paginate(['status'=>$request->input('filters.status',$request->input('status')),'search'=>$request->input('search')], $request->integer('per_page', 25))->through(fn (EmployeeResignation $row) => ['id'=>$row->id,'employee_id'=>$row->employee_id,'employee'=>$row->employee?->full_name ?? $row->employee_id,'last_working_date'=>$row->last_working_date?->toDateString(),'reason'=>$row->reason,'status'=>$row->status,'approved_at'=>$row->approved_at?->toIso8601String(),'decision_note'=>$row->decision_note])),
            'filters' => $request->only('status', 'search'),
        ]);
    }

    public function store(Request $request, EmployeeResignationService $service): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'string', 'exists:employees,employee_id'],
            'last_working_date' => ['required', 'date', 'after_or_equal:today'],
            'reason' => ['required', 'string', 'max:5000'],
        ]);
        try { $service->request($data, $request->user()?->id); }
        catch (\Throwable $e) { return back()->withErrors(['employee_id' => $e->getMessage()])->withInput(); }
        return back()->with('success', 'Resignation request submitted for approval.');
    }

    public function decide(Request $request, EmployeeResignation $resignation, EmployeeResignationService $service): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:Approved,Rejected'], 'decision_note' => ['nullable', 'string', 'max:2000']]);
        try { $service->decide($resignation, $data['decision'], $request->user()?->id, $data['decision_note'] ?? null); }
        catch (\Throwable $e) { return back()->with('error', $e->getMessage()); }
        return back()->with('success', 'Resignation request '.$data['decision'].'.');
    }
}
