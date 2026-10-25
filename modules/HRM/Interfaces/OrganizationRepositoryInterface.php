<?php 

namespace Modules\HRM\Interfaces;

interface OrganizationRepositoryInterface
{
    public function setOrganization(array $data);
    public function getOrganization();
    public function updateOrganization($organizationId, array $data);
}

