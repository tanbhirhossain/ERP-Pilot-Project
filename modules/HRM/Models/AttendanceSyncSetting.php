<?php
namespace Modules\HRM\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class AttendanceSyncSetting extends Model {
    use HasFactory;
    protected $guarded = [];
    protected $casts = ['auto_sync_enabled'=>'boolean','is_active'=>'boolean','last_sync_at'=>'datetime','next_sync_at'=>'datetime'];
    public function device(): BelongsTo { return $this->belongsTo(BiometricDevice::class, 'device_id'); }
}
