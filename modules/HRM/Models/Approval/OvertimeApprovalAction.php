<?php

namespace Modules\HRM\Models\Approval;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\CORE\Models\User;
use Modules\HRM\Models\Roster\RosterOvertime;

class OvertimeApprovalAction extends Model
{
    protected $table = 'overtime_approval_actions';

    protected $guarded = [];

    protected $casts = ['stage_index' => 'integer', 'decided_at' => 'datetime'];

    public function overtime(): BelongsTo
    {
        return $this->belongsTo(RosterOvertime::class, 'overtime_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
