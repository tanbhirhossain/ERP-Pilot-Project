<?php

namespace Modules\ACCOUNTS\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Bank extends Model
{
    use HasFactory, SoftDeletes;
    protected $table = 'acc_banks';
    protected $fillable = ['name', 'code', 'bank_name', 'branch_name', 'account_no', 'account_holder', 'swift_code', 'account_id', 'currency', 'opening_balance', 'current_balance', 'is_default', 'is_reconcile', 'is_active', 'notes', 'created_by'];
    protected $casts = ['opening_balance' => 'decimal:2', 'current_balance' => 'decimal:2', 'is_default' => 'boolean', 'is_reconcile' => 'boolean', 'is_active' => 'boolean'];

    public function account(): BelongsTo { return $this->belongsTo(Account::class, 'account_id'); }
    public function transactions(): HasMany { return $this->hasMany(BankTransaction::class, 'bank_id'); }

    public function scopeActive($q) { return $q->where('is_active', true); }
}
