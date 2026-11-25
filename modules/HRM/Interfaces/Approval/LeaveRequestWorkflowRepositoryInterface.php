<?php

namespace Modules\HRM\Interfaces\Approval;

use Illuminate\Support\Collection;

interface LeaveRequestWorkflowRepositoryInterface
{
    /** Snapshot rows for stage grouping: steps + current_stage per request. */
    public function stagesForLeaveIds(array $ids): Collection;
}
