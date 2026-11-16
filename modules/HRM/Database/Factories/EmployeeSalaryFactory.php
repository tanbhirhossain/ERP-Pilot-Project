<?php

namespace Modules\HRM\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HRM\Models\Employee\EmployeeSalary;

class EmployeeSalaryFactory extends Factory
{
    protected $model = EmployeeSalary::class;

    public function definition(): array
    {
        $basic = $this->faker->numberBetween(20000, 80000);

        return [
            'basic_salary' => $basic,
            'gross_salary' => $basic * 1.4,
            'house_rent' => $basic * 0.3,
            'medical_allowance' => 1500,
            'net_salary' => $basic * 1.4,
            'is_current' => true,
            'effective_from' => now()->toDateString(),
            // A salary record without these cannot be paid by bank — the advice refuses to be
            // generated for it. Seeded employees should be payable, so the factory supplies them.
            'payment_method' => 'bank',
            'bank_name' => 'Sonali Bank Ltd',
            'bank_account_no' => $this->faker->numerify('###############'),
        ];
    }
}
