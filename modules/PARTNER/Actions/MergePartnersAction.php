<?php

namespace Modules\PARTNER\Actions;

use Illuminate\Support\Facades\DB;
use Modules\CORE\Services\AuditTrailService;
use Modules\PARTNER\Models\Partner;

class MergePartnersAction
{
    public function __construct(private readonly AuditTrailService $audit) {}

    /**
     * Merge secondary into primary: move contacts/addresses/banks/docs, sum opening, soft-delete secondary.
     */
    public function execute(Partner $primary, Partner $secondary, ?string $reason = null): Partner
    {
        if ($primary->id === $secondary->id) {
            throw new \InvalidArgumentException('Cannot merge a partner into itself.');
        }

        return DB::transaction(function () use ($primary, $secondary, $reason): Partner {
            // Move relations
            $secondary->contacts()->update(['partner_id' => $primary->id]);
            $secondary->addresses()->update(['partner_id' => $primary->id]);
            $secondary->banks()->update(['partner_id' => $primary->id]);
            $secondary->documents()->update(['partner_id' => $primary->id]);
            $secondary->notes()->update(['partner_id' => $primary->id]);
            $secondary->activities()->update(['partner_id' => $primary->id]);

            // Merge balances
            $primary->increment('opening_balance', (float) $secondary->opening_balance);
            $primary->increment('current_balance', (float) $secondary->current_balance);

            // Preserve secondary code in notes
            $primary->notes()->create([
                'created_by' => auth()->id(),
                'title' => 'Merged from ' . $secondary->partner_code,
                'content' => "Merged partner {$secondary->partner_code} ({$secondary->name}) into {$primary->partner_code}. Reason: " . ($reason ?? 'Duplicate consolidation'),
                'is_private' => true,
            ]);

            $this->audit->log('partner_merge', $primary, "Merged {$secondary->partner_code} into {$primary->partner_code}", 'merged', [
                'primary' => $primary->partner_code,
                'secondary' => $secondary->partner_code,
                'reason' => $reason,
            ]);

            // Soft-delete secondary with audit
            $secondary->update(['notes' => ($secondary->notes ? $secondary->notes . "\n" : '') . "Merged into {$primary->partner_code} on " . now()->toDateString()]);
            $secondary->delete();

            return $primary->load(['contacts', 'addresses', 'banks', 'documents', 'notes']);
        });
    }
}
