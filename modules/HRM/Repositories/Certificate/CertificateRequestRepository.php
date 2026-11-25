<?php

namespace Modules\HRM\Repositories\Certificate;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\HRM\Interfaces\Certificate\CertificateRequestRepositoryInterface;
use Modules\HRM\Models\Certificate\CertificateRequest;

class CertificateRequestRepository implements CertificateRequestRepositoryInterface
{
    public function types(): array
    {
        return CertificateRequest::TYPES;
    }

    public function find(int $id): ?CertificateRequest
    {
        return CertificateRequest::find($id);
    }

    public function findForEmployee(int $id, string $code, bool $withIssuer = false): ?CertificateRequest
    {
        $query = CertificateRequest::where('employee_id', $code);

        if ($withIssuer) {
            $query->with('issuer:id,name');
        }

        return $query->find($id);
    }

    public function paginateForEmployee(string $code, ?string $status, int $perPage): LengthAwarePaginator
    {
        $query = CertificateRequest::where('employee_id', $code)
            ->with('issuer:id,name')
            ->orderByDesc('id');

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        return $query->paginate($perPage);
    }

    public function pendingCountForEmployee(string $code): int
    {
        return CertificateRequest::where('employee_id', $code)->where('status', 'Pending')->count();
    }
}
