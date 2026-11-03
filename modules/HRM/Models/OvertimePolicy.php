<?php

namespace Modules\HRM\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class OvertimePolicy extends Model
{
    use LogsActivity;
    protected $table = 'hrm_overtime_policies';
    protected $guarded = [];
    protected $casts = ['max_ot_hours_daily'=>'decimal:2','max_ot_hours_monthly'=>'decimal:2','ot_rate_multiplier'=>'decimal:2','holiday_ot_rate'=>'decimal:2','extra_duty_rate'=>'decimal:2','requires_approval'=>'boolean','is_active'=>'boolean'];
    public function scopeActive($q){ return $q->where('is_active',true); }
    public function validateHours(float $hours, string $type='OT'): void {
        $cap = $type === 'OT' ? (float)$this->max_ot_hours_daily : (float)$this->max_ot_hours_monthly;
        // daily cap check
        if ($type==='OT' && $hours > (float)$this->max_ot_hours_daily) throw new \DomainException("OT {$hours}h exceeds daily cap {$this->max_ot_hours_daily}h per policy {$this->name}.");
    }
    public function getActivitylogOptions(): LogOptions { return LogOptions::defaults()->useLogName('hrm_ot_policy')->logAll()->logOnlyDirty(); }
}
