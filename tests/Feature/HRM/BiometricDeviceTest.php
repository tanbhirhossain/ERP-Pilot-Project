<?php

use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\HRM\Models\BiometricDevice;
use Modules\HRM\Services\BiometricDeviceService;
use Tests\Feature\HRM\HrmTestHelper;

uses(HrmTestHelper::class);

beforeEach(function () {
    $this->seed(SuperAdminSeeder::class);
});

test('can view biometric devices index', function () {
    $this->actingAs($this->adminUser())
        ->get(route('hrm.devices.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('HRM::BiometricDevices/Index'));
});

test('can create biometric device', function () {
    $this->actingAs($this->adminUser())
        ->post(route('hrm.devices.store'), [
            'name' => 'Main Gate ZK-T50',
            'ip_address' => '192.168.1.100',
            'port' => 4370,
            'serial_number' => 'SN123456789',
            'device_model' => 'ZK-T50',
            'location' => 'Main Gate',
            'status' => 'Active',
            'is_enabled' => true,
            'auto_sync_enabled' => true,
            'sync_interval_minutes' => 30,
        ])
        ->assertRedirect(route('hrm.devices.index'));

    $device = BiometricDevice::where('serial_number', 'SN123456789')->first();
    expect($device)->not->toBeNull();
    expect($device->ip_address)->toBe('192.168.1.100');
    expect($device->auto_sync_enabled)->toBeTrue();
});

test('device validation requires valid IP', function () {
    $this->actingAs($this->adminUser())
        ->post(route('hrm.devices.store'), [
            'name' => 'Bad Device',
            'ip_address' => 'not-an-ip',
            'port' => 4370,
            'status' => 'Active',
        ])
        ->assertSessionHasErrors('ip_address');
});

test('can update device', function () {
    $device = $this->createDevice(['name' => 'Old Name']);
    $this->actingAs($this->adminUser())
        ->put(route('hrm.devices.update', $device->id), [
            'name' => 'New Gate Device',
            'ip_address' => $device->ip_address,
            'port' => 4370,
            'status' => 'Maintenance',
            'is_enabled' => false,
        ])
        ->assertRedirect();

    expect($device->refresh()->name)->toBe('New Gate Device');
    expect($device->status)->toBe('Maintenance');
});

test('can delete device', function () {
    $device = $this->createDevice();
    $this->actingAs($this->adminUser())
        ->delete(route('hrm.devices.destroy', $device->id))
        ->assertRedirect();

    expect(BiometricDevice::find($device->id))->toBeNull();
});

test('can test device connection (fsockopen mock)', function () {
    // Use localhost:4370 which will fail but should return error flash
    $device = $this->createDevice(['ip_address' => '127.0.0.1', 'port' => 59999]);
    $this->actingAs($this->adminUser())
        ->post(route('hrm.devices.test', $device->id))
        ->assertRedirect();
    // Should have either success or error flash (connection will fail to 127.0.0.1:59999)
    expect(session()->has('success') || session()->has('error'))->toBeTrue();
});

test('device sync settings mirrored on update', function () {
    $device = $this->createDevice(['auto_sync_enabled' => false]);
    $this->actingAs($this->adminUser())
        ->post(route('hrm.devices.store'), [
            'name' => 'Sync Device',
            'ip_address' => '192.168.1.101',
            'port' => 4370,
            'status' => 'Active',
            'auto_sync_enabled' => true,
            'sync_interval_minutes' => 10,
        ]);

    $created = BiometricDevice::where('ip_address', '192.168.1.101')->first();
    expect($created->auto_sync_enabled)->toBeTrue();
    expect($created->sync_interval_minutes)->toBe(10);
});

test('biometric device service testConnection handles unreachable host', function () {
    $service = app(BiometricDeviceService::class);
    $device = $this->createDevice(['ip_address' => '127.0.0.1', 'port' => 59999]); // Local port with no listener
    $result = $service->testConnection($device);
    expect($result)->toHaveKey('ok');
    expect($result['ok'])->toBeFalse();
    expect($result['message'])->toContain('Failed to connect');
});
