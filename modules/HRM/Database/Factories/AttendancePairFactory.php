<?php
namespace Modules\HRM\Database\Factories;
use Modules\HRM\Models\Attendance\AttendancePair;
use Illuminate\Database\Eloquent\Factories\Factory;
class AttendancePairFactory extends Factory {
    protected $model = AttendancePair::class;
    public function definition(): array {
        $in = $this->faker->dateTimeBetween('-7 days','now');
        $out = (clone $in)->modify('+8 hours');
        return [
            'duty_date' => $in->format('Y-m-d'),
            'punch_in' => $in->format('Y-m-d H:i:s'),
            'punch_out' => $out->format('Y-m-d H:i:s'),
            'duty_type' => 'Primary',
            'shift_type_applied' => 'Fixed',
            'worked_hours' => 8.0,
        ];
    }
    public function open(): static { return $this->state(fn()=>['punch_out'=>null,'worked_hours'=>0]); }
    public function overtime(): static { return $this->state(fn()=>['duty_type'=>'Overtime']); }
}
