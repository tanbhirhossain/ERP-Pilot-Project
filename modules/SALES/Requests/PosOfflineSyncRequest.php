<?php

namespace Modules\SALES\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\SALES\Services\PosOfflineSyncService;

/**
 * The envelope of an offline sync — and deliberately nothing more.
 *
 * A batch may contain one ticket that has gone stale, one that names a drawer somebody closed while
 * the network was down, and twenty good ones. Validating the rows here would answer that with a single
 * 422 and throw the twenty away, which is precisely the failure an offline queue cannot afford: the
 * money is already in the drawer, so a rejected batch means tickets the ledger never sees.
 *
 * So this request checks the *shape* (a non-empty array of rows, each carrying the device key the
 * sync is identified by) and `PosOfflineSyncService` validates each row against the till's own rules
 * (`PosSaleRequest::rowRules()`), one row at a time, reporting per-row verdicts.
 *
 * Authorisation is the till's permission and nothing else: syncing a queued sale *is* selling, so a
 * role that can ring a sale can replay one. Inventing `pos.offline.sync` would split one act across two
 * grants and quietly break every existing cashier role.
 */
class PosOfflineSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('pos.terminal.sell') ?? false;
    }

    public function rules(): array
    {
        return [
            'sales' => ['required', 'array', 'min:1', 'max:'.PosOfflineSyncService::MAX_ROWS],
            // Required, not inferred from the payload's hash: two tabs on one device queue distinct
            // tickets that can look identical (same cart, same minute), and a hash would merge them
            // into one sale. The UUID is minted once, at the moment the cashier presses Sell.
            'sales.*.client_ref' => ['required', 'string', 'uuid'],
            'sales.*.rang_up_at' => ['nullable', 'date'],
            // Set only when a human has read the "this was rung up on 7 Nov" refusal and chosen to
            // post it into today anyway. Never set by the device on its own.
            'sales.*.acknowledge_stale_date' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'sales.max' => 'A sync may carry at most '.PosOfflineSyncService::MAX_ROWS.' tickets. The rest will go on the next flush.',
            'sales.*.client_ref.required' => 'Every queued ticket must carry its device reference — without it a retry would double the sale.',
            'sales.*.client_ref.uuid' => 'A ticket reference must be the UUID the till generated when the sale was queued.',
        ];
    }
}
