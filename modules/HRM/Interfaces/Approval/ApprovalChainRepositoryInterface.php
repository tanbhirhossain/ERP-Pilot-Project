<?php

namespace Modules\HRM\Interfaces\Approval;

use Illuminate\Support\Collection;
use Modules\HRM\Models\Approval\ApprovalChain;

interface ApprovalChainRepositoryInterface
{
    public function stageKeys(): array;

    public function resolvers(): array;

    /** @return Collection<int, ApprovalChain> */
    public function allWithSteps(): Collection;

    public function findByKey(string $key, bool $withSteps = false): ?ApprovalChain;

    /**
     * Replace the chain's steps transactionally. Only new requests follow the
     * new chain — in-flight ones keep their snapshot.
     *
     * @param list<array{stage_key: string, stage_label: string, resolver: string, role_name?: ?string}> $steps
     */
    public function replaceSteps(ApprovalChain $chain, array $steps, ?string $name, ?bool $isActive): ApprovalChain;
}
