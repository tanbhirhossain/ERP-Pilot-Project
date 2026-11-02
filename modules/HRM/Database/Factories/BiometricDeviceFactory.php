<?php
namespace Modules\HRM\Database\Factories;
use Modules\HRM\Models\BiometricDevice;
use Illuminate\Database\Eloquent\Factories\Factory;
class BiometricDeviceFactory extends Factory {
    protected $model = BiometricDevice::class;
    public function definition(): array {
        return [
            'name' => 'ZK-'.$this->faker->lexify('???').' Gate',
            'device_name' => 'ZK-T'.$this->faker->numberBetween(10,100),
            'ip_address' => $this->faker->ipv4(),
            'port' => 4370,
            'serial_number' => $this->faker->unique()->numerify('SN##########'),
            'device_model' => $this->faker->randomElement(['ZK-T50','ZK-B100','ZK-F22']),
            'location' => $this->faker->randomElement(['Main Gate','Building A','Factory Floor','HQ Lobby']),
            'status' => 'Active',
            'is_enabled' => true,
            'auto_sync_enabled' => false,
            'sync_interval_minutes' => 15,
        ];
    }
    public function disabled(): static { return $this->state(fn(array $a)=>['is_enabled'=>false,'status'=>'Inactive']); }
    public function withAutoSync(): static { return $this->state(fn(array $a)=>['auto_sync_enabled'=>true,'is_enabled'=>true]); }
}
