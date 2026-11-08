<?php

namespace Modules\PAYROLL\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\CORE\Support\Concerns\CoercesNullNumerics;

class PayrollBankAdviceItem extends Model
{
    use CoercesNullNumerics;

    /** NOT NULL numeric columns — `*_id` is excluded on purpose; see the trait. */
    protected function nullToZero(): array
    {
        return ['net_amount'];
    }

    protected $table = 'payroll_bank_advice_items';

    protected $guarded = [];

    protected $casts = ['net_amount' => 'decimal:2'];

    public function advice(): BelongsTo
    {
        return $this->belongsTo(PayrollBankAdvice::class, 'advice_id');
    }

    // BEFTN row format: SL, Account No, Account Name, Amount, Routing, Reference
    public function toBeftnRow(int $sl): array
    {
        return [
            'SL' => $sl,
            'Account No' => $this->bank_account_no ?? '',
            'Account Name' => $this->employee_name,
            'Amount' => number_format((float) $this->net_amount, 2, '.', ''),
            'Routing No' => $this->routing_no ?? '',
            'Bank' => $this->bank_name ?? '',
            'Branch' => $this->branch_name ?? '',
            'Reference' => $this->reference ?? $this->employee_id,
        ];
    }
}
