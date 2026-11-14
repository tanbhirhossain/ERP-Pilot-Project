<?php

namespace Modules\CORE\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A pending (or decided) request to perform a gated action on one document.
 *
 * The document itself is never modified by the approval flow — the gate is
 * checked at the moment of the action (confirm/post/pay), so a rejected
 * request leaves the document exactly as it was.
 */
class ApprovalRequest extends Model
{
    use LogsActivity;

    protected $table = 'approval_requests';

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'decided_at' => 'datetime',
    ];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(ApprovalPolicy::class, 'policy_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('approval_request')->logAll()->logOnlyDirty()
            ->setDescriptionForEvent(fn (string $e) => "ApprovalRequest {$e}");
    }
}
