<?php
namespace Modules\HRM\Models\Leave;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class LeaveApprovalAction extends Model {
    protected $table='leave_approval_actions'; protected $guarded=[]; protected $casts=['decided_at'=>'datetime'];
    public function leaveRequest(): BelongsTo { return $this->belongsTo(LeaveRequest::class); }
    public function actor(): BelongsTo { return $this->belongsTo(\App\Models\User::class,'actor_id'); }
}
