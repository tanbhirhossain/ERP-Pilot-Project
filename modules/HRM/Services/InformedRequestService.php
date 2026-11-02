<?php
namespace Modules\HRM\Services;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Interfaces\Informed\InformedRequestRepositoryInterface;
use Modules\HRM\Models\Informed\InformedRequest;
class InformedRequestService {
    public function __construct(private readonly InformedRequestRepositoryInterface $repo) {}
    public function paginate(DataTableQuery $dt): LengthAwarePaginator { return $this->repo->paginate($dt); }
    public function findOrFail(int $id): InformedRequest { return $this->repo->findOrFail($id); }
    public function create(array $d): InformedRequest { return $this->repo->create($d); }
    public function update(InformedRequest $r, array $d): InformedRequest { return $this->repo->update($r,$d); }
    public function delete(InformedRequest $r): void { $this->repo->delete($r); }
    public function acknowledge(InformedRequest $r): InformedRequest {
        return DB::transaction(function() use($r){
            if($r->status!=='Pending') throw new \RuntimeException('Only Pending can be acknowledged');
            $r->update(['status'=>'Acknowledged','approved_by'=>auth()->id(),'approved_at'=>now()]);
            return $r->refresh();
        });
    }
    public function reject(InformedRequest $r): InformedRequest {
        if($r->status!=='Pending') throw new \RuntimeException('Only Pending can be rejected');
        $r->update(['status'=>'Rejected','approved_by'=>auth()->id(),'approved_at'=>now()]);
        return $r;
    }
}
