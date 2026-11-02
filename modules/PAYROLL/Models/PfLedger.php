<?php

namespace Modules\PAYROLL\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PfLedger extends Model
{
    protected $table = 'pf_ledgers';
    protected $guarded = [];
    protected $casts = ['amount' => 'decimal:2', 'entry_date' => 'date'];
    public function account(): BelongsTo { return $this->belongsTo(PfAccount::class, 'pf_account_id'); }
}
