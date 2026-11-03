<?php

namespace Modules\ACCOUNTS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankStatement extends Model
{
    protected $table = 'acc_bank_statements';
    protected $fillable = ['bank_id','txn_date','description','reference_no','amount','balance','matched_bank_txn_id','is_reconciled','reconciled_at','import_batch'];
    protected $casts = ['txn_date'=>'date','amount'=>'decimal:2','balance'=>'decimal:2','is_reconciled'=>'boolean','reconciled_at'=>'datetime'];
    public function bank(): BelongsTo { return $this->belongsTo(Bank::class,'bank_id'); }
    public function matchedTxn(): BelongsTo { return $this->belongsTo(BankTransaction::class,'matched_bank_txn_id'); }
}
