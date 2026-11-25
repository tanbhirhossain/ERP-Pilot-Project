<?php

namespace Modules\HRM\Repositories\Approval;

use Illuminate\Support\Collection;
use Modules\HRM\Interfaces\Approval\LeaveRequestWorkflowRepositoryInterface;
use Modules\HRM\Models\Leave\LeaveRequestWorkflow;

class LeaveRequestWorkflowRepository implements LeaveRequestWorkflowRepositoryInterface
{
    public function stagesForLeaveIds(array $ids): Collection
    {
        return LeaveRequestWorkflow::whereIn('leave_request_id', $ids)->get(['steps', 'current_stage']);
    }
}
