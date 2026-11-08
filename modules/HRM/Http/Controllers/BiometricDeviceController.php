<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\HRM\Models\BiometricDevice;
use Modules\HRM\Requests\StoreBiometricDeviceRequest;
use Modules\HRM\Requests\UpdateBiometricDeviceRequest;
use Modules\HRM\Services\BiometricDeviceService;

class BiometricDeviceController extends Controller
{
    public function __construct(private readonly BiometricDeviceService $svc) {}

    public function index(Request $r): Response
    {
        $dt = DataTableQuery::fromRequest($r, ['id', 'name', 'ip_address', 'status', 'created_at'], ['status']);

        return Inertia::render('HRM::BiometricDevices/Index', ['devices' => PaginatedResource::from($this->svc->paginate($dt)->through(fn (BiometricDevice $d) => ['id' => $d->id, 'name' => $d->name, 'device_name' => $d->device_name, 'ip_address' => $d->ip_address, 'port' => $d->port, 'serial_number' => $d->serial_number, 'device_model' => $d->device_model, 'location' => $d->location, 'status' => $d->status, 'is_enabled' => $d->is_enabled, 'auto_sync_enabled' => $d->auto_sync_enabled, 'sync_interval_minutes' => $d->sync_interval_minutes, 'last_sync_at' => $d->last_sync_at?->toIso8601String(), 'created_at' => $d->created_at?->toIso8601String()]))]);
    }

    public function create(): Response
    {
        return Inertia::render('HRM::BiometricDevices/Create');
    }

    public function store(StoreBiometricDeviceRequest $req): RedirectResponse
    {
        $this->svc->create($req->validated());

        return redirect()->route('hrm.devices.index')->with('success', 'Device created.');
    }

    public function edit(BiometricDevice $device): Response
    {
        return Inertia::render('HRM::BiometricDevices/Edit', ['device' => ['id' => $device->id, 'name' => $device->name, 'device_name' => $device->device_name, 'ip_address' => $device->ip_address, 'port' => $device->port, 'serial_number' => $device->serial_number, 'device_model' => $device->device_model, 'location' => $device->location, 'status' => $device->status, 'is_enabled' => $device->is_enabled, 'auto_sync_enabled' => $device->auto_sync_enabled, 'sync_interval_minutes' => $device->sync_interval_minutes]]);
    }

    public function update(UpdateBiometricDeviceRequest $req, BiometricDevice $device): RedirectResponse
    {
        $this->svc->update($device, $req->validated());

        return redirect()->route('hrm.devices.index')->with('success', 'Device updated.');
    }

    public function destroy(BiometricDevice $device): RedirectResponse
    {
        $this->svc->delete($device);

        return redirect()->route('hrm.devices.index')->with('success', 'Device deleted.');
    }

    public function test(BiometricDevice $device): RedirectResponse
    {
        $res = $this->svc->testConnection($device);

        return back()->with($res['ok'] ? 'success' : 'error', $res['message']);
    }
}
