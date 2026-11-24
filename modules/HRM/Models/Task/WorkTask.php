<?php

namespace Modules\HRM\Models\Task;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\CORE\Models\User;
use Modules\HRM\Models\Employee\Employee;

class WorkTask extends Model
{
    protected $table = 'hrm_tasks';

    protected $guarded = [];

    protected $casts = [
        'due_date' => 'date',
        'completed_at' => 'datetime',
        'verified_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public const PRIORITIES = ['Low', 'Medium', 'High', 'Urgent'];

    public const STATUSES = ['Open', 'In Progress', 'Done', 'Verified', 'Closed', 'Cancelled'];

    /** What the assignee may move to from each status. Anything else is a 422. */
    public const ASSIGNEE_MOVES = [
        'Open' => ['In Progress', 'Done'],
        'In Progress' => ['Open', 'Done'],
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['Open', 'In Progress'], true);
    }
}
