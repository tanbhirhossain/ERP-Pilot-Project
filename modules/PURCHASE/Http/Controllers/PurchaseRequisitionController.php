<?php

namespace Modules\PURCHASE\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Models\Attachment;
use Modules\CORE\Services\ActivityTimelineService;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\PURCHASE\Models\PurchaseRequisition;
use Modules\PURCHASE\Requests\PurchaseRequisitionRequest;
use Modules\PURCHASE\Services\PurchaseRequisitionService;

/**
 * F18 — the requisition desk: a list, a form, and one record where the decision gets made.
 *
 * Every state change goes through the service and comes back as a redirect with a flash, because the
 * alternative (a JSON action that re-renders a grid) hides *why* something was refused. A budget refusal
 * and a self-approval refusal are sentences a requester can act on; they are worth a page reload.
 */
class PurchaseRequisitionController extends Controller
{
    public function __construct(private readonly PurchaseRequisitionService $svc) {}

    public function index(Request $r): Response
    {
        $dt = DataTableQuery::fromRequest($r, ['req_no', 'title'], ['status']);

        return Inertia::render('PURCHASE::Requisitions/Index', [
            'requisitions' => PaginatedResource::from($this->svc->paginate($dt)->through(fn (PurchaseRequisition $q): array => [
                'id' => $q->id,
                'req_no' => $q->req_no,
                'title' => $q->title,
                'department' => $q->department,
                'requester' => $q->requester_name ?? $q->requester?->name,
                'warehouse' => $q->warehouse?->name,
                'need_by' => $q->need_by?->toDateString(),
                'estimated_amount' => $q->estimated_amount,
                'status' => $q->status,
                'created_at' => $q->created_at?->toIso8601String(),
            ])),
            'statusCounts' => PurchaseRequisition::query()
                ->selectRaw('status, count(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status'),
            'canManage' => $r->user()?->can('purchase.requisitions.manage') ?? false,
            'canApprove' => $r->user()?->can('purchase.requisitions.approve') ?? false,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('PURCHASE::Requisitions/Create', [
            'canApprove' => request()->user()?->can('purchase.requisitions.approve') ?? false,
        ]);
    }

    public function store(PurchaseRequisitionRequest $r): RedirectResponse
    {
        $req = $this->svc->create($r->validated());

        return redirect()
            ->route('purchase.requisitions.show', $req->id)
            ->with('success', "Requisition {$req->req_no} saved as a draft.");
    }

    public function show(PurchaseRequisition $requisition, ActivityTimelineService $timeline): Response
    {
        $req = $this->svc->findOrFail($requisition->id);

        return Inertia::render('PURCHASE::Requisitions/Show', [
            'requisition' => $req,
            'budget' => $this->svc->budgetPosition($req),
            'activities' => $timeline->for($req),
            'attachments' => Attachment::where('attachable_type', $req::class)
                ->where('attachable_id', $req->id)
                ->with('uploader:id,name')->orderByDesc('created_at')->get(),
            'canManage' => request()->user()?->can('purchase.requisitions.manage') ?? false,
            'canApprove' => request()->user()?->can('purchase.requisitions.approve') ?? false,
            'canQuote' => request()->user()?->can('purchase.rfq.manage') ?? false,
        ]);
    }

    public function edit(PurchaseRequisition $requisition): Response
    {
        $requisition->load('items');

        return Inertia::render('PURCHASE::Requisitions/Edit', [
            'requisition' => $requisition,
            'canApprove' => request()->user()?->can('purchase.requisitions.approve') ?? false,
        ]);
    }

    public function update(PurchaseRequisitionRequest $r, PurchaseRequisition $requisition): RedirectResponse
    {
        try {
            $this->svc->update($requisition, $r->validated());

            return redirect()->route('purchase.requisitions.show', $requisition->id)->with('success', 'Requisition updated.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function destroy(PurchaseRequisition $requisition): RedirectResponse
    {
        try {
            $this->svc->delete($requisition);

            return redirect()->route('purchase.requisitions.index')->with('success', "Draft {$requisition->req_no} deleted.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function submit(PurchaseRequisition $requisition): RedirectResponse
    {
        try {
            // No override here on purpose: the person submitting is the person asking, and a budget
            // breach is only ever waived by the approver on the approve action.
            $req = $this->svc->submit($requisition);

            return redirect()->route('purchase.requisitions.show', $req->id)->with('success', "{$req->req_no} submitted for approval.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function approve(Request $r, PurchaseRequisition $requisition): RedirectResponse
    {
        try {
            $req = $this->svc->approve($requisition, $r->boolean('override_budget'));

            return redirect()->route('purchase.requisitions.show', $req->id)->with('success', "{$req->req_no} approved — it can now be put out to quote or ordered.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function reject(Request $r, PurchaseRequisition $requisition): RedirectResponse
    {
        $data = $r->validate(['reason' => ['required', 'string', 'max:255']]);

        try {
            $req = $this->svc->reject($requisition, (string) $data['reason']);

            return redirect()->route('purchase.requisitions.show', $req->id)->with('success', "{$req->req_no} rejected.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function cancel(PurchaseRequisition $requisition): RedirectResponse
    {
        try {
            $req = $this->svc->cancel($requisition);

            return redirect()->route('purchase.requisitions.show', $req->id)->with('success', "{$req->req_no} cancelled.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Ask the market: creates the draft round and takes the user straight to it. */
    public function requestQuotes(PurchaseRequisition $requisition): RedirectResponse
    {
        try {
            $rfq = $this->svc->openRfq($requisition);

            return redirect()
                ->route('purchase.rfqs.show', $rfq->id)
                ->with('success', "Quote round {$rfq->rfq_no} opened from {$requisition->req_no} — send it to vendors to start receiving prices.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
