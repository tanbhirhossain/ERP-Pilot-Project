<?php

namespace Modules\PARTNER\Actions;

use Illuminate\Support\Facades\DB;
use Modules\CORE\Services\AuditTrailService;
use Modules\PARTNER\Models\Partner;

class VerifyPartnerAction
{
    public function __construct(private readonly AuditTrailService $audit) {}

    public function execute(Partner $partner, bool $approve, ?string $remarks = null): Partner
    {
        return DB::transaction(function () use ($partner, $approve, $remarks): Partner {
            $prev = $partner->verification_status;

            $partner->update([
                'is_verified' => $approve,
                'verification_status' => $approve ? 'approved' : 'rejected',
                'verified_at' => $approve ? now() : null,
                'verified_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]);

            $this->audit->log(
                'partner_verification',
                $partner,
                $approve ? "Partner {$partner->partner_code} approved" : "Partner {$partner->partner_code} rejected",
                $approve ? 'approved' : 'rejected',
                ['from' => $prev, 'to' => $partner->verification_status, 'remarks' => $remarks]
            );

            $partner->activities()->create([
                'user_id' => auth()->id(),
                'action' => $approve ? 'approved' : 'rejected',
                'description' => $approve ? 'Verified by '.auth()->user()?->name : "Rejected: {$remarks}",
                'properties' => ['remarks' => $remarks, 'from' => $prev],
            ]);

            return $partner;
        });
    }
}
