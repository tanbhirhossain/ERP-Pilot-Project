<?php

namespace Modules\HRM\Models\Approval;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApprovalChain extends Model
{
    protected $table = 'approval_chains';

    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];

    public const LEAVE = 'leave';

    public const OVERTIME = 'overtime';

    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalChainStep::class, 'approval_chain_id')->orderBy('position');
    }

    /**
     * Ordered step rows for the active chain, or null when no active chain is
     * configured (callers fall back to their legacy single stage).
     *
     * @return list<array<string, mixed>>|null
     */
    public static function activeStepsFor(string $key): ?array
    {
        $chain = self::where('key', $key)->where('is_active', true)->first();

        if ($chain === null) {
            return null;
        }

        $steps = $chain->steps()->orderBy('position')->get();

        if ($steps->isEmpty()) {
            return null;
        }

        return $steps->map(fn (ApprovalChainStep $s): array => [
            'position' => (int) $s->position,
            'stage_key' => $s->stage_key,
            'stage_label' => $s->stage_label,
            'resolver' => $s->resolver,
            'role_name' => $s->role_name,
        ])->all();
    }
}
