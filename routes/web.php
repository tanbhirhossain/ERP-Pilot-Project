<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Entry routes
|--------------------------------------------------------------------------
|
| The admin application lives inside the CORE module (premium dashboard,
| tree sidebar with ACL menu, audit trail). Both starter-kit entry points
| forward into it, so a fresh login lands straight on the real dashboard
| instead of the kit's placeholder pages.
|
*/

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('core.dashboard')
        : redirect()->route('login');
})->name('home');

Route::get('dashboard', function () {
    return redirect()->route('core.dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';


// --- DEBUG: Device Attendance Test (remove after fix) ---
use Jmrashed\Zkteco\Lib\ZKTeco;
use Illuminate\Support\Facades\Log;
Route::get('test-device-compare', function () {
    $ip = request('ip', '192.168.10.94');
    $port = (int) request('port', 4370);
    $zk = new ZKTeco($ip, $port);
    $connected = $zk->connect();
    if (!$connected) return response()->json(['connected' => false, 'error' => 'Cannot connect to '.$ip]);
    $todayYmd = date('Y-m-d');
    $bugDate = date('27-10-2026');
    $attendanceLog = $zk->getAttendance();
    $todayFixed = []; $todayBug = [];
    foreach ($attendanceLog as $r) {
        $d = substr($r['timestamp'], 0, 10);
        if ($d === $todayYmd) $todayFixed[] = $r;
        if ($d === $bugDate) $todayBug[] = $r;
    }
    $latest5 = $zk->getAttendanceWithLimit(5);
    $zk->disconnect();
    return response()->json([
        'connected' => $connected, 'ip' => $ip, 'total' => count($attendanceLog),
        'todayYmd' => $todayYmd, 'bugDate' => $bugDate,
        'todayFixed' => count($todayFixed), 'todayBug' => count($todayBug),
        'latest5' => count($latest5), 'sample' => array_slice($attendanceLog, 0, 3),
        'note' => 'date(27-10-2026) is bug - always literal, use date(Y-m-d)'
    ]);
});
Route::get('test-service-sync', function () {
    $ip = request('ip', '192.168.10.94');
    $device = \Modules\HRM\Models\BiometricDevice::where('ip_address', $ip)->first();
    if (!$device) return response()->json(['error' => 'Device not found: '.$ip]);
    $service = app(\Modules\HRM\Services\Attendance\AttendanceSyncService::class);
    $result = $service->syncDevice($device, null, null);
    return response()->json(['device' => $device->name.' ('.$device->ip_address.')', 'result' => $result]);
});

// --- HRM Attendance Debug (diagnose pairs nil) ---
Route::middleware(['web','auth'])->group(function () {
    Route::get('debug-attendance-orphans', [\Modules\HRM\Http\Controllers\AttendanceDebugController::class, 'orphanStats'])->name('debug.orphans');
    Route::post('debug-reprocess-orphans', [\Modules\HRM\Http\Controllers\AttendanceDebugController::class, 'reprocessOrphans'])->name('debug.reprocess');
});
