<?php

namespace Modules\PAYROLL\Models;

use Illuminate\Database\Eloquent\Model;

final class PayrollWorkflowAudit extends Model
{
    protected $table = 'payroll_workflow_audits';

    protected $fillable = ['subject_type', 'subject_id', 'event', 'actor_id', 'occurred_at'];

    protected $casts = ['subject_id' => 'integer', 'actor_id' => 'integer', 'occurred_at' => 'datetime'];
}
