<?php

namespace Modules\HRM\Repositories\Approval;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\HRM\Interfaces\Approval\ApprovalChainRepositoryInterface;
use Modules\HRM\Models\Approval\ApprovalChain;
use Modules\HRM\Models\Approval\ApprovalChainStep;

class ApprovalChainRepository implements ApprovalChainRepositoryInterface
{
    public function stageKeys(): array
    {
        return ApprovalChainStep::STAGE_KEYS;
    }

    public function resolvers(): array
    {
        return ApprovalChainStep::RESOLVERS;
    }

    public function allWithSteps(): Collection
    {
        return ApprovalChain::with('steps')->orderBy('key')->get();
    }

    public function findByKey(string $key, bool $withSteps = false): ?ApprovalChain
    {
        $query = ApprovalChain::where('key', $key);

        if ($withSteps) {
            $query->with('steps');
        }

        return $query->first();
    }

    public function replaceSteps(ApprovalChain $chain, array $steps, ?string $name, ?bool $isActive): ApprovalChain
    {
        return DB::transaction(function () use ($chain, $steps, $name, $isActive) {
            if ($name !== null) {
                $chain->name = $name;
            }

            if ($isActive !== null) {
                $chain->is_active = $isActive;
            }

            $chain->save();

            ApprovalChainStep::where('approval_chain_id', $chain->id)->delete();

            foreach (array_values($steps) as $position => $step) {
                ApprovalChainStep::create([
                    'approval_chain_id' => $chain->id,
                    'position' => $position,
                    'stage_key' => $step['stage_key'],
                    'stage_label' => $step['stage_label'],
                    'resolver' => $step['resolver'],
                    'role_name' => $step['resolver'] === 'role' ? ($step['role_name'] ?? null) : null,
                ]);
            }

            return $chain->fresh()->load('steps');
        });
    }
}
