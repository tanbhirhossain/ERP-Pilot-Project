<?php

namespace Modules\PAYROLL\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollSlip extends Model
{
    protected $table = 'payroll_slips';

    protected $guarded = [];

    protected $casts = ['breakdown_json' => 'array', 'basic_salary' => 'decimal:2', 'gross_salary' => 'decimal:2', 'total_benefits' => 'decimal:2', 'total_deductions' => 'decimal:2', 'net_salary' => 'decimal:2', 'paid_at' => 'datetime'];

    public function salarySheet(): BelongsTo
    {
        return $this->belongsTo(PayrollSalarySheet::class, 'salary_sheet_id');
    }

    /**
     * Withdraw this slip's provident-fund accrual when the slip goes.
     *
     * `pf_ledgers` reaches a slip through a polymorphic pair (`reference_type`/`reference_id`) that
     * the schema does not police, so the 3,433 dead rows P19b had to repair were all created the same
     * way: `payroll_slips.salary_sheet_id` cascades on delete, the slip disappears, and the accrual
     * that pointed at it stays behind — describing a payroll that no longer exists, still counting
     * towards a member's balance.
     *
     * Deleting is refused while a *settled* row exists. A settled contribution has cash behind it in
     * the fund; quietly removing it would make the ledger agree with the payroll and stop agreeing
     * with the bank.
     */
    protected static function booted(): void
    {
        static::deleting(function (PayrollSlip $slip): void {
            $ledger = PfLedger::query()
                ->where('reference_type', 'payroll_slip')
                ->where('reference_id', $slip->id);

            $settled = (clone $ledger)->whereIn('status', ['settled', 'paid'])->count();

            if ($settled > 0) {
                throw new \DomainException(
                    "Slip {$slip->id} cannot be deleted: {$settled} provident-fund entr(ies) for it are already settled. "
                    .'Settled money is in the fund — reverse the settlement first if the slip really must go.'
                );
            }

            $ledger->delete();
        });
    }


    public function loanInstallments(): HasMany
    {
        return $this->hasMany(PayrollLoanInstallment::class, 'payroll_slip_id');
    }

    public function scopeHeld(Builder $query): Builder
    {
        return $query->where('payment_status', 'hold');
    }

    /**
     * Paid slips whose bank journal never made it to the ledger — the payroll/finance divergence
     * that finding 35 made invisible. `payment_posting_error` says why for each one.
     */
    public function scopePaidButUnposted(Builder $query): Builder
    {
        return $query->where('payment_status', 'paid')->whereNull('payment_journal_id');
    }
}
