<?php

namespace Modules\HRM\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\HRM\Models\BiometricDevice;

class BiometricDeviceSeeder extends Seeder
{
    public function run(): void
    {
        $devices = [
            [
                'name' => 'F18 - Main Gate',
                'device_name' => 'ZKTeco F18',
                'ip_address' => '192.168.10.91',
                'port' => 4370,
                'device_model' => 'F18',
                'location' => 'Main Gate',
                'connection_type' => 'TCP',
                'status' => 'Active',
                'is_enabled' => true,
                'auto_sync_enabled' => true,
                'sync_interval_minutes' => 15,
            ],
            [
                'name' => 'F18 - Admin Block',
                'device_name' => 'ZKTeco F18',
                'ip_address' => '192.168.10.93',
                'port' => 4370,
                'device_model' => 'F18',
                'location' => 'Admin Block',
                'connection_type' => 'TCP',
                'status' => 'Active',
                'is_enabled' => true,
                'auto_sync_enabled' => true,
                'sync_interval_minutes' => 15,
            ],
            [
                'name' => 'F18 - Emergency',
                'device_name' => 'ZKTeco F18',
                'ip_address' => '192.168.10.94',
                'port' => 4370,
                'device_model' => 'F18',
                'location' => 'Emergency Dept',
                'connection_type' => 'TCP',
                'status' => 'Active',
                'is_enabled' => true,
                'auto_sync_enabled' => true,
                'sync_interval_minutes' => 15,
            ],
        ];

        foreach ($devices as $data) {
            BiometricDevice::updateOrCreate(
                ['ip_address' => $data['ip_address']],
                $data
            );
        }

        $this->command?->info('Seeded 3 F18 devices: 192.168.10.91, 192.168.10.93, 192.168.10.94');
    }
}
