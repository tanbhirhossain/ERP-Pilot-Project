<?php

namespace Modules\ACCOUNTS\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\ACCOUNTS\Models\Journal;
use Modules\ACCOUNTS\Requests\StoreJournalRequest;
use Modules\ACCOUNTS\Services\JournalService;
use Modules\CORE\Support\Api\ApiEndpoint;

/**
 * F13 — create a journal by API, as a draft. Nothing on this endpoint can post.
 *
 * That half-step is the whole design: an e-commerce or payroll system can push
 * double-entry data in and have it validated by the identical `StoreJournalRequest` the
 * screen uses — same rules, same `JournalBalance` tolerance, same 422 messages — while
 * a human still decides when the books change. A client cannot post an unbalanced or
 * misdated entry, and an integration bug becomes a draft nobody posted instead of a
 * corrected ledger.
 *
 * There is deliberately no second authorisation check in `show()`: the route's permission
 * middleware is the only ACL gate, and a controller that re-implements it drifts.
 *
 * `status => draft` is passed explicitly because `JournalService::create` honours the
 * caller's status and `post()` refuses anything that is not a draft; leaving it out
 * hands the default to whichever value the service currently prefers.
 */
class JournalApiController extends Controller
{
    public function __construct(private readonly JournalService $journals) {}

    #[ApiEndpoint(
        summary: 'Create a draft journal',
        description: 'Validated by the same form request the screen uses — same rules, same balance tolerance, same 422 messages — and always created as a draft. Posting is not available over v1 by design: an integration bug then leaves an unposted draft instead of a ledger that needs correcting. Domain refusals (an open fiscal year covering the date, a posting account) also answer 422 with the screen\'s sentence, never a 500.',
        request: 'JournalDraft',
        response: 'JournalCreated',
    )]
    public function store(StoreJournalRequest $request): JsonResponse
    {
        try {
            $journal = $this->journals->create([
                ...$request->validated(),
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]);
        } catch (\DomainException $e) {
            // The fiscal calendar and account-validity rules live in the posting domain and
            // speak in DomainException, which the screen renders as a 500 page. Over the API
            // that is the wrong status twice over: the client's input was refused, and a 5xx
            // invites the retry that a rate-limited integration will happily loop on. So: 422,
            // same sentence, keyed as a document-level error because it is not one field's.
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['journal' => [$e->getMessage()]],
            ], 422);
        }

        return response()->json([
            'id' => $journal->id,
            'reference_no' => $journal->reference_no,
            'journal_date' => $journal->journal_date->format('Y-m-d'),
            'status' => $journal->status,
            'line_count' => $journal->lines()->count(),
            'total' => number_format((float) $journal->lines()->sum('debit'), 2, '.', ''),
            'next_step' => 'Review and post it in Accounts → Journals — posting is not available over v1.',
        ], 201);
    }

    #[ApiEndpoint(
        summary: 'Read one journal and its lines',
        description: 'Debits and credits per line, as decimal strings, with the posting account resolved to code and name.',
        path: [
            ['name' => 'journal', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer'], 'description' => 'Journal id.'],
        ],
        response: 'JournalDetail',
    )]
    public function show(Journal $journal): JsonResponse
    {
        return response()->json([
            'id' => $journal->id,
            'reference_no' => $journal->reference_no,
            'journal_date' => $journal->journal_date->format('Y-m-d'),
            'type' => $journal->type,
            'status' => $journal->status,
            'narration' => $journal->narration,
            'lines' => $journal->lines()->with('account:id,code,name')->get()->map(fn ($l) => [
                'account_id' => $l->account_id,
                'account' => $l->account?->code.' · '.$l->account?->name,
                'debit' => $l->debit,
                'credit' => $l->credit,
                'description' => $l->description,
            ])->values(),
        ]);
    }
}
