<?php

namespace Modules\HRM\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Repositories\Concerns\BuildsDatatableQueries;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Interfaces\BiometricDeviceRepositoryInterface;
use Modules\HRM\Models\BiometricDevice;

class BiometricDeviceRepository implements BiometricDeviceRepositoryInterface
{
    use BuildsDatatableQueries;

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        $q = $this->baseQuery();
        $this->applyDataTable($q, $dt, $this->searchColumns(), $this->sortable(), $this->filters());

        return $q->paginate($dt->perPage, ['*'], 'page', $dt->page)->withQueryString();
    }

    public function exportBuilder(DataTableQuery $dt): Builder
    {
        $q = $this->baseQuery();
        $this->applyDataTable($q, $dt, $this->searchColumns(), $this->sortable(), $this->filters());

        return $q;
    }

    public function options(?string $search = null, int $limit = 25): Collection
    {
        return BiometricDevice::query()->when($search, fn (Builder $q, $t) => $q->where('name', 'like', '%'.addcslashes($t, '%_').'%'))->orderBy('name')->limit($limit)->get(['id', 'name', 'ip_address'])->map(fn ($d) => ['value' => $d->id, 'label' => $d->name, 'description' => $d->ip_address])->values();
    }

    public function findOrFail(int $id): BiometricDevice
    {
        return BiometricDevice::with('syncSetting')->findOrFail($id);
    }

    public function create(array $data): BiometricDevice
    {
        return DB::transaction(fn () => BiometricDevice::create($data));
    }

    public function update(BiometricDevice $d, array $data): BiometricDevice
    {
        return DB::transaction(function () use ($d, $data) {
            $d->fill($data);
            $d->save();

            return $d;
        });
    }

    public function delete(BiometricDevice $d): void
    {
        DB::transaction(fn () => $d->delete());
    }

    public function testConnection(BiometricDevice $device): array
    {
        $host = $device->ip_address;
        $port = $device->port ?? 4370;
        $fp = @fsockopen($host, $port, $errno, $errstr, 5);
        if ($fp) {
            fclose($fp);

            return ['ok' => true, 'message' => "Successfully connected to {$host}:{$port}"];
        }

        return ['ok' => false, 'message' => "Failed to connect to {$host}:{$port} - {$errstr} ({$errno})"];
    }

    private function baseQuery(): Builder
    {
        return BiometricDevice::query()->with('syncSetting');
    }

    private function searchColumns(): array
    {
        return ['name', 'ip_address', 'serial_number', 'location'];
    }

    private function sortable(): array
    {
        return ['id' => 'id', 'name' => 'name', 'ip_address' => 'ip_address', 'status' => 'status', 'created_at' => 'created_at'];
    }

    private function filters(): array
    {
        return ['status' => fn (Builder $q, string $v) => $q->where('status', $v)];
    }
}
