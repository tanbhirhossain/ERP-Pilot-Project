<?php

namespace Modules\PAYROLL\Models;

use Illuminate\Database\Eloquent\Model;

final class PayrollCalculationSnapshot extends Model
{
    protected $table = 'payroll_calculation_snapshots';

    protected $guarded = [];

    protected $casts = ['snapshot_json' => 'array'];
}
