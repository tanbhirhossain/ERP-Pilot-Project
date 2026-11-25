<?php

namespace Modules\HRM\Interfaces\Approval;

use Illuminate\Support\Collection;

interface OvertimeRequestWorkflowRepositoryInterface
{
    /** Snapshot rows for stage grouping: steps + current_stage per record. */
    public function stagesForOvertimeIds(array $ids): Collection;
}
