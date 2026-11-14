<?php

namespace Modules\SALES\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\CORE\Models\User;
use Modules\SALES\DTO\InvoiceLineData;
use Modules\SALES\DTO\PosSaleData;
use Modules\SALES\Models\PosSale;
use Modules\SALES\Requests\PosSaleRequest;

/**
 * Replays tickets the till queued while the network was down — one row at a time, and only ever once.
 *
 * Three properties, each earned against a specific way this could be written wrong:
 *
 *  1. **A replay is a lookup, not an insert.** `client_ref` is minted by the device when the cashier
 *     presses Sell and is unique on `pos_sales`, so a till that cannot tell whether its first attempt
 *     landed (the classic: request sent, connection died, no answer) gets back the sale the server
 *     already has instead of writing a second one. Without this, the drawer is short or over by the
 *     whole ticket and the day-end journal balances against a count nobody can explain.
 *  2. **One bad ticket cannot sink the batch.** Each row is validated, dated and sold independently; a
 *     drawer that was closed while the network was down rejects *its* ticket and reports it, and the
 *     other twenty-four land. The rejected rows stay in the till's queue for a human, they are not
 *     dropped on the floor — the money is already in the drawer either way.
 *  3. **The ledger path is not re-implemented.** A row becomes a `PosSaleData` and goes through
 *     `PosService::sell()`, the same method the till calls when the network is up. Stock moves, cost
 *     is snapshotted, the credit limit is enforced and the drawer's aggregates update exactly as they
 *     would in real time. An offline-first copy of that arithmetic is how a queue ends up posting
 *     numbers the rest of the app disagrees with.
 *
 * Deliberately *not* here: any attempt to be clever about conflicts. A queued ticket is refused with a
 * plain message when the world changed underneath it, because a till that silently re-dated, re-priced
 * or re-drew a sale would be reconciling against an event that never happened at the counter.
 */
final class PosOfflineSyncService
{
    /**
     * One request per burst of a bad connection, and a bound on how much a single replay can hold the
     * ledger open for. 25 is a lunch-rush morning of a small till; more than that means the device was
     * offline for a day, and the honest answer then is a supervisor, not a bigger batch.
     */
    public const MAX_ROWS = 25;

    public function __construct(private readonly PosService $pos) {}

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{results: list<array<string, mixed>>, synced: int, duplicated: int, rejected: int}
     */
    public function sync(User $user, array $rows): array
    {
        $results = [];
        // A device that queued the same ticket twice (double-tap before the button disabled itself)
        // must not be told one of them was lost, so a repeat inside one batch answers from the first
        // result rather than from the ledger it has not reached yet.
        $byRef = [];

        foreach ($rows as $row) {
            $ref = (string) ($row['client_ref'] ?? '');

            // Only a *posted* first attempt is short-circuited. If the first row was refused, the
            // identical second one has to be refused on its own merits: reporting it as a duplicate of
            // a refusal would tell the cashier the ticket was handled when nothing was ever written.
            if (isset($byRef[$ref]) && $byRef[$ref]['status'] === 'synced') {
                $results[] = array_merge($byRef[$ref], [
                    'status' => 'duplicate',
                    'reason' => 'repeat-in-batch',
                    'message' => 'This ticket appeared twice in one sync; only the first was posted.',
                ]);

                continue;
            }

            $result = $this->syncOne($user, $row);
            $byRef[$ref] = $result;
            $results[] = $result;
        }

        return [
            'results' => $results,
            'synced' => count(array_filter($results, static fn (array $r): bool => $r['status'] === 'synced')),
            'duplicated' => count(array_filter($results, static fn (array $r): bool => $r['status'] === 'duplicate')),
            'rejected' => count(array_filter($results, static fn (array $r): bool => $r['status'] === 'rejected')),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function syncOne(User $user, array $row): array
    {
        $ref = (string) ($row['client_ref'] ?? '');

        if ($ref === '') {
            return $this->rejected($ref, 'A ticket cannot be replayed without its device reference.', ['kind' => 'validation']);
        }

        // Before validating, before anything: a ticket the ledger already has is answered from the
        // ledger. Re-validating it would let a rule that has since tightened turn a completed sale into
        // a 422, which is how a retry ends in a duplicate-or-nothing coin flip.
        $existing = PosSale::where('client_ref', $ref)->first();

        if ($existing !== null) {
            return $this->settled($existing, 'duplicate', 'already-posted');
        }

        $violations = $this->rowViolations($row);

        if ($violations !== []) {
            return $this->rejected($ref, 'The till sent a ticket the server will not accept.', [
                'kind' => 'validation',
                'errors' => $violations,
            ]);
        }

        $rangUpAt = $this->rangUpAt($row);
        $stale = $rangUpAt !== null && $rangUpAt->lessThan(Carbon::today());

        if ($stale && ! filter_var($row['acknowledge_stale_date'] ?? false, FILTER_VALIDATE_BOOL)) {
            return $this->rejected($ref, sprintf(
                'This ticket was rung up on %s, which is not today (%s), so posting it would move money into a day that '
                .'has already been counted. Ring the sale again if the customer still has the goods, or post it to '
                .'today on purpose if the drawer is still open and waiting for it.',
                $rangUpAt->toDateString(),
                Carbon::today()->toDateString(),
            ), ['kind' => 'stale_date', 'rang_up_at' => $rangUpAt->toIso8601String()]);
        }

        try {
            $sale = $this->pos->sell($this->dto($row, $ref, $rangUpAt));
        } catch (\DomainException|\InvalidArgumentException $e) {
            // Stock that is no longer there, a drawer somebody closed while the cable was unplugged, a
            // credit limit the customer has since used up. All correct refusals — the sale stands in the
            // till, unposted, until a person decides what actually happened.
            return $this->rejected($ref, $e->getMessage(), ['kind' => 'business_rule']);
        } catch (UniqueConstraintViolationException) {
            // The gap between the lookup above and this insert, closed by the unique index rather than
            // by a lock: two tabs, or a retry the server answered after the client had given up.
            $race = PosSale::where('client_ref', $ref)->first();

            return $race !== null
                ? $this->settled($race, 'duplicate', 'concurrent-replay')
                : $this->rejected($ref, 'The ticket collided with another sync and could not be read back. Check the sales list before ringing it again.', ['kind' => 'business_rule']);
        }

        $result = $this->settled($sale, 'synced');

        // The till showed the customer a number computed from a *cached* price list. Say so when the
        // two disagree — the cashier, not the ledger, is the place a price change has to surface.
        if (array_key_exists('expected_total', $row) && is_numeric($row['expected_total'])) {
            $driftCents = (int) round(((float) $sale->total_amount - (float) $row['expected_total']) * 100);

            if ($driftCents !== 0) {
                $result['amount_drift'] = number_format($driftCents / 100, 2, '.', '');
                $result['message'] = sprintf(
                    'Posted, but the total moved: the till said %s and the ledger says %s. The price list on the device was stale.',
                    number_format((float) $row['expected_total'], 2, '.', ''),
                    (string) $sale->total_amount,
                );
            }
        }

        return $result;
    }

    /**
     * The till's own rules, applied to one row. See `PosSaleRequest::rowRules()` for why this is the
     * same list and not a copy of it.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, list<string>>
     */
    private function rowViolations(array $row): array
    {
        $rules = PosSaleRequest::rowRules();

        // The sibling-field rule the static list cannot carry, reconstructed from the row's own array.
        $rules['customer_id'][] = Rule::requiredIf(fn (): bool => ($row['payment_method'] ?? null) === 'due');

        $errors = Validator::make($row, $rules)->errors()->toArray();

        // `items.0.product_id` reads as a field path, which is noise to a cashier. Keep the shape, but
        // say which line so the ticket can be fixed rather than re-rung blind.
        return array_map(static fn (array $messages): array => array_values($messages), $errors);
    }

    /** @param  array<string, mixed>  $row */
    private function dto(array $row, string $ref, ?Carbon $rangUpAt): PosSaleData
    {
        $lines = array_map(
            static fn (array $it): InvoiceLineData => new InvoiceLineData(
                productId: (int) $it['product_id'],
                qty: (float) $it['qty'],
                unitPrice: (float) $it['unit_price'],
                variantId: isset($it['variant_id']) ? (int) $it['variant_id'] : null,
                discountPct: (float) ($it['discount_pct'] ?? 0),
                vatRate: (float) ($it['vat_rate'] ?? 0),
            ),
            $row['items'] ?? [],
        );

        return new PosSaleData(
            warehouseId: (int) $row['warehouse_id'],
            lines: $lines,
            paymentMethod: (string) $row['payment_method'],
            customerId: isset($row['customer_id']) ? (int) $row['customer_id'] : null,
            customerName: (string) ($row['customer_name'] ?? 'Walk-in Customer'),
            bankId: isset($row['bank_id']) ? (int) $row['bank_id'] : null,
            cashAmount: (float) ($row['cash_amount'] ?? 0),
            bankAmount: (float) ($row['bank_amount'] ?? 0),
            sessionId: isset($row['session_id']) ? (int) $row['session_id'] : null,
            paidAmount: (float) ($row['paid_amount'] ?? 0),
            tenderedAmount: (float) ($row['tendered_amount'] ?? 0),
            clientRef: $ref,
            rangUpAt: $rangUpAt?->toDateTimeString(),
        );
    }

    /** @param  array<string, mixed>  $row */
    private function rangUpAt(array $row): ?Carbon
    {
        $value = $row['rang_up_at'] ?? null;

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Exception) {
            // A device with a broken clock must not be able to wedge its own queue. The stamp is
            // evidence about when a ticket was taken, not an input to the ledger — the ledger's date is
            // the server's, always.
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function settled(PosSale $sale, string $status, ?string $reason = null): array
    {
        return [
            'client_ref' => $sale->client_ref,
            'status' => $status,
            // Only a duplicate explains itself: `already-posted` and `concurrent-replay` are the two
            // ways a ticket can already be in the ledger, and a till needs to know it did not just
            // write one.
            'reason' => $reason,
            'pos_no' => $sale->pos_no,
            'sale_id' => $sale->id,
            'sale_date' => $sale->sale_date?->toDateString(),
            'total_amount' => (string) $sale->total_amount,
            'rang_up_at' => $sale->rang_up_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function rejected(string $ref, string $message, array $extra = []): array
    {
        return array_merge(['client_ref' => $ref, 'status' => 'rejected', 'message' => $message], $extra);
    }
}
