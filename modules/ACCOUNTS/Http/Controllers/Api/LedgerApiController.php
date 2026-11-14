<?php

namespace Modules\ACCOUNTS\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\ACCOUNTS\Services\LedgerService;
use Modules\CORE\Support\Api\ApiEndpoint;

/**
 * F13 — trial balance over the API.
 *
 * `LedgerService::trialBalance()` is the identical source the on-screen report uses,
 * including its sign convention (a `debit`-nature account reports `debit − credit`,
 * anything else reports `credit − debit`). That is worth stating because it is the one
 * thing an integrator is certain to get wrong: a consumer that assumes "credit is
 * positive" will read every asset account backwards and the numbers will still look
 * plausible.
 *
 * The response carries `nature` and `balance_side` on every row so the sign can be
 * derived rather than remembered.
 */
class LedgerApiController extends Controller
{
    public function __construct(private readonly LedgerService $ledger) {}

    #[ApiEndpoint(
        summary: 'Trial balance as at a date',
        description: 'The identical rows and sign convention as the on-screen report: a `debit`-nature account reports debit − credit, everything else reports credit − debit. Read `balance` with `balance_side`; a client that assumes credit-positive will read every asset account backwards and still get plausible numbers.',
        query: [
            ['name' => 'as_of', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date'], 'description' => 'Defaults to today.'],
        ],
        response: 'TrialBalance',
    )]
    public function trialBalance(Request $request): JsonResponse
    {
        $data = $request->validate(['as_of' => ['nullable', 'date']]);
        $asOf = $data['as_of'] ?? now()->toDateString();

        $rows = $this->ledger->trialBalance($asOf)
            ->map(fn (array $r) => [
                'account_id' => $r['account_id'],
                'code' => $r['code'],
                'name' => $r['name'],
                'account_type' => $r['account_type'],
                'nature' => $r['nature'],
                'total_debit' => number_format($r['total_debit'], 2, '.', ''),
                'total_credit' => number_format($r['total_credit'], 2, '.', ''),
                'balance' => number_format($r['balance'], 2, '.', ''),
                'balance_side' => $r['balance_side'],
            ])
            ->values();

        return response()->json([
            'as_of' => $asOf,
            'rows' => $rows,
            'totals' => [
                'debit' => number_format($rows->sum(fn ($r) => (float) $r['total_debit']), 2, '.', ''),
                'credit' => number_format($rows->sum(fn ($r) => (float) $r['total_credit']), 2, '.', ''),
            ],
        ]);
    }
}
