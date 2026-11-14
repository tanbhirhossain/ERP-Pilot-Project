<?php

namespace Modules\PURCHASE\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\ACCOUNTS\Services\BudgetService;
use Modules\CORE\Repositories\Concerns\BuildsDatatableQueries;
use Modules\CORE\Services\ApprovalService;
use Modules\CORE\Support\DataTableQuery;
use Modules\PURCHASE\Models\PurchaseOrder;
use Modules\PURCHASE\Models\PurchaseRequisition;
use Modules\PURCHASE\Models\PurchaseRequisitionItem;
use Modules\PURCHASE\Models\PurchaseRfq;
use Modules\PURCHASE\Models\PurchaseRfqLine;

/**
 * F18 — the requisition state machine, its estimates, and the one control that makes the document worth
 * having: a budget headroom check at submit.
 *
 * Three principles, each of which is a test:
 *
 * 1. **A requisition never touches the ledger.** Not a journal, not a stock move, not a bill. Its whole
 *    job is to be a decision point, and the moment it also *did* something financially it became a second
 *    purchase order with worse validation.
 * 2. **Money is arithmetic, in integer poisha.** `qty × price` summed in floats is how a report ends up
 *    printing `48213.770000000004`; the same rounding discipline `BudgetService`, `VatReturnService` and
 *    the POS offline queue use. Estimates are stored as decimal(15,2) and cast `decimal:2`, so what the
 *    browser sees is what the column holds.
 * 3. **An approval refusal writes nothing.** The status update happens after `ApprovalService` has said
 *    yes, so a pending request is created for the approver and the requisition stays `draft` — no
 *    half-moved record whose status claims a thing nobody approved.
 */
class PurchaseRequisitionService
{
    use BuildsDatatableQueries;

    /**
     * A requisition is *submitted* against the budget month of its need-by date, falling back to today.
     * A request for next month's consumables therefore checks next month's plan, which is the only
     * question a budget can answer.
     */
    public function __construct(private readonly ApprovalService $approvals) {}

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        return $this->query($dt)->paginate($dt->perPage, ['*'], 'page', $dt->page)->withQueryString();
    }

    public function findOrFail(int $id): PurchaseRequisition
    {
        return PurchaseRequisition::with([
            'items.product', 'warehouse', 'requester', 'reviewer', 'rfqs.quotes.vendor', 'convertedOrder',
        ])->findOrFail($id);
    }

    public function create(array $data): PurchaseRequisition
    {
        return DB::transaction(function () use ($data): PurchaseRequisition {
            $items = $data['items'] ?? [];
            unset($data['items']);

            $data['req_no'] = $this->nextNo();
            $data['status'] = 'draft';
            $data['requested_by'] = $data['requested_by'] ?? auth()->id();
            $data['created_by'] = auth()->id();
            unset($data['estimated_amount']);

            $req = PurchaseRequisition::create($data);
            $this->syncItems($req, is_array($items) ? $items : []);

            return $this->recomputeEstimate($req);
        });
    }

    public function update(PurchaseRequisition $req, array $data): PurchaseRequisition
    {
        if (! $req->isDraft()) {
            throw new \DomainException('Only a draft requisition can be edited — once it is submitted, the answer goes in a new one or in the review.');
        }

        return DB::transaction(function () use ($req, $data): PurchaseRequisition {
            $items = $data['items'] ?? null;
            unset($data['items']);

            // Never editable, whatever the form posts: the number is the record's identity and the
            // requester is who the approver will call.
            unset($data['req_no'], $data['status'], $data['requested_by'], $data['created_by'], $data['reviewed_by'], $data['reviewed_at'], $data['converted_order_id']);

            unset($data['estimated_amount']);

            if ($items !== null) {
                $this->syncItems($req, $items);
            }

            $req->update($data);

            // Recomputed from what is now in the database, not from what the form posted: the header is
            // the sum of the stored lines or it is a second opinion.
            return $this->recomputeEstimate($req);
        });
    }

    public function delete(PurchaseRequisition $req): void
    {
        if (! $req->isDraft()) {
            throw new \DomainException('Only a draft requisition can be deleted.');
        }

        $req->items()->delete();
        $req->delete();
    }

    /**
     * draft → submitted: the only transition with preconditions, because this is where the document stops
     * being a note and starts being a request someone must answer.
     */
    public function submit(PurchaseRequisition $req): PurchaseRequisition
    {
        if (! $req->isDraft()) {
            throw new \DomainException('Only a draft requisition can be submitted.');
        }

        if ($req->items()->where('qty', '>', 0)->doesntExist()) {
            throw new \DomainException('Submit needs at least one line with a quantity.');
        }

        // Submitting is *not* the place to wave a breach through: the requester is standing here, and a
        // checkbox that says "post anyway" next to their own submit button is the same person approving
        // themselves with extra steps. `approve()` below is where a breach may be overridden, by someone
        // else, and that is recorded as a decision.
        $budget = $this->budgetPosition($req);

        if ($budget !== null && $budget['over']) {
            throw new \DomainException($this->budgetMessage($budget));
        }

        // Runs last-but-one: a policy that needs a second signature creates an approval request and
        // notifies its approver, and that must not describe a requisition this method then refuses.
        $this->approvals->assertAllowed('purchase_requisition', $req->id, (float) $req->estimated_amount, $req->req_no);

        $req->update(['status' => 'submitted']);

        return $req->fresh();
    }

    /**
     * submitted → approved. Segregation is enforced here rather than by a policy an admin might forget
     * to activate: a requisition the requester themselves signed off is not an approval at all.
     */
    /**
     * submitted → approved. `$overrideBudget` is the *only* door past a breach, and it is held by the
     * approver rather than the requester — which is why the flag is checked against the permission here
     * instead of being trusted from the controller.
     */
    public function approve(PurchaseRequisition $req, bool $overrideBudget = false): PurchaseRequisition
    {
        // The one exception to "submitted first": a requisition the budget stopped cannot be submitted,
        // and the approver's answer to that is a single recorded act — override and approve together —
        // rather than the requester being told to post it anyway and then ask again. Every guard below
        // still runs before the status write, so no ordering here can let a refusal through.
        if ($req->status === 'draft' && ! $overrideBudget) {
            throw new \DomainException('Submit the requisition before deciding on it.');
        }

        if (! in_array($req->status, ['draft', 'submitted'], true)) {
            throw new \DomainException("A requisition that is {$req->status} cannot be approved again.");
        }

        $me = auth()->id();

        if ($me !== null && (int) $me === (int) $req->requested_by) {
            throw new \DomainException('You raised this requisition — someone else has to approve it.');
        }

        // Checked before anything else about the override: a flag from a form is not a permission, and
        // "approve anyway" must not double as a way to skip submission.
        if ($overrideBudget && ! auth()->user()?->can('purchase.requisitions.approve')) {
            throw new \DomainException('Only an approver can override a budget refusal.');
        }

        $budget = $this->budgetPosition($req);

        if ($budget !== null && $budget['over'] && ! $overrideBudget) {
            throw new \DomainException($this->budgetMessage($budget));
        }

        $req->update([
            'status' => 'approved',
            'reviewed_by' => $me,
            'reviewed_at' => now(),
            'rejection_reason' => null,
        ]);

        return $req->fresh();
    }

    public function reject(PurchaseRequisition $req, string $reason): PurchaseRequisition
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new \DomainException('A refusal needs a reason — that is the whole point of refusing in writing.');
        }

        if (! in_array($req->status, ['draft', 'submitted', 'approved'], true)) {
            throw new \DomainException("A requisition that is {$req->status} cannot be rejected.");
        }

        $req->update([
            'status' => 'rejected',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'rejection_reason' => mb_substr($reason, 0, 255),
        ]);

        return $req->fresh();
    }

    public function cancel(PurchaseRequisition $req): PurchaseRequisition
    {
        if ($req->status === 'converted') {
            throw new \DomainException('This requisition has already become a purchase order — cancel the order instead.');
        }

        if ($req->status === 'cancelled') {
            throw new \DomainException('Already cancelled.');
        }

        $req->update(['status' => 'cancelled']);

        return $req->fresh();
    }

    /**
     * Open a quote round against this requisition: one RFQ line per *outstanding* requisition line.
     *
     * Already-ordered quantities are excluded rather than clamped, so a second round after a partial
     * award asks the market for exactly what is still missing.
     */
    public function openRfq(PurchaseRequisition $req, ?string $subject = null): PurchaseRfq
    {
        if (! $req->isAwardable()) {
            throw new \DomainException('Only an approved requisition can be put out to quote.');
        }

        $req->load('items');

        return DB::transaction(function () use ($req, $subject): PurchaseRfq {
            $rfq = PurchaseRfq::create([
                'rfq_no' => app(PurchaseRfqService::class)->nextNo(),
                'requisition_id' => $req->id,
                'subject' => $subject ?: ($req->title.' — '.$req->req_no),
                'status' => 'draft',
                'created_by' => auth()->id(),
            ]);

            foreach ($req->items as $item) {
                $remaining = round(max(0, (float) $item->qty - (float) $item->qty_ordered), 2);

                if ($remaining <= 0) {
                    continue;
                }

                PurchaseRfqLine::create([
                    'rfq_id' => $rfq->id,
                    'requisition_item_id' => $item->id,
                    'description' => $item->description,
                    'qty' => $remaining,
                    'uom_id' => $item->uom_id,
                ]);
            }

            if (! $rfq->lines()->exists()) {
                throw new \DomainException('Every line of this requisition has already been ordered.');
            }

            return $rfq;
        });
    }

    /**
     * The budget position for a requisition, or null when there is nothing to compare it against.
     *
     * "No approved budget line for this account and month" is *not* a failure and must never block a
     * purchase: shops without a plan would be unable to buy anything. But once a plan exists for the
     * account, spending past it is a decision, so it becomes a refusal with a named override.
     *
     * @return array{account_id:int,account:string,period:string,plan:float,actual:float,headroom:float,requested:float,over:bool}|null
     */
    public function budgetPosition(PurchaseRequisition $req): ?array
    {
        if (! $req->account_id) {
            return null;
        }

        $period = ($req->need_by ?? now())->format('Y-m');

        $line = DB::table('acc_budget_lines as bl')
            ->join('acc_budgets as b', 'b.id', '=', 'bl.budget_id')
            ->where('bl.account_id', $req->account_id)
            ->where('bl.period', $period)
            ->whereIn('b.status', ['approved', 'locked'])
            ->selectRaw('bl.amount as amount, b.id as budget_id')
            ->orderByDesc('b.id')
            ->first();

        if (! $line) {
            return null;
        }

        $svc = app(BudgetService::class);
        $actual = (float) ($svc->actuals([$req->account_id], $period)[$req->account_id] ?? 0.0);
        $plan = (float) $line->amount;
        $requested = (float) $req->estimated_amount;

        // Spend already posted reduces the room; a credit-natured account plans income and the service
        // returns it signed, so one subtraction covers both directions (the convention F14 established).
        $headroom = $this->centsToAmount($this->cents($plan) - $this->cents($actual));
        $remaining = $this->centsToAmount($this->cents($headroom) - $this->cents($requested));

        return [
            'account_id' => (int) $req->account_id,
            'account' => (string) DB::table('acc_accounts')->where('id', $req->account_id)->value('name'),
            'period' => $period,
            'plan' => $plan,
            'actual' => $actual,
            'headroom' => $headroom,
            'requested' => $requested,
            'over' => $remaining < 0,
        ];
    }

    private function budgetMessage(array $budget): string
    {
        return sprintf(
            'Over budget: %s for %s has %s planned, %s already posted, leaving %s — this request needs %s. Only an approver can push it through, from the approval screen.',
            $budget['account'] ?: 'account '.$budget['account_id'],
            $budget['period'],
            number_format($budget['plan'], 2),
            number_format($budget['actual'], 2),
            number_format($budget['headroom'], 2),
            number_format($budget['requested'], 2),
        );
    }

    /**
     * @param  list<array<string,mixed>>  $items
     */
    private function syncItems(PurchaseRequisition $req, array $items): void
    {
        $req->items()->delete();

        foreach ($items as $it) {
            unset($it['id'], $it['requisition_id'], $it['qty_ordered']);
            $estimate = $this->multiply((float) ($it['qty'] ?? 0), (float) ($it['expected_unit_price'] ?? 0));

            PurchaseRequisitionItem::create([
                'requisition_id' => $req->id,
                'product_id' => $it['product_id'] ?? null,
                'description' => $it['description'] ?? ($it['product_name'] ?? 'Unspecified'),
                'uom_id' => $it['uom_id'] ?? null,
                'qty' => (float) ($it['qty'] ?? 0),
                'expected_unit_price' => (float) ($it['expected_unit_price'] ?? 0),
                'line_estimate' => $estimate,
                'notes' => $it['notes'] ?? null,
            ]);
        }
    }

    /**
     * The header estimate is the sum of the *stored* line estimates, so the number on an approval list
     * and the numbers in the lines can never disagree by a rounding step. Returns the reloaded record
     * because every caller wants the version with its lines and its total in the same breath.
     */
    private function recomputeEstimate(PurchaseRequisition $req): PurchaseRequisition
    {
        $poisha = 0;

        foreach ($req->items()->get() as $item) {
            $poisha += $this->cents((float) $item->line_estimate);
        }

        $req->update(['estimated_amount' => $this->centsToAmount($poisha)]);

        return $req->fresh(['items']);
    }

    public function nextNo(): string
    {
        $pad = (int) config('purchase.code.pad', 5);
        $pref = config('purchase.code.requisition_prefix', 'PR-');

        return DB::transaction(function () use ($pref, $pad): string {
            $last = PurchaseRequisition::where('req_no', 'like', $pref.'%')->lockForUpdate()->orderByDesc('id')->value('req_no');
            $n = $last ? ((int) substr($last, strlen($pref)) + 1) : 1;

            return $pref.str_pad((string) $n, $pad, '0', STR_PAD_LEFT);
        });
    }

    /** Mark the requisition answered by this PO (the first one, when a split award made several). */
    public function attachOrder(PurchaseRequisition $req, PurchaseOrder $order): void
    {
        $req->update(['converted_order_id' => $order->id, 'status' => 'converted']);
    }

    private function baseQuery(): Builder
    {
        return PurchaseRequisition::query()
            ->leftJoin('users as req_users', 'req_users.id', '=', 'purchase_requisitions.requested_by')
            ->with(['warehouse', 'requester'])
            ->select('purchase_requisitions.*', 'req_users.name as requester_name');
    }

    private function query(DataTableQuery $dt): Builder
    {
        $q = $this->baseQuery();

        // Whitelisted through the shared trait, deliberately: the search box must not be able to reach
        // `justification` (free text that can name a salary) or sort by an unindexed expression.
        $this->applyDataTable(
            $q,
            $dt,
            ['purchase_requisitions.req_no', 'purchase_requisitions.title'],
            [
                'req_no' => 'purchase_requisitions.req_no',
                'status' => 'purchase_requisitions.status',
                'need_by' => 'purchase_requisitions.need_by',
                'estimated_amount' => 'purchase_requisitions.estimated_amount',
                'created_at' => 'purchase_requisitions.created_at',
            ],
            [
                'status' => fn (Builder $x, string $v) => $x->where('purchase_requisitions.status', $v),
                'requested_by' => fn (Builder $x, string $v) => $x->where('purchase_requisitions.requested_by', (int) $v),
            ],
        );

        return $q;
    }

    private function cents(float $v): int
    {
        return (int) round($v * 100);
    }

    /**
     * qty × price, exact to the poisha.
     *
     * Both sides are rounded to integer poisha first, the product is taken in integers, and only then
     * divided back out — so a 3-decimal quantity against a 2-decimal price cannot produce a half-poisha
     * total that rounds differently on two runs.
     */
    private function multiply(float $qty, float $price): float
    {
        $q = (int) round($qty * 1000);
        $p = (int) round($price * 100);

        return round($q * $p / 100000, 2);
    }

    private function centsToAmount(int $cents): float
    {
        return $cents / 100;
    }
}
