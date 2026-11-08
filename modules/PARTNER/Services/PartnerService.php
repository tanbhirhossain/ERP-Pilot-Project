<?php

namespace Modules\PARTNER\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Modules\CORE\Support\DataTableQuery;
use Modules\PARTNER\DTO\CreditCheckResult;
use Modules\PARTNER\Interfaces\PartnerRepositoryInterface;
use Modules\PARTNER\Models\Partner;
use Modules\PARTNER\Models\PartnerDocument;
use Modules\PARTNER\Models\PartnerNote;

class PartnerService
{
    public function __construct(private readonly PartnerRepositoryInterface $repo) {}

    public function paginate(DataTableQuery $dt, ?string $type = null): LengthAwarePaginator
    {
        return $this->repo->paginate($dt, $type);
    }

    public function exportBuilder(DataTableQuery $dt, ?string $type = null): Builder
    {
        return $this->repo->exportBuilder($dt, $type);
    }

    public function options(?string $search = null, int $limit = 25, ?string $type = null): Collection
    {
        return $this->repo->options($search, $limit, $type);
    }

    public function findOrFail(int $id): Partner
    {
        return $this->repo->findOrFail($id);
    }

    public function findByCode(string $code): ?Partner
    {
        return $this->repo->findByCode($code);
    }

    public function create(array $data): Partner
    {
        return $this->repo->create($data);
    }

    public function update(Partner $partner, array $data): Partner
    {
        return $this->repo->update($partner, $data);
    }

    public function delete(Partner $partner): void
    {
        $this->repo->delete($partner);
    }

    public function toggleActive(Partner $partner): Partner
    {
        return $this->repo->toggleActive($partner);
    }

    public function verify(Partner $partner, bool $approved, ?string $remarks = null): Partner
    {
        return $this->repo->verify($partner, $approved, $remarks);
    }

    public function creditCheck(Partner $partner, float $additionalAmount = 0): CreditCheckResult
    {
        $creditLimit = (float) $partner->credit_limit;
        $currentBalance = (float) $partner->current_balance;
        $projected = $currentBalance + $additionalAmount;
        $available = max(0, $creditLimit - $currentBalance);
        $utilPct = $creditLimit > 0 ? round(($projected / $creditLimit) * 100, 2) : 0;

        $warningPct = (int) config('partner.credit_check.warning_threshold_pct', 80);
        $block = (bool) config('partner.credit_check.block_on_exceed', false);

        if ($creditLimit <= 0) {
            return new CreditCheckResult(true, $currentBalance, $creditLimit, $available, $additionalAmount, $projected, 0, 'No credit limit set — unlimited.', 'ok');
        }

        if ($projected > $creditLimit) {
            return new CreditCheckResult(
                ! $block, $currentBalance, $creditLimit, $available, $additionalAmount, $projected, $utilPct,
                "Credit limit exceeded. Available: {$available}, Requested: {$additionalAmount}, Projected: {$projected} / Limit: {$creditLimit}",
                'exceeded'
            );
        }

        if ($utilPct >= $warningPct) {
            return new CreditCheckResult(true, $currentBalance, $creditLimit, $available, $additionalAmount, $projected, $utilPct, "Credit utilization high: {$utilPct}% of limit.", 'warning');
        }

        return new CreditCheckResult(true, $currentBalance, $creditLimit, $available, $additionalAmount, $projected, $utilPct, 'Credit check passed.', 'ok');
    }

    public function addNote(Partner $partner, array $data): PartnerNote
    {
        $data['partner_id'] = $partner->id;
        $data['created_by'] = auth()->id();

        return $partner->notes()->create($data);
    }

    public function addDocument(Partner $partner, array $data): PartnerDocument
    {
        if (isset($data['file']) && $data['file'] instanceof UploadedFile) {
            $file = $data['file'];
            $path = $file->store('partners/'.$partner->id.'/documents', 'public');
            $data['file_path'] = $path;
            $data['file_name'] = $file->getClientOriginalName();
            $data['mime_type'] = $file->getMimeType();
            $data['file_size'] = $file->getSize();
            unset($data['file']);
        }

        return $partner->documents()->create($data);
    }
}
