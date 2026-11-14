<?php

namespace Modules\ACCOUNTS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * F17 — a treasury challan depositing withheld tax (TDS/VDS) or net VAT to NBR.
 */
class TaxDeposit extends Model
{
    protected $table = 'tax_deposits';

    protected $fillable = ['deposit_no', 'type', 'period', 'amount', 'deposit_date', 'bank_id', 'challan_no', 'treasury_code', 'journal_id', 'notes', 'created_by'];

    protected $casts = [
        'period' => 'date',
        'deposit_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class, 'bank_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'journal_id');
    }

    public function withholdings(): HasMany
    {
        return $this->hasMany(Withholding::class, 'deposit_id');
    }
}
