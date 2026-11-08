<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\HRM\Models\BiometricDevice;
use Modules\HRM\Models\DeviceUser;
use Modules\HRM\Services\Attendance\AttendanceSyncService;
use Modules\HRM\Services\EmployeeService;

class DeviceUserController extends Controller
{
    /**
     * Global Device-User → Employee mapping.
     * No device binding — one ZK id (UserID, device_user_id) maps to one EMP-xxxx globally (uid is internal only)
     * This allows punch-in on Device A and punch-out on Device B to resolve to same employee.
     */
    public function index(Request $r): Response
    {
        $dt = DataTableQuery::fromRequest($r, ['id', 'device_user_id', 'name', 'created_at'], ['employee_id']);

        $q = DeviceUser::with(['employee:id,employee_id,full_name']);

        if ($r->input('search')) {
            $needle = '%'.addcslashes($r->input('search'), '%_').'%';
            $q->where(fn ($g) => $g
                ->where('device_user_id', 'like', $needle)
                ->orWhere('name', 'like', $needle)
                ->orWhere('employee_id', 'like', $needle)
                ->orWhereHas('employee', fn ($e) => $e->where('full_name', 'like', $needle))
            );
        }

        if ($filter = $r->input('filters.employee_id')) {
            $q->where('employee_id', $filter);
        }

        $sort = $r->input('sort', 'created_at');
        $dir = $r->input('dir', 'desc');
        $map = ['id' => 'id', 'device_user_id' => 'device_user_id', 'name' => 'name', 'created_at' => 'created_at'];
        $q->orderBy($map[$sort] ?? 'created_at', $dir === 'asc' ? 'asc' : 'desc');

        $paginator = $q->paginate($r->input('per_page', 25), ['*'], 'page', $r->input('page', 1))->withQueryString();

        return Inertia::render('HRM::DeviceUsers/Index', [
            'deviceUsers' => PaginatedResource::from($paginator->through(fn (DeviceUser $du) => [
                'id' => $du->id,
                'uid' => $du->uid,
                'device_user_id' => $du->device_user_id,
                'name' => $du->name,
                'employee_id' => $du->employee_id,
                'employee' => $du->employee ? ['employee_id' => $du->employee->employee_id, 'full_name' => $du->employee->full_name] : null,
                'created_at' => $du->created_at?->toIso8601String(),
            ])),
            // Keep deviceOptions only for the optional "Sync from device" helper — mapping itself is global
            'deviceOptions' => BiometricDevice::orderBy('name')->get()->map(fn ($d) => ['value' => $d->id, 'label' => $d->name.' ('.$d->ip_address.')'])->values()->all(),
            'employeeOptions' => app(EmployeeService::class)->options(null, 200)->toArray(),
        ]);
    }

    public function store(Request $r): RedirectResponse
    {
        $data = $r->validate([
            'uid' => ['nullable', 'string', 'max:50'],
            'device_user_id' => ['required', 'string', 'max:50', 'unique:device_users,device_user_id'],
            'employee_id' => ['required', 'exists:employees,employee_id', 'unique:device_users,employee_id'],
            'name' => ['nullable', 'string', 'max:255'],
        ], [
            'employee_id.unique' => 'This employee is already mapped to another Device User ID.',
            'device_user_id.unique' => 'This Device User ID is already mapped.',
        ]);

        // Explicitly not binding to device — global mapping only. device_id stored as NULL for clean cross-device punches.
        $data['device_id'] = null;

        DeviceUser::create($data);

        return back()->with('success', 'Employee → Device mapping created.');
    }

    public function update(Request $r, DeviceUser $deviceUser): RedirectResponse
    {
        $data = $r->validate([
            'uid' => ['nullable', 'string', 'max:50'],
            'device_user_id' => ['required', 'string', 'max:50', Rule::unique('device_users', 'device_user_id')->ignore($deviceUser->id)],
            'employee_id' => ['required', 'exists:employees,employee_id', Rule::unique('device_users', 'employee_id')->ignore($deviceUser->id)],
            'name' => ['nullable', 'string', 'max:255'],
        ], [
            'employee_id.unique' => 'This employee is already mapped to another Device User ID.',
        ]);

        $deviceUser->update($data);

        return back()->with('success', 'Mapping updated.');
    }

    public function destroy(DeviceUser $deviceUser): RedirectResponse
    {
        $deviceUser->delete();

        return back()->with('success', 'Mapping removed — punch IDs will show as orphan until remapped.');
    }

    /**
     * Sync users from a physical device into global mapping.
     * Creates global entries (device_id = null) if not already mapped.
     * Does NOT create per-device duplicates — one ZK id = one global row (uid never for mapping)
     */
    public function syncFromDevice(Request $r, BiometricDevice $device): RedirectResponse
    {
        try {
            $sync = app(AttendanceSyncService::class);
            $users = $sync->fetchUsers($device);
            $created = 0;
            $skipped = 0;
            $updatedName = 0;

            foreach ($users as $u) {
                $uid = $u['uid'] ?? $u[0] ?? null;
                $userid = $u['userid'] ?? $u['user_id'] ?? $u['id'] ?? $u[1] ?? null;
                $name = $u['name'] ?? $u[2] ?? null;
                if (! $userid) {
                    continue;
                }

                $useridStr = (string) $userid;

                // Global check — device_id ignored (cross-device)
                $existing = DeviceUser::where('device_user_id', $useridStr)->first();
                if ($existing) {
                    // Optionally backfill name if empty and device has it
                    if (! $existing->name && $name) {
                        $existing->update(['name' => $name]);
                        $updatedName++;
                    }
                    $skipped++;

                    continue;
                }

                DeviceUser::create([
                    'device_id' => null,
                    'uid' => (string) ($uid ?? $userid),
                    'device_user_id' => $useridStr,
                    'name' => $name,
                    'employee_id' => null, // leave unmapped — admin will link to EMP-xxxx via UI
                ]);
                $created++;
            }

            $msg = "Synced {$created} new Device IDs globally";
            if ($skipped) {
                $msg .= ", {$skipped} already existed";
            }
            if ($updatedName) {
                $msg .= ", {$updatedName} names updated";
            }
            $msg .= '. Link them to employees in the table below.';

            return back()->with('success', $msg);
        } catch (\Throwable $e) {
            return back()->with('error', 'Device fetch failed: '.$e->getMessage());
        }
    }
}
