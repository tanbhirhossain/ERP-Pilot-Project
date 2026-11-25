<?php

namespace Modules\HRM\Interfaces\Certificate;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\HRM\Models\Certificate\CertificateRequest;

interface CertificateRequestRepositoryInterface
{
    /** The catalogue the request form offers. */
    public function types(): array;

    public function find(int $id): ?CertificateRequest;

    public function findForEmployee(int $id, string $code, bool $withIssuer = false): ?CertificateRequest;

    public function paginateForEmployee(string $code, ?string $status, int $perPage): LengthAwarePaginator;

    public function pendingCountForEmployee(string $code): int;
}
