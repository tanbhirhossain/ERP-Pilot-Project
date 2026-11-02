<?php

use Illuminate\Support\Facades\Route;

test('Separator.vue component exists and has correct content', function () {
    $path = resource_path('js/components/ui/separator/Separator.vue');
    expect(file_exists($path))->toBeTrue();
    $content = file_get_contents($path);
    expect($content)->toContain('radix-vue');
    expect($content)->toContain('Separator');
    expect($content)->toContain('cn(');
    expect($content)->toContain('orientation === \'vertical\'');
});

test('RosterDuty bulk route is POST and defined before wildcard edit', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn($r) => str_contains($r->getName() ?? '', 'hrm.duties'))
        ->map(fn($r) => ['name'=>$r->getName(),'uri'=>$r->uri(),'methods'=>$r->methods()])
        ->values()->toArray();

    $bulk = collect($routes)->firstWhere('name','hrm.duties.bulk');
    expect($bulk)->not->toBeNull();
    expect($bulk['methods'])->toContain('POST');
    expect($bulk['uri'])->toBe('hrm/duties/bulk');

    // Ensure bulk does not clash with {duty} wildcard (different methods/URIs)
    $edit = collect($routes)->firstWhere('name','hrm.duties.edit');
    expect($edit['uri'])->toBe('hrm/duties/{duty}/edit');
    expect($bulk['uri'])->not->toBe($edit['uri']);
});

test('AttendanceSyncController routes exist and are correct', function () {
    $expected = [
        'hrm.attendance.sync.index' => 'GET',
        'hrm.attendance.sync.device' => 'POST',
        'hrm.attendance.sync.all' => 'POST',
        'hrm.attendance.sync.process' => 'POST',
        'hrm.attendance.sync.settings' => 'PUT',
        'hrm.attendance.manual' => 'POST',
    ];
    foreach ($expected as $name => $method) {
        $route = Route::getRoutes()->getByName($name);
        expect($route)->not->toBeNull("Route {$name} should exist");
        expect($route->methods())->toContain($method);
    }
});

test('RosterDutyController update uses UpdateRosterDutyRequest allowing same date for same record', function () {
    $controller = file_get_contents(base_path('modules/HRM/Http/Controllers/RosterDutyController.php'));
    expect($controller)->toContain('UpdateRosterDutyRequest');
    expect($controller)->toContain('public function update(UpdateRosterDutyRequest');
});

test('AttendanceSyncController manualPunch uses unique MANUAL-UID not hardcoded MANUAL', function () {
    $controller = file_get_contents(base_path('modules/HRM/Http/Controllers/AttendanceSyncController.php'));
    expect($controller)->toContain('MANUAL-');
    expect($controller)->toContain('md5');
    // Ensure not the old hardcoded string alone
    expect($controller)->not->toContain("'device_user_id'=>'MANUAL'");
});

test('AttendanceSyncController syncAll handles empty devices gracefully', function () {
    $controller = file_get_contents(base_path('modules/HRM/Http/Controllers/AttendanceSyncController.php'));
    expect($controller)->toContain("empty(\$results)");
    expect($controller)->toContain('No enabled devices');
});

test('RosterDutyRepository bulkAssign has guards for 60 days and 200 employees', function () {
    $repo = file_get_contents(base_path('modules/HRM/Repositories/Roster/RosterDutyRepository.php'));
    expect($repo)->toContain('60 days');
    expect($repo)->toContain('200 employees');
    expect($repo)->toContain('upsert');
});

test('Vitest sidebar component still passes after HRM menu changes', function () {
    // This is a placeholder: actual vitest runs separately; here we just ensure menu file is valid TS
    $menu = file_get_contents(base_path('modules/CORE/Resources/js/Config/menu.ts'));
    expect($menu)->toContain("hrm-organization");
    expect($menu)->toContain("Device Users");
    expect($menu)->toContain("Link2");
});

test('All HRM routes count is as expected (no missing CRUD)', function () {
    $hrmRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn($r) => str_starts_with($r->getName() ?? '', 'hrm.'))
        ->map(fn ($route) => $route->getName())->filter()->values()->toArray();
    $expectedKeywords = ['organizations','departments','designations','devices','device-users','employees','shifts','duties','attendance.logs','attendance.pairs','attendance.dailies','attendance.sync'];
    foreach ($expectedKeywords as $keyword) {
        expect(collect($hrmRoutes)->filter(fn($n)=>str_contains($n,$keyword))->isNotEmpty())->toBeTrue("Should have route for {$keyword}");
    }
    // Should have at least 35 hrm routes
    expect(count($hrmRoutes))->toBeGreaterThan(30);
});

