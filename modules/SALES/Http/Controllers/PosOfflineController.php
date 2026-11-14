<?php

namespace Modules\SALES\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\SALES\Models\PosSale;
use Modules\SALES\Requests\PosOfflineSyncRequest;
use Modules\SALES\Services\PosOfflineSyncService;

/**
 * F22 — the offline till's side of the server: the queue desk, and the door tickets come back through.
 *
 * Two actions, and the reason they are not one is the reason this feature is hard. `sync` is a POST
 * that a device may make more than once for the same ticket, so it answers JSON with a verdict per
 * row and never a redirect — an Inertia redirect to a receipt page is exactly what a till cannot
 * render when the network is on its way out again. `queue` is the human's page: what is still sitting
 * on this device, and what the ledger has already accepted from one.
 */
class PosOfflineController extends Controller
{
    public function __construct(private readonly PosOfflineSyncService $sync) {}

    /**
     * The offline desk.
     *
     * The pending list itself lives on the device (IndexedDB) — the server has never heard of those
     * tickets, which is the whole point of the feature — so what this page supplies is the *other*
     * half: the sales the ledger received from an offline queue, today and this month, so a manager
     * can see that the twelve tickets on the tablet have, or have not, arrived.
     */
    public function queue(Request $r): Response
    {
        $from = now()->startOfMonth()->toDateString();

        $posted = PosSale::query()
            ->rungOnDevice()
            ->where('sale_date', '>=', $from)
            ->with(['warehouse:id,name', 'session:id,session_no,status'])
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (PosSale $s): array => [
                'id' => $s->id,
                'pos_no' => $s->pos_no,
                'client_ref' => $s->client_ref,
                'sale_date' => $s->sale_date?->toDateString(),
                // The counter-clock time, so a gap between the two is visible instead of deniable.
                'rang_up_at' => $s->rang_up_at?->toIso8601String(),
                'customer_name' => $s->customer_name,
                'total_amount' => (string) $s->total_amount,
                'payment_method' => $s->payment_method,
                'warehouse' => $s->warehouse?->name,
                'session' => $s->session?->session_no,
                'session_status' => $s->session?->status,
                'status' => $s->status,
            ])
            ->all();

        return Inertia::render('SALES::Pos/Offline', [
            'posted' => $posted,
            'postedToday' => count(array_filter($posted, static fn (array $s): bool => $s['sale_date'] === now()->toDateString())),
            'maxBatch' => PosOfflineSyncService::MAX_ROWS,
            // The desk is reachable from the till without a permission dance: both are the till's.
            'canSell' => $r->user()->can('pos.terminal.sell'),
        ]);
    }

    /**
     * Replay a batch of queued tickets.
     *
     * Always 200 with a verdict per row, even when every row is refused, because the caller is a queue
     * that has to clear the ones that worked and keep the ones that did not. A 4xx here would mean the
     * till cannot tell which tickets to drop, and "drop" is the one thing a sale may never be.
     *
     * The exceptions are the envelope: an unauthenticated or unauthorised caller gets 401/403 from the
     * route, and a malformed batch gets 422 from `PosOfflineSyncRequest`.
     */
    public function sync(PosOfflineSyncRequest $r): JsonResponse
    {
        $result = $this->sync->sync($r->user(), (array) $r->input('sales', []));

        return response()->json($result + [
            'synced_at' => now()->toIso8601String(),
            // Echoed so the till can tell a refused-but-retryable batch from one the server truncated.
            'batch_limit' => PosOfflineSyncService::MAX_ROWS,
        ]);
    }
}
