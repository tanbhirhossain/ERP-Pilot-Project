<?php

namespace Modules\CORE\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Models\ApprovalPolicy;
use Modules\CORE\Models\ApprovalRequest;
use Modules\CORE\Services\ApprovalService;

/**
 * Approval inbox + policy matrix (Phase 1 — F3).
 *
 * The inbox shows requests the signed-in user is allowed to decide (derived from
 * the policy permission), plus their own raised requests. Approve/reject are
 * delegated to ApprovalService, which enforces SoD server-side.
 */
class ApprovalController extends Controller
{
    public function __construct(private readonly ApprovalService $approvals) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        $pending = ApprovalRequest::with(['policy', 'requester:id,name'])
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->get()
            ->filter(fn ($r) => $r->policy && $user->can($r->policy->approver_permission))
            ->values()
            ->map(fn ($r) => $this->present($r));

        $mine = ApprovalRequest::with(['policy', 'decider:id,name'])
            ->where('requested_by', $user->id)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn ($r) => $this->present($r));

        $recent = ApprovalRequest::with(['policy', 'requester:id,name', 'decider:id,name'])
            ->whereIn('status', ['approved', 'rejected'])
            ->orderByDesc('decided_at')
            ->limit(20)
            ->get()
            ->map(fn ($r) => $this->present($r));

        return Inertia::render('CORE::Approvals/Index', [
            'pending' => $pending,
            'mine' => $mine,
            'recent' => $recent,
        ]);
    }

    public function policies(): Response
    {
        $policies = ApprovalPolicy::orderBy('document_type')->orderByDesc('priority')->get();

        return Inertia::render('CORE::Approvals/Policies', [
            'policies' => $policies,
            'documentTypes' => [
                ['value' => 'purchase_order', 'label' => 'Purchase Order (confirm)'],
                ['value' => 'vendor_bill', 'label' => 'Vendor Bill (post)'],
                ['value' => 'sales_invoice', 'label' => 'Sales Invoice (post)'],
            ],
        ]);
    }

    public function storePolicy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'document_type' => 'required|in:purchase_order,vendor_bill,sales_invoice',
            'min_amount' => 'required|numeric|min:0',
            'approver_permission' => 'required|string|max:128',
            'require_segregation' => 'boolean',
            'priority' => 'integer|min:0',
            'description' => 'nullable|string|max:500',
        ]);

        ApprovalPolicy::create($data + ['is_active' => true]);

        return back()->with('success', 'Approval policy created.');
    }

    public function togglePolicy(ApprovalPolicy $policy): RedirectResponse
    {
        $policy->update(['is_active' => ! $policy->is_active]);

        return back()->with('success', $policy->is_active ? 'Policy activated.' : 'Policy deactivated.');
    }

    public function destroyPolicy(ApprovalPolicy $policy): RedirectResponse
    {
        $policy->delete();

        return back()->with('success', 'Policy deleted.');
    }

    public function approve(Request $request, ApprovalRequest $approval): RedirectResponse
    {
        $data = $request->validate(['note' => 'nullable|string|max:500']);

        try {
            $this->approvals->approve($approval, $request->user(), $data['note'] ?? null);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Request approved.');
    }

    public function reject(Request $request, ApprovalRequest $approval): RedirectResponse
    {
        $data = $request->validate(['note' => 'required|string|max:500']);

        try {
            $this->approvals->reject($approval, $request->user(), $data['note']);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Request rejected.');
    }

    private function present(ApprovalRequest $r): array
    {
        return [
            'id' => $r->id,
            'document_type' => $r->document_type,
            'document_no' => $r->document_no,
            'amount' => $r->amount,
            'status' => $r->status,
            'policy' => $r->policy ? ['name' => $r->policy->name, 'approver_permission' => $r->policy->approver_permission] : null,
            'requester' => $r->requester?->name,
            'decider' => $r->decider?->name,
            'decision_note' => $r->decision_note,
            'requested_at' => $r->created_at?->diffForHumans(),
            'decided_at' => $r->decided_at?->diffForHumans(),
        ];
    }
}
