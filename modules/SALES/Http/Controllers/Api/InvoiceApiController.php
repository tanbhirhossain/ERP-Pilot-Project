<?php

namespace Modules\SALES\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CORE\Support\Api\ApiEndpoint;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\SALES\Models\Invoice;
use Modules\SALES\Services\InvoiceService;

/**
 * F13 — the read-only invoice surface.
 *
 * Deliberately built on the same two pieces the screen uses: `DataTableQuery` parses
 * `search`/`sort`/`dir`/`page`/`per_page`/`filters`, and `PaginatedResource` shapes the
 * `{ data, meta }` envelope. So the API and the table on `Sales → Invoices` cannot
 * disagree about what `filters[status]=partial` means, about the 100-row page ceiling,
 * or about which columns are sortable — and an integrator who has seen one has seen
 * both.
 *
 * Money leaves as a **string**, because the models cast every decimal `decimal:2`: a
 * float in a JSON body is how an integration ends up one paisa short on a payment
 * reconciliation, and `MoneyColumnCastTest` exists in this repo precisely to stop
 * that. v1 exposes no invoice writes: an invoice is a document with a VAT line and a
 * journal behind it, and a client that cannot see the numbering rules should not be
 * creating one.
 */
class InvoiceApiController extends Controller
{
    /** The same whitelist the screen passes, so the API cannot sort by a column the UI hides. */
    private const SORTS = ['invoice_no', 'invoice_date', 'created_at'];

    private const FILTERS = ['status', 'payment_status'];

    public function __construct(private readonly InvoiceService $invoices) {}

    #[ApiEndpoint(
        summary: 'List invoices',
        description: 'One page of invoices. Money is exact two-decimal strings, so `total_amount` can be added up without floating-point drift.',
        query: [
            ['name' => 'search', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Invoice number, invoice date or created_at — the columns the on-screen table searches.'],
            ['name' => 'sort', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['invoice_no', 'invoice_date', 'created_at']]],
            ['name' => 'dir', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['asc', 'desc']], 'description' => 'Default desc.'],
            ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1]],
            ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], 'description' => 'Default 25, capped at 100. Want more? Paginate.'],
            ['name' => 'filters[status]', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'draft, issued, partial, paid, void.'],
            ['name' => 'filters[payment_status]', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'unpaid, partial, paid.'],
        ],
        response: 'InvoicePage',
    )]
    public function index(Request $request): JsonResponse
    {
        $dt = DataTableQuery::fromRequest($request, self::SORTS, self::FILTERS);

        $page = $this->invoices->paginate($dt)->through(fn (Invoice $i) => $this->summary($i));

        return response()->json(PaginatedResource::from($page));
    }

    /** Accepts the numeric id or the invoice number — the number is what people quote. */
    #[ApiEndpoint(
        summary: 'Read one invoice, by id or invoice number',
        description: 'Includes the VAT-bearing lines. A row you may not see answers 404 rather than 403 — that the document exists is not information for outside the ACL.',
        path: [
            ['name' => 'key', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'Numeric invoice id or the human invoice number, which is what a customer quotes back at you.'],
        ],
        response: 'InvoiceDetail',
    )]
    public function show(Request $request, string $key): JsonResponse
    {
        $invoice = is_numeric($key)
            ? Invoice::with($this->relations())->find($key)
            : Invoice::with($this->relations())->where('invoice_no', $key)->first();

        abort_if($invoice === null, 404, "No invoice matches [$key].");

        return response()->json([
            ...$this->summary($invoice),
            // The list endpoint gets `items_count` from the repository's withCount(); a
            // single invoice has the rows loaded, so count those rather than report null.
            'items_count' => $invoice->items->count(),
            'notes' => $invoice->notes,
            'posted_at' => $invoice->posted_at?->toIso8601String(),
            'customer' => $invoice->customer ? [
                'id' => $invoice->customer->id,
                'name' => $invoice->customer->name,
                'bin' => $invoice->customer->bin_number ?? null,
            ] : null,
            'warehouse' => $invoice->warehouse?->name,
            'lines' => $invoice->items->map(fn ($l) => [
                'product_name' => $l->product_name,
                'sku' => $l->sku,
                'qty' => $l->qty,
                'unit_price' => $l->unit_price,
                'discount_amount' => $l->discount_amount,
                'vat_rate' => $l->vat_rate,
                'vat_amount' => $l->vat_amount,
                'line_total' => $l->line_total,
            ])->values(),
        ]);
    }

    private function relations(): array
    {
        return ['customer', 'warehouse', 'items'];
    }

    private function summary(Invoice $i): array
    {
        return [
            'id' => $i->id,
            'invoice_no' => $i->invoice_no,
            'customer' => $i->customer?->name,
            'invoice_date' => $i->invoice_date?->format('Y-m-d'),
            'due_date' => $i->due_date?->format('Y-m-d'),
            'currency' => $i->currency,
            // Straight from the `decimal:2` casts: strings, exactly two decimals.
            'subtotal' => $i->subtotal,
            'discount_amount' => $i->discount_amount,
            'vat_amount' => $i->vat_amount,
            'total_amount' => $i->total_amount,
            'paid_amount' => $i->paid_amount,
            'balance' => $i->balance,
            'status' => $i->status,
            'payment_status' => $i->payment_status,
            'journal_id' => $i->journal_id,
            'items_count' => $i->items_count,
        ];
    }
}
