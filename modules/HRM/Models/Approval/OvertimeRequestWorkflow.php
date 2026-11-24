<?php

namespace Modules\HRM\Models\Approval;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\HRM\Models\Roster\RosterOvertime;

class OvertimeRequestWorkflow extends Model
{
    protected $table = 'overtime_request_workflows';

    protected $guarded = [];

    protected $casts = ['steps' => 'array', 'current_stage' => 'integer'];

    public function overtime(): BelongsTo
    {
        return $this->belongsTo(RosterOvertime::class, 'overtime_id');
    }
}
