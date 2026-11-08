<?php

namespace Modules\HRM\Interfaces;

use Modules\HRM\Models\Organization;

interface OrganizationRepositoryInterface
{
    public function get(): ?Organization;

    public function upsert(array $data): Organization;
}
