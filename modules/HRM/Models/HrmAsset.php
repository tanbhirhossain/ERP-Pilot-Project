<?php

namespace Modules\HRM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class HrmAsset extends Model
{
    use SoftDeletes, LogsActivity;
    protected $table = 'hrm_assets';
    protected $guarded = [];
    protected $casts = ['unit_cost' => 'decimal:2'];
    public function assignments(): HasMany { return $this->hasMany(EmployeeAsset::class, 'asset_id'); }
    public function currentAssignment(): HasMany { return $this->hasMany(EmployeeAsset::class, 'asset_id')->whereNull('returned_date')->latest(); }
    public function isAvailable(): bool { return $this->status === 'In Stock' || $this->status === 'Returned'; }
    public function getActivitylogOptions(): LogOptions { return LogOptions::defaults()->useLogName('hrm_asset')->logAll()->logOnlyDirty(); }
}
