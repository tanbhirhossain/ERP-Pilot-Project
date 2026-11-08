<?php

namespace Modules\HRM\Repositories;

use Modules\HRM\Interfaces\OrganizationRepositoryInterface;
use Modules\HRM\Models\Organization;

class OrganizationRepository implements OrganizationRepositoryInterface
{
    public function get(): ?Organization
    {
        return Organization::query()->first();
    }

    public function upsert(array $data): Organization
    {
        $org = Organization::query()->first();
        if ($org) {
            $org->update($data);

            return $org->refresh();
        }

        return Organization::create($data);
    }
}
