<?php
namespace Modules\HRM\Models\Leave;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class LeaveRequestWorkflow extends Model {
    protected $table='leave_request_workflows'; protected $guarded=[];
    protected $casts=['steps'=>'array','current_stage'=>'integer'];
    public function leaveRequest(): BelongsTo { return $this->belongsTo(LeaveRequest::class); }
}
