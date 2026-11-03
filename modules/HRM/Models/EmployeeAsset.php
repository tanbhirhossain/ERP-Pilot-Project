<?php

namespace Modules\HRM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class EmployeeAsset extends Model
{
    use LogsActivity;
    protected $table = 'hrm_employee_assets';
    protected $guarded = [];
    protected $casts = ['issued_date'=>'date','expected_return_date'=>'date','returned_date'=>'date','recovery_amount'=>'decimal:2'];
    public function asset(): BelongsTo { return $this->belongsTo(HrmAsset::class, 'asset_id'); }
    public function employee(): BelongsTo { return $this->belongsTo(\Modules\HRM\Models\Employee\Employee::class, 'employee_id','employee_id'); }
    public function isOpen(): bool { return $this->returned_date === null; }
    public function getActivitylogOptions(): LogOptions { return LogOptions::defaults()->useLogName('hrm_employee_asset')->logAll()->logOnlyDirty(); }
}
