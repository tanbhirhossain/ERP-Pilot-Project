<?php

namespace Modules\PARTNER\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Repositories\Concerns\BuildsDatatableQueries;
use Modules\CORE\Support\DataTableQuery;
use Modules\PARTNER\Interfaces\PartnerRepositoryInterface;
use Modules\PARTNER\Models\Partner;

class PartnerRepository implements PartnerRepositoryInterface
{
    use BuildsDatatableQueries;

    public function paginate(DataTableQuery $dt, ?string $type = null): LengthAwarePaginator
    {
        $q = $this->baseQuery($type);
        $this->applyDataTable($q, $dt, $this->searchColumns(), $this->sortable(), $this->filters());
        return $q->paginate($dt->perPage, ['*'], 'page', $dt->page)->withQueryString();
    }

    public function exportBuilder(DataTableQuery $dt, ?string $type = null): Builder
    {
        $q = $this->baseQuery($type);
        $this->applyDataTable($q, $dt, $this->searchColumns(), $this->sortable(), $this->filters());
        return $q;
    }

    public function options(?string $search = null, int $limit = 25, ?string $type = null): Collection
    {
        return Partner::query()
            ->when($type, fn (Builder $q) => $type === 'vendor' ? $q->vendors() : ($type === 'customer' ? $q->customers() : $q))
            ->active()
            ->when($search, function (Builder $q, string $term): void {
                $needle = '%'.addcslashes($term, '%_').'%';
                $q->where(function (Builder $g) use ($needle): void {
                    $g->where('partner_code', 'like', $needle)
                        ->orWhere('name', 'like', $needle)
                        ->orWhere('legal_name', 'like', $needle)
                        ->orWhere('phone', 'like', $needle);
                });
            })
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'partner_code', 'name', 'legal_name', 'phone', 'type', 'group_id'])
            ->map(fn (Partner $p) => [
                'value' => $p->partner_code,
                'label' => "{$p->name} ({$p->partner_code})",
                'description' => $p->phone ?? $p->legal_name,
                'id' => $p->id,
                'type' => $p->type,
            ])->values();
    }

    public function findOrFail(int $id): Partner
    {
        return Partner::with(['group', 'priceList', 'contacts', 'addresses', 'banks', 'documents', 'notes.author', 'creator', 'verifier', 'assignee'])->findOrFail($id);
    }

    public function findByCode(string $code): ?Partner
    {
        return Partner::where('partner_code', $code)->first();
    }

    public function create(array $data): Partner
    {
        return DB::transaction(function () use ($data): Partner {
            $partnerData = $this->extractPartnerData($data);
            $partnerData['created_by'] = auth()->id();
            $partnerData['updated_by'] = auth()->id();

            if (empty($partnerData['partner_code'])) {
                $partnerData['partner_code'] = app(\Modules\PARTNER\Services\PartnerCodeGeneratorService::class)->generate($partnerData['type'] ?? 'both');
            }

            $partner = Partner::create($partnerData);

            $this->syncRelations($partner, $data);

            // Log activity
            $partner->activities()->create([
                'user_id' => auth()->id(),
                'action' => 'created',
                'description' => "Partner {$partner->partner_code} created",
            ]);

            return $partner->load(['group', 'contacts', 'addresses', 'banks']);
        });
    }

    public function update(Partner $partner, array $data): Partner
    {
        return DB::transaction(function () use ($partner, $data): Partner {
            $partnerData = $this->extractPartnerData($data);
            $partnerData['updated_by'] = auth()->id();

            // Prevent type change if ledger entries exist (future ACCOUNTS guard)
            $partner->update($partnerData);

            $this->syncRelations($partner, $data, true);

            $partner->activities()->create([
                'user_id' => auth()->id(),
                'action' => 'updated',
                'description' => "Partner {$partner->partner_code} updated",
            ]);

            return $partner->load(['group', 'contacts', 'addresses', 'banks']);
        });
    }

    public function delete(Partner $partner): void
    {
        DB::transaction(function () use ($partner): void {
            // Guard: prevent delete if has transactions (future: check vouchers/invoices)
            $partner->activities()->create([
                'user_id' => auth()->id(),
                'action' => 'deleted',
                'description' => "Partner {$partner->partner_code} deleted",
            ]);
            $partner->delete();
        });
    }

    public function toggleActive(Partner $partner): Partner
    {
        $partner->update(['is_active' => ! $partner->is_active, 'updated_by' => auth()->id()]);
        $partner->activities()->create([
            'user_id' => auth()->id(),
            'action' => $partner->is_active ? 'activated' : 'deactivated',
            'description' => "Partner {$partner->partner_code} " . ($partner->is_active ? 'activated' : 'deactivated'),
        ]);
        return $partner;
    }

    public function verify(Partner $partner, bool $approved, ?string $remarks = null): Partner
    {
        $partner->update([
            'is_verified' => $approved,
            'verification_status' => $approved ? 'approved' : 'rejected',
            'verified_at' => $approved ? now() : null,
            'verified_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        $partner->activities()->create([
            'user_id' => auth()->id(),
            'action' => $approved ? 'verified' : 'rejected',
            'description' => $approved ? "Partner {$partner->partner_code} verified" : "Partner {$partner->partner_code} rejected: {$remarks}",
            'properties' => ['remarks' => $remarks],
        ]);

        return $partner;
    }

    private function baseQuery(?string $type = null): Builder
    {
        return Partner::query()
            ->with(['group:id,name', 'priceList:id,name'])
            ->when($type, function (Builder $q, string $t): void {
                if ($t === 'vendor') $q->vendors();
                elseif ($t === 'customer') $q->customers();
            });
    }

    private function searchColumns(): array
    {
        return ['partner_code', 'name', 'legal_name', 'phone', 'email', 'tin', 'bin', 'trade_license'];
    }

    private function sortable(): array
    {
        return [
            'partner_code' => 'partner_code',
            'name' => 'name',
            'type' => 'type',
            'phone' => 'phone',
            'credit_limit' => 'credit_limit',
            'current_balance' => 'current_balance',
            'created_at' => 'created_at',
            'is_verified' => 'is_verified',
            'is_active' => 'is_active',
        ];
    }

    private function filters(): array
    {
        return [
            'type' => fn (Builder $q, string $v) => $q->where('type', $v),
            'group_id' => fn (Builder $q, string $v) => $q->where('group_id', $v),
            'is_active' => fn (Builder $q, string $v) => $q->where('is_active', $v === '1' || $v === 'true'),
            'is_verified' => fn (Builder $q, string $v) => $q->where('is_verified', $v === '1' || $v === 'true'),
            'verification_status' => fn (Builder $q, string $v) => $q->where('verification_status', $v),
        ];
    }

    private function extractPartnerData(array $data): array
    {
        return collect($data)->only([
            'partner_code', 'type', 'name', 'legal_name', 'group_id', 'price_list_id',
            'trade_license', 'tin', 'bin', 'vat_reg_no', 'nid',
            'phone', 'email', 'website',
            'credit_limit', 'payment_terms_days', 'payment_terms', 'opening_balance', 'current_balance', 'currency',
            'is_active', 'assigned_to', 'notes', 'internal_notes', 'meta',
        ])->toArray();
    }

    private function syncRelations(Partner $partner, array $data, bool $isUpdate = false): void
    {
        if ($isUpdate) {
            // For update, we replace child records — delete then recreate (simple, audit via LogsActivity on child)
            // Contacts
            if (array_key_exists('contacts', $data)) {
                $partner->contacts()->delete();
                foreach ($data['contacts'] ?? [] as $contact) {
                    if (empty(array_filter($contact))) continue;
                    $partner->contacts()->create($contact);
                }
            }
            if (array_key_exists('addresses', $data)) {
                $partner->addresses()->delete();
                foreach ($data['addresses'] ?? [] as $addr) {
                    if (empty(array_filter($addr))) continue;
                    $partner->addresses()->create($addr);
                }
            }
            if (array_key_exists('banks', $data)) {
                $partner->banks()->delete();
                foreach ($data['banks'] ?? [] as $bank) {
                    if (empty(array_filter($bank))) continue;
                    $partner->banks()->create($bank);
                }
            }
        } else {
            foreach ($data['contacts'] ?? [] as $contact) {
                if (empty(array_filter($contact))) continue;
                $partner->contacts()->create($contact);
            }
            foreach ($data['addresses'] ?? [] as $addr) {
                if (empty(array_filter($addr))) continue;
                $partner->addresses()->create($addr);
            }
            foreach ($data['banks'] ?? [] as $bank) {
                if (empty(array_filter($bank))) continue;
                $partner->banks()->create($bank);
            }
        }

        // Ensure only one primary per relation
        $this->enforceSinglePrimary($partner);
    }

    private function enforceSinglePrimary(Partner $partner): void
    {
        $primaryContacts = $partner->contacts()->where('is_primary', true)->get();
        if ($primaryContacts->count() > 1) {
            $keep = $primaryContacts->first();
            $partner->contacts()->where('id', '!=', $keep->id)->update(['is_primary' => false]);
        }
        $primaryAddresses = $partner->addresses()->where('is_default', true)->get();
        if ($primaryAddresses->count() > 1) {
            $keep = $primaryAddresses->first();
            $partner->addresses()->where('id', '!=', $keep->id)->update(['is_default' => false]);
        }
        $primaryBanks = $partner->banks()->where('is_primary', true)->get();
        if ($primaryBanks->count() > 1) {
            $keep = $primaryBanks->first();
            $partner->banks()->where('id', '!=', $keep->id)->update(['is_primary' => false]);
        }
    }
}
