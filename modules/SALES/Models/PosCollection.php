<?php

namespace Modules\SALES\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\ACCOUNTS\Models\Bank;
use Modules\ACCOUNTS\Models\Receivable;
use Modules\PARTNER\Models\Partner;

/**
 * Money taken at a till against a credit sale raised earlier.
 *
 * A collection is not a journal. It is a drawer event: the cash or card settlement of somebody's
 * outstanding due, recorded against the session whose drawer took it, so that the session's day-end
 * posting carries it (Dr cash/bank, Cr receivables) inside the one journal for that day. Posting it
 * separately would put two entries in the ledger for one day's takings and break the reconciliation
 * between the drawer count and the books — report item 39.
 */
class PosCollection extends Model
{
    protected $table = 'pos_collections';

    protected $fillable = [
        'collection_no', 'session_id', 'pos_sale_id', 'partner_id', 'receivable_id', 'amount',
        'method', 'bank_id', 'collected_by', 'collected_at', 'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'collected_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'session_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(PosSale::class, 'pos_sale_id');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }

    public function receivable(): BelongsTo
    {
        return $this->belongsTo(Receivable::class, 'receivable_id');
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class, 'bank_id');
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by');
    }

    public function isCash(): bool
    {
        return $this->method === 'cash';
    }
}
