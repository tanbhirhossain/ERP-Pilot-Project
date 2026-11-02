<?php
namespace Modules\HRM\Services;
use Modules\HRM\Interfaces\OrganizationRepositoryInterface;
use Modules\HRM\Models\Organization;
class OrganizationService {
    public function __construct(private readonly OrganizationRepositoryInterface $repo) {}
    public function get(): ?Organization { return $this->repo->get(); }
    public function upsert(array $data): Organization { return $this->repo->upsert($data); }
}
