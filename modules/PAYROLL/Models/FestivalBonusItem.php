<?php

namespace Modules\PAYROLL\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\CORE\Support\Concerns\CoercesNullNumerics;

class FestivalBonusItem extends Model
{
    use CoercesNullNumerics;

    /** NOT NULL numeric columns — `*_id` is excluded on purpose; see the trait. */
    protected function nullToZero(): array
    {
        return ['amount'];
    }

    protected $table = 'festival_bonus_items';

    protected $guarded = [];

    protected $casts = ['amount' => 'decimal:2', 'breakdown_json' => 'array'];

    public function run(): BelongsTo
    {
        return $this->belongsTo(FestivalBonusRun::class, 'festival_bonus_run_id');
    }

    public function slip(): BelongsTo
    {
        return $this->belongsTo(PayrollSlip::class, 'payroll_slip_id');
    }
}
