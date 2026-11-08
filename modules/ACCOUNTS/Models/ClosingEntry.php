<?php

namespace Modules\ACCOUNTS\Models;

use Illuminate\Database\Eloquent\Model;

class ClosingEntry extends Model
{
    protected $table = 'acc_closing_entries';

    protected $fillable = ['fiscal_year_id', 'journal_id', 'type', 'amount', 'created_by'];

    protected $casts = ['amount' => 'decimal:2'];

    public function journal()
    {
        return $this->belongsTo(Journal::class, 'journal_id');
    }

    public function fiscalYear()
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }
}
