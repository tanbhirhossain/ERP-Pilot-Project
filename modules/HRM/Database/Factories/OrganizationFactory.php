<?php
namespace Modules\HRM\Database\Factories;
use Modules\HRM\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
class OrganizationFactory extends Factory {
    protected $model = Organization::class;
    public function definition(): array {
        return [
            'name' => $this->faker->company(),
            'email' => $this->faker->companyEmail(),
            'phone' => $this->faker->phoneNumber(),
            'address' => $this->faker->address(),
            'website' => $this->faker->url(),
        ];
    }
}
