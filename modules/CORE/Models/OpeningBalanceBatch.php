<?php

namespace Modules\CORE\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\ACCOUNTS\Models\Journal;

/**
 * Audit + idempotency record for one take-on batch (COA openings, AR, AP, stock).
 * The unique reference is what makes re-running an import safe: the wizard
 * refuses a reference that already posted, so a double-click cannot double the
 * opening ledger.
 */
class OpeningBalanceBatch extends Model
{
    protected $table = 'opening_balance_batches';

    protected $guarded = [];

    protected $casts = [
        'as_of_date' => 'date',
        'total_amount' => 'decimal:2',
        'meta' => 'array',
    ];

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'journal_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
