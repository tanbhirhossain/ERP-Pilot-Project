<?php

namespace Modules\HRM\Repositories\Approval;

use Illuminate\Support\Collection;
use Modules\HRM\Interfaces\Approval\OvertimeRequestWorkflowRepositoryInterface;
use Modules\HRM\Models\Approval\OvertimeRequestWorkflow;

class OvertimeRequestWorkflowRepository implements OvertimeRequestWorkflowRepositoryInterface
{
    public function stagesForOvertimeIds(array $ids): Collection
    {
        return OvertimeRequestWorkflow::whereIn('overtime_id', $ids)->get(['steps', 'current_stage']);
    }
}
