<?php

namespace Modules\SALES\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\ACCOUNTS\Models\Journal;
use Modules\INVENTORY\Models\Warehouse;

class PosSession extends Model
{
    protected $table = 'pos_sessions';

    protected $fillable = [
        'session_no', 'warehouse_id', 'opened_by', 'closed_by', 'opening_cash', 'closing_cash',
        'expected_cash', 'cash_difference', 'total_sales', 'transactions_count', 'status',
        'opened_at', 'closed_at', 'notes',
        // Day-end posting (item 39): the one journal this session's takings went to, and what it
        // carried. Kept on the session so the drawer reconciles without re-deriving it from sales.
        'journal_id', 'posted_at', 'discount_total', 'net_sales_total', 'vat_total', 'cash_total',
        'bank_total', 'due_total', 'collected_total', 'cogs_total', 'void_count',
    ];

    /**
     * Every money column carries `decimal:2`, which `MoneyColumnCastTest` discovers and enforces:
     * the cast is what makes a total read back as exact decimal text rather than a re-rounded
     * double, and a day-end journal is built by summing these.
     */
    protected $casts = [
        'opening_cash' => 'decimal:2', 'closing_cash' => 'decimal:2', 'expected_cash' => 'decimal:2',
        'cash_difference' => 'decimal:2', 'total_sales' => 'decimal:2',
        'discount_total' => 'decimal:2', 'net_sales_total' => 'decimal:2', 'vat_total' => 'decimal:2',
        'cash_total' => 'decimal:2', 'bank_total' => 'decimal:2', 'due_total' => 'decimal:2',
        'collected_total' => 'decimal:2', 'cogs_total' => 'decimal:2',
        'transactions_count' => 'integer', 'void_count' => 'integer',
        'opened_at' => 'datetime', 'closed_at' => 'datetime', 'posted_at' => 'datetime',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(PosSale::class, 'session_id');
    }

    /** The day-end journal, when this session has been posted. */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'journal_id');
    }

    /** Money taken at this till against credit sales raised earlier. */
    public function collections(): HasMany
    {
        return $this->hasMany(PosCollection::class, 'session_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /** Has the day been posted to the ledger? Once it has, the numbers are no longer editable. */
    public function isPosted(): bool
    {
        return $this->journal_id !== null;
    }
}
