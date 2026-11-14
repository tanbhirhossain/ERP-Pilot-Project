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
use Modules\PURCHASE\Models\PurchaseRfq;
use Modules\PURCHASE\Models\PurchaseRfqQuote;
use Modules\PURCHASE\Requests\PurchaseRfqAwardRequest;
use Modules\PURCHASE\Requests\PurchaseRfqQuoteRequest;
use Modules\PURCHASE\Requests\PurchaseRfqRequest;
use Modules\PURCHASE\Services\PurchaseRequisitionService;
use Modules\PURCHASE\Services\PurchaseRfqService;

/**
 * F18 — the quote round: ask, receive, compare, award.
 *
 * The comparison is computed by the service and handed to the page as props, not fetched by the browser.
 * Two reasons, both practical: a grid assembled client-side cannot be printed or exported as the record
 * of a decision, and "why did we buy from this vendor?" is a question the *file* has to answer years
 * later, not the screen.
 */
class PurchaseRfqController extends Controller
{
    public function __construct(private readonly PurchaseRfqService $svc) {}

    public function index(Request $r): Response
    {
        $dt = DataTableQuery::fromRequest($r, ['rfq_no', 'subject'], ['status']);

        return Inertia::render('PURCHASE::Rfqs/Index', [
            'rfqs' => PaginatedResource::from($this->svc->paginate($dt)->through(fn (PurchaseRfq $q): array => [
                'id' => $q->id,
                'rfq_no' => $q->rfq_no,
                'subject' => $q->subject,
                'requisition' => $q->requisition?->req_no,
                'due_on' => $q->due_on?->toDateString(),
                'status' => $q->status,
                'quotes' => $q->quotes->count(),
                'lines' => $q->lines->count(),
                'awarded' => $q->awarded_quote_id !== null,
                'created_at' => $q->created_at?->toIso8601String(),
            ])),
            'canManage' => $r->user()?->can('purchase.rfq.manage') ?? false,
        ]);
    }

    public function create(Request $r): Response
    {
        // Pre-linked from a requisition's "request quotes" button; a bare round is for market soundings.
        $req = $r->integer('requisition_id')
            ? app(PurchaseRequisitionService::class)->findOrFail($r->integer('requisition_id'))
            : null;

        return Inertia::render('PURCHASE::Rfqs/Create', [
            'requisitionId' => $req?->id,
            // The lines the buyer is being asked to price, carried over so nobody retypes a requisition.
            // Quantities are the outstanding remainder, not the original ask — an already-ordered line
            // should not be quoted again.
            'requisition' => $req ? [
                'id' => $req->id,
                'req_no' => $req->req_no,
                'title' => $req->title,
                'warehouse' => $req->warehouse?->name,
                'lines' => $req->items
                    ->filter(fn ($item): bool => $item->remainingQty() > 0)
                    ->map(fn ($item): array => [
                        'requisition_item_id' => $item->id,
                        'description' => $item->description,
                        'qty' => (string) $item->remainingQty(),
                        'uom_id' => $item->uom_id,
                    ])
                    ->values()
                    ->all(),
            ] : null,
        ]);
    }

    public function store(PurchaseRfqRequest $r): RedirectResponse
    {
        $rfq = $this->svc->create($r->validated());

        return redirect()->route('purchase.rfqs.show', $rfq->id)->with('success', "Quote round {$rfq->rfq_no} created.");
    }

    public function show(PurchaseRfq $rfq, ActivityTimelineService $timeline): Response
    {
        $rfq = $this->svc->findOrFail($rfq->id);

        return Inertia::render('PURCHASE::Rfqs/Show', [
            'rfq' => $rfq,
            'compare' => $this->svc->compare($rfq),
            'activities' => $timeline->for($rfq),
            'attachments' => Attachment::where('attachable_type', $rfq::class)
                ->where('attachable_id', $rfq->id)
                ->with('uploader:id,name')->orderByDesc('created_at')->get(),
            'canManage' => request()->user()?->can('purchase.rfq.manage') ?? false,
            // Receiving a quote is clerical; choosing a winner is not. Two grants, so a stores clerk can
            // key in prices without being able to commit the company to them.
            'canAward' => request()->user()?->can('purchase.rfq.award') ?? false,
        ]);
    }

    public function update(PurchaseRfqRequest $r, PurchaseRfq $rfq): RedirectResponse
    {
        try {
            $fresh = $this->svc->update($rfq, $r->validated());

            return redirect()->route('purchase.rfqs.show', $fresh->id)->with('success', 'Round updated.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function destroy(PurchaseRfq $rfq): RedirectResponse
    {
        try {
            $this->svc->delete($rfq);

            return redirect()->route('purchase.rfqs.index')->with('success', 'Draft round deleted.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function send(PurchaseRfq $rfq): RedirectResponse
    {
        try {
            $fresh = $this->svc->send($rfq);

            return redirect()->route('purchase.rfqs.show', $fresh->id)->with('success', "{$fresh->rfq_no} is open for quotes.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function close(PurchaseRfq $rfq): RedirectResponse
    {
        try {
            $fresh = $this->svc->close($rfq);

            return redirect()->route('purchase.rfqs.show', $fresh->id)->with('success', "{$fresh->rfq_no} closed to new quotes.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function cancel(PurchaseRfq $rfq): RedirectResponse
    {
        try {
            $fresh = $this->svc->cancel($rfq);

            return redirect()->route('purchase.rfqs.show', $fresh->id)->with('success', "{$fresh->rfq_no} cancelled.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function quote(PurchaseRfqQuoteRequest $r, PurchaseRfq $rfq): RedirectResponse
    {
        try {
            $quote = $this->svc->recordQuote($rfq, $r->validated());

            return back()->with('success', "Quote recorded for {$quote->vendor?->name}.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function withdrawQuote(PurchaseRfqQuote $quote): RedirectResponse
    {
        try {
            $fresh = $this->svc->withdrawQuote($quote);

            return back()->with('success', 'Quote withdrawn — it will not be offered in the comparison.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function award(PurchaseRfqAwardRequest $r, PurchaseRfq $rfq): RedirectResponse
    {
        try {
            $orders = $this->svc->award($rfq, $r->validated('picks'), $r->integer('warehouse_id') ?: null);

            $list = implode(', ', array_map(static fn (int $id): string => '#'.$id, $orders));

            return redirect()
                ->route('purchase.rfqs.show', $rfq->id)
                ->with('success', count($orders) > 1
                    ? 'Round awarded — '.count($orders)." draft purchase orders created ({$list})."
                    : "Round awarded — draft purchase order {$list} created.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
