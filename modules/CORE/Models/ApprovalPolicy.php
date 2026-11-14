<?php

namespace Modules\CORE\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One rule of the approval matrix: "documents of this type at or above this
 * amount need a decision from someone holding this permission".
 *
 * The highest-priority active policy whose min_amount is met wins, so a
 * deployment can layer "everything above 10k needs a manager, everything
 * above 100k needs finance" without code changes.
 */
class ApprovalPolicy extends Model
{
    use LogsActivity;

    protected $table = 'approval_policies';

    protected $guarded = [];

    protected $casts = [
        'min_amount' => 'decimal:2',
        'require_segregation' => 'boolean',
        'is_active' => 'boolean',
        'priority' => 'integer',
    ];

    public function requests(): HasMany
    {
        return $this->hasMany(ApprovalRequest::class, 'policy_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('approval_policy')->logAll()->logOnlyDirty()
            ->setDescriptionForEvent(fn (string $e) => "ApprovalPolicy {$e}");
    }
}
