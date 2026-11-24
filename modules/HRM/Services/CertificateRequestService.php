<?php

namespace Modules\HRM\Services;

use Illuminate\Support\Facades\DB;
use Modules\HRM\Models\Certificate\CertificateRequest;

/**
 * Certificate request lifecycle, shared by the mobile app (request/cancel)
 * and the HR desk queue coming in Phase 3 (issue/reject).
 */
class CertificateRequestService
{
    public function request(array $data, ?int $actorId): CertificateRequest
    {
        return DB::transaction(function () use ($data, $actorId): CertificateRequest {
            $duplicate = CertificateRequest::where('employee_id', $data['employee_id'])
                ->where('certificate_type', $data['certificate_type'])
                ->where('status', 'Pending')
                ->exists();

            if ($duplicate) {
                throw new \DomainException('You already have a pending request for this certificate.');
            }

            return CertificateRequest::create($data + [
                'status' => 'Pending',
                'requested_by' => $actorId,
            ]);
        });
    }

    public function cancel(CertificateRequest $row): CertificateRequest
    {
        if (! $row->isPending()) {
            throw new \DomainException("Only a Pending request can be cancelled (this one is {$row->status}).");
        }

        $row->update(['status' => 'Cancelled']);

        return $row->fresh();
    }

    public function issue(CertificateRequest $row, int $actorId, ?string $note = null): CertificateRequest
    {
        if (! $row->isPending()) {
            throw new \DomainException("Only a Pending request can be issued (this one is {$row->status}).");
        }

        $row->update([
            'status' => 'Issued',
            'issued_by' => $actorId,
            'issued_at' => now(),
            'decision_note' => $note,
        ]);

        return $row->fresh();
    }

    public function reject(CertificateRequest $row, int $actorId, ?string $note = null): CertificateRequest
    {
        if (! $row->isPending()) {
            throw new \DomainException("Only a Pending request can be rejected (this one is {$row->status}).");
        }

        $row->update([
            'status' => 'Rejected',
            'issued_by' => $actorId,
            'issued_at' => now(),
            'decision_note' => $note,
        ]);

        return $row->fresh();
    }
}
