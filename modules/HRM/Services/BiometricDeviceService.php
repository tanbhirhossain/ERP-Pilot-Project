<?php
namespace Modules\HRM\Services;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Interfaces\BiometricDeviceRepositoryInterface;
use Modules\HRM\Models\BiometricDevice;
class BiometricDeviceService {
    public function __construct(private readonly BiometricDeviceRepositoryInterface $repo) {}
    public function paginate(DataTableQuery $dt): LengthAwarePaginator { return $this->repo->paginate($dt); }
    public function options(?string $s=null,int $l=25): Collection { return $this->repo->options($s,$l); }
    public function findOrFail(int $id): BiometricDevice { return $this->repo->findOrFail($id); }
    public function create(array $d): BiometricDevice { return $this->repo->create($d); }
    public function update(BiometricDevice $d,array $data): BiometricDevice { return $this->repo->update($d,$data); }
    public function delete(BiometricDevice $d): void { $this->repo->delete($d); }
    public function testConnection(BiometricDevice $d): array { return $this->repo->testConnection($d); }
}
