<?php

namespace Modules\HRM\Models\Approval;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalChainStep extends Model
{
    protected $table = 'approval_chain_steps';

    protected $guarded = [];

    protected $casts = ['position' => 'integer'];

    public const STAGE_KEYS = ['supervisor', 'hr', 'dgm', 'gm', 'ceo'];

    public const RESOLVERS = ['supervisor', 'role'];

    public function chain(): BelongsTo
    {
        return $this->belongsTo(ApprovalChain::class, 'approval_chain_id');
    }
}
