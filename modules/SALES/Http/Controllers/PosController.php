<?php

namespace Modules\SALES\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\ACCOUNTS\Models\Bank;
use Modules\CORE\Models\User;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\INVENTORY\Models\Warehouse;
use Modules\PARTNER\Models\Partner;
use Modules\SALES\DTO\InvoiceLineData;
use Modules\SALES\DTO\PosSaleData;
use Modules\SALES\Models\PosSale;
use Modules\SALES\Models\PosSession;
use Modules\SALES\Requests\PosSaleRequest;
use Modules\SALES\Services\PosReportService;
use Modules\SALES\Services\PosService;

class PosController extends Controller
{
    public function __construct(
        private readonly PosService $svc,
        private readonly PosReportService $reports,
    ) {}

    public function terminal(Request $r): Response
    {
        $warehouses = Warehouse::active()->orderBy('name')->get(['id', 'name', 'code']);
        /*
         * Was: where('type','customer')->orWhere('is_customer', true).
         *
         * `is_customer` is not a column on partners and appears in no migration.
         * SQLite and MySQL silently coerce the double-quoted identifier to a
         * string literal, so the predicate was permanently false — and on
         * PostgreSQL it is a hard error. It also missed partners whose type is
         * 'both' and skipped the active() filter the warehouse and bank pickers
         * here both apply. The customers() scope is the authoritative definition.
         */
        $customers = Partner::query()
            ->customers()
            ->active()
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name', 'partner_code']);
        $banks = Bank::active()->orderBy('name')->get(['id', 'name']);
        $openSession = PosSession::where('status', 'open')->where('opened_by', $r->user()->id)->latest()->first();

        /*
         * Every open drawer, not just this user's. The till used to recognise only a drawer it had
         * opened itself, while `show()` and `collect()` both resolve "the open drawer" by warehouse —
         * so when a supervisor opened the drawer for a till, the cashier's screen reported no session
         * at all and the cashier either sold with no drawer or opened a second one for the same till.
         * The till now picks the drawer for the warehouse it is selling from, and falls back to its
         * own, which is the same answer the collection path gives.
         */
        $openSessions = PosSession::with(['warehouse:id,name,code', 'opener:id,name'])
            ->where('status', 'open')
            ->orderByDesc('opened_at')
            ->get()
            ->map(fn (PosSession $s): array => [
                'id' => $s->id,
                'session_no' => $s->session_no,
                'warehouse_id' => $s->warehouse_id,
                'warehouse' => $s->warehouse?->name,
                'cashier' => $s->opener?->name,
                'opening_cash' => (string) $s->opening_cash,
                'opened_at' => $s->opened_at?->toIso8601String(),
                'mine' => $s->opened_by === $r->user()->id,
            ])->all();

        return Inertia::render('SALES::Pos/Terminal', [
            // The drawer controls are guarded by pos.sessions.manage and the Z-report by
            // pos.reports.view, while this page only needs pos.terminal.sell. Without these two
            // flags the till offers buttons that answer with a 403 page.
            'canManageSessions' => $r->user()->can('pos.sessions.manage'),
            'canViewReports' => $r->user()->can('pos.reports.view'),
            'openSessions' => $openSessions,
            'warehouses' => $warehouses->map(fn ($w) => ['value' => $w->id, 'label' => "{$w->name} ({$w->code})"]),
            // `name` alongside the display label: a credit sale has to record who owes it, and
            // parsing the debtor's name back out of "Acme Ltd (CUS-001)" is not how you get it.
            'customers' => $customers->map(fn ($c) => ['value' => $c->id, 'label' => "{$c->name} ({$c->partner_code})", 'name' => $c->name]),
            'banks' => $banks->map(fn ($b) => ['value' => $b->id, 'label' => $b->name]),
            'openSession' => $openSession,
        ]);
    }

    public function sessions(Request $r): Response
    {
        $dt = DataTableQuery::fromRequest($r, ['session_no', 'created_at'], []);

        return Inertia::render('SALES::Pos/Sessions', [
            'sessions' => PaginatedResource::from($this->svc->sessionsPaginate($dt)->through(fn (PosSession $s) => [
                'id' => $s->id, 'session_no' => $s->session_no, 'warehouse' => $s->warehouse ? ['name' => $s->warehouse->name] : null,
                'opening_cash' => $s->opening_cash, 'total_sales' => $s->total_sales, 'transactions_count' => $s->transactions_count,
                'status' => $s->status, 'opened_at' => $s->opened_at?->toIso8601String(), 'closed_at' => $s->closed_at?->toIso8601String(),
            ])),
        ]);
    }

    public function openSession(Request $r): RedirectResponse
    {
        $r->validate(['warehouse_id' => 'required|exists:inventory_warehouses,id', 'opening_cash' => 'required|numeric|min:0', 'notes' => 'nullable|string|max:1000']);
        try {
            $s = $this->svc->openSession($r->only(['warehouse_id', 'opening_cash', 'notes']));

            return back()->with('success', "Session {$s->session_no} opened.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function closeSession(Request $r, PosSession $session): RedirectResponse
    {
        /*
         * The count is optional, and the service was written to treat it that way: "a session can be
         * closed without a count, in which case there is no variance to report and pretending there
         * is one would be worse than null." This rule used to demand a number anyway, and the till
         * sent 0 whenever the field was left alone — so every drawer that was closed without being
         * counted was recorded as short by its entire takings, and the variance column of the day-end
         * report was fiction. Absent now means absent.
         */
        $r->validate(['closing_cash' => 'nullable|numeric|min:0', 'notes' => 'nullable|string|max:1000']);
        try {
            $closed = $this->svc->closeSession($session, $r->only(['closing_cash', 'notes']));
            $journalNo = $closed->journal?->journal_no;

            // The close is an accounting event now, so the operator is told what it produced: which
            // journal the day went to, and whether the counted drawer matched what the day says.
            $message = $journalNo
                ? "Session {$closed->session_no} closed and posted to {$journalNo}."
                : "Session {$closed->session_no} closed. Nothing to post: the drawer took no money all day.";

            if ($closed->cash_difference !== null) {
                $difference = (float) $closed->cash_difference;
                $message .= abs($difference) < 0.005
                    ? ' The count matches.'
                    : sprintf(' The count is %s by %s.', $difference < 0 ? 'short' : 'over', number_format(abs($difference), 2));
            }

            // Closing a drawer is what produces its Z-report, so the operator lands on it instead of
            // back on the till with a flash message they have to go and hunt for. Only if they may
            // read reports: a cashier who cannot would be walked straight into a 403.
            if ($r->user()->can('pos.reports.view')) {
                return redirect()->route('sales.pos.sessions.dayEnd', $closed->id)->with('success', $message);
            }

            return back()->with('success', $message);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function history(Request $r): Response
    {
        $dt = DataTableQuery::fromRequest($r, ['pos_no', 'sale_date'], []);

        return Inertia::render('SALES::Pos/History', [
            'sales' => PaginatedResource::from($this->svc->salesPaginate($dt)->through(fn (PosSale $s) => [
                'id' => $s->id, 'pos_no' => $s->pos_no, 'customer_name' => $s->customer_name, 'sale_date' => $s->sale_date->format('Y-m-d'),
                'total_amount' => $s->total_amount, 'payment_method' => $s->payment_method, 'status' => $s->status, 'created_at' => $s->created_at?->toIso8601String(),
                'due_amount' => $s->due_amount, 'collected_amount' => $s->collected_amount,
            ])),
        ]);
    }

    public function sell(PosSaleRequest $r): RedirectResponse
    {
        /*
         * F22: a submission the ledger has already answered is not a new sale.
         *
         * The till sends `client_ref` when it submits, whether it is online or not, so a cashier who
         * pressed Sell twice because the response was slow gets the receipt of the sale that happened
         * instead of a second one. The redirect is the same shape the first answer took, which is the
         * point: the till cannot tell the difference, and neither can the drawer.
         */
        if (($ref = $r->input('client_ref')) && ($existing = PosSale::where('client_ref', $ref)->first())) {
            return redirect()->route('sales.pos.receipt', $existing->id)
                ->with('success', "Sale {$existing->pos_no} was already recorded — this repeat of the same submission changed nothing.");
        }

        try {
            $lines = array_map(fn ($it) => new InvoiceLineData(productId: (int) $it['product_id'], qty: (float) $it['qty'], unitPrice: (float) $it['unit_price'], variantId: $it['variant_id'] ?? null, discountPct: (float) ($it['discount_pct'] ?? 0), vatRate: (float) ($it['vat_rate'] ?? 0)), $r->input('items'));
            $dto = new PosSaleData(warehouseId: (int) $r->input('warehouse_id'), lines: $lines, paymentMethod: $r->input('payment_method'), customerId: $r->input('customer_id'), customerName: $r->input('customer_name', 'Walk-in Customer'), bankId: $r->input('bank_id'), cashAmount: (float) ($r->input('cash_amount', 0)), bankAmount: (float) ($r->input('bank_amount', 0)), sessionId: $r->input('session_id'), paidAmount: (float) ($r->input('paid_amount', 0)), tenderedAmount: (float) ($r->input('tendered_amount', 0)), clientRef: $ref);
            $sale = $this->svc->sell($dto);

            $message = match (true) {
                (float) $sale->due_amount > 0 && (float) $sale->paid_amount > 0 => "Sale {$sale->pos_no} part-paid — {$sale->paid_amount} taken, {$sale->due_amount} owed by {$sale->customer_name}. Stock deducted; the due posts to the receivables ledger when this drawer closes.",
                (float) $sale->due_amount > 0 => "Sale {$sale->pos_no} completed on credit — stock deducted, {$sale->due_amount} owed by {$sale->customer_name}. It posts to the receivables ledger when this drawer closes.",
                default => "Sale {$sale->pos_no} completed — stock deducted. The day posts to the ledger when this drawer closes.",
            };

            return redirect()->route('sales.pos.receipt', $sale->id)->with('success', $message);
        } catch (\DomainException|\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function receipt(PosSale $sale): Response
    {
        // `invoice` is no longer loaded: a POS sale has no invoice (item 39). The receipt is the
        // document, and the accounting reference it can honestly print is the day-end journal.
        $sale->load(['items.product', 'warehouse', 'customer', 'session.journal']);

        return Inertia::render('SALES::Pos/Receipt', ['sale' => $sale]);
    }

    public function show(PosSale $sale): Response
    {
        $sale->load(['items.product', 'warehouse', 'customer', 'session.journal', 'collections']);

        // A due is collected into the drawer that is open for this till, so the page needs to know
        // which one that is — and needs the settlement accounts, for a card or bank collection.
        $openSession = PosSession::where('warehouse_id', $sale->warehouse_id)
            ->where('status', 'open')
            ->latest()
            ->first();

        return Inertia::render('SALES::Pos/Show', [
            'sale' => $sale,
            'banks' => Bank::active()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Bank $b) => ['value' => $b->id, 'label' => $b->name])->all(),
            'openSession' => $openSession === null ? null : [
                'id' => $openSession->id,
                'session_no' => $openSession->session_no,
                'cashier' => $openSession->opener?->name,
            ],
        ]);
    }

    /**
     * The user-wise day-end and collection report.
     *
     * One row per drawer, plus the same day folded up per cashier — the view a shop owner wants at
     * the close: who took what in cash and card, what was sold on credit, what was collected against
     * earlier credit, and whose drawer counted short.
     */
    public function dayEndReport(Request $r): Response
    {
        $filters = $r->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:inventory_warehouses,id'],
            'status' => ['nullable', 'in:open,closed'],
        ]);

        // Default to the current month rather than to everything: a report that opens on three years
        // of drawers is a report nobody reads, and one that opens empty looks broken.
        $filters['from'] = $filters['from'] ?? now()->startOfMonth()->toDateString();
        $filters['to'] = $filters['to'] ?? now()->toDateString();

        return Inertia::render('SALES::Pos/DayEndReport', [
            'report' => $this->reports->dayEnd($filters),
            'filters' => $filters,
            // {value,label} like every other picker in this module, so SearchableSelect takes them
            // as they come.
            'cashiers' => User::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $u): array => ['value' => $u->id, 'label' => $u->name])->all(),
            'warehouses' => Warehouse::active()->orderBy('name')->get(['id', 'name', 'code'])
                ->map(fn (Warehouse $w): array => ['value' => $w->id, 'label' => "{$w->name} ({$w->code})"])->all(),
        ]);
    }

    /**
     * The collection register: who collected what, from whom, against which sale.
     *
     * The day-end report answers "how did each drawer do", including the collections that landed in
     * it. This answers the credit side of the counter — per collector, per customer, per method —
     * and lists the collections that landed in no drawer at all, which no drawer-scoped report can
     * show.
     */
    public function collections(Request $r): Response
    {
        $filters = $r->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'partner_id' => ['nullable', 'integer', 'exists:partners,id'],
            'method' => ['nullable', 'in:cash,bank,bkash,card'],
        ]);

        // Same default as the day-end report: this month. A register that opens on every collection
        // ever taken is a register nobody reads, and one that opens empty looks broken.
        $filters['from'] = $filters['from'] ?? now()->startOfMonth()->toDateString();
        $filters['to'] = $filters['to'] ?? now()->toDateString();

        return Inertia::render('SALES::Pos/Collections', [
            'report' => $this->reports->collections($filters),
            'filters' => $filters,
            'collectors' => User::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $u): array => ['value' => $u->id, 'label' => $u->name])->all(),
            'customers' => Partner::query()->customers()->active()->orderBy('name')->limit(200)
                ->get(['id', 'name', 'partner_code'])
                ->map(fn (Partner $p): array => ['value' => $p->id, 'label' => "{$p->name} ({$p->partner_code})"])->all(),
        ]);
    }

    /** One drawer's Z-report: what it rang up, what it collected, and the journal the day went to. */
    public function sessionDayEnd(PosSession $session): Response
    {
        $detail = $this->reports->sessionDetail($session->id);
        $session->load('journal.lines.account');
        $journal = $session->journal;

        return Inertia::render('SALES::Pos/SessionDayEnd', [
            'session' => [
                'id' => $session->id,
                'session_no' => $session->session_no,
                'status' => $session->status,
                'warehouse' => $session->warehouse?->name,
                'cashier' => $session->opener?->name,
                'opened_at' => $session->opened_at?->toIso8601String(),
                'closed_at' => $session->closed_at?->toIso8601String(),
                'opening_cash' => (string) $session->opening_cash,
                'expected_cash' => $session->expected_cash !== null ? (string) $session->expected_cash : null,
                'closing_cash' => $session->closing_cash !== null ? (string) $session->closing_cash : null,
                'cash_difference' => $session->cash_difference !== null ? (string) $session->cash_difference : null,
                'net_sales_total' => (string) $session->net_sales_total,
                'vat_total' => (string) $session->vat_total,
                'discount_total' => (string) $session->discount_total,
                'cogs_total' => (string) $session->cogs_total,
                'cash_total' => (string) $session->cash_total,
                'bank_total' => (string) $session->bank_total,
                'due_total' => (string) $session->due_total,
                'collected_total' => (string) $session->collected_total,
                'void_count' => (int) $session->void_count,
                'transactions_count' => (int) $session->transactions_count,
                'notes' => $session->notes,
            ],
            'sales' => $detail['sales'],
            'collections' => $detail['collections'],
            'journal' => $journal === null ? null : [
                'id' => $journal->id,
                'journal_no' => $journal->journal_no,
                'journal_date' => $journal->journal_date?->toDateString(),
                'status' => $journal->status,
                'narration' => $journal->narration,
                'total_debit' => (string) $journal->total_debit,
                'total_credit' => (string) $journal->total_credit,
                'lines' => $journal->lines->map(fn ($line): array => [
                    'code' => $line->account?->code,
                    'account' => $line->account?->name,
                    'debit' => (string) $line->debit,
                    'credit' => (string) $line->credit,
                    'description' => $line->description,
                ])->all(),
            ],
        ]);
    }

    /**
     * Take money at the counter against a credit sale.
     *
     * The payment goes into the drawer that is open for this till, so it lands in that cashier's day
     * and in that day's journal — not in the day the sale was rung up on, which is already posted.
     */
    public function collect(Request $r, PosSale $sale): RedirectResponse
    {
        $data = $r->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'in:cash,bank,bkash,card'],
            'bank_id' => ['nullable', 'exists:acc_banks,id', 'required_unless:method,cash'],
            'session_id' => ['nullable', 'integer', 'exists:pos_sessions,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        // No drawer named means "the one I am standing at": the open session for this till.
        $data['session_id'] = $data['session_id']
            ?? PosSession::where('warehouse_id', $sale->warehouse_id)->where('status', 'open')->value('id');

        try {
            $collection = $this->svc->collectDue($sale, $data);

            return back()->with('success', sprintf(
                'Collected %s against %s (%s). It joins the day of session %s.',
                number_format((float) $collection->amount, 2),
                $sale->pos_no,
                $collection->collection_no,
                $collection->session?->session_no ?? $collection->session_id,
            ));
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function void(PosSale $sale): RedirectResponse
    {
        try {
            $this->svc->void($sale);

            // Accurate about what a void does now: the stock comes back and the day's counters come
            // down, but nothing is reversed in the ledger, because nothing was posted yet — the day
            // posts once, when the drawer closes (report item 39).
            return back()->with('success', "Sale {$sale->pos_no} voided — stock returned and the day's totals reduced.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
