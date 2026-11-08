<?php

namespace Modules\PARTNER\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\PARTNER\Models\Partner;
use Modules\PARTNER\Models\PartnerGroup;

class PartnerFactory extends Factory
{
    protected $model = Partner::class;

    public function definition(): array
    {
        $type = $this->faker->randomElement(['vendor', 'customer', 'both']);
        $prefix = match ($type) {
            'vendor' => 'VEN-', 'customer' => 'CUS-', default => 'PAR-'
        };
        $group = PartnerGroup::inRandomOrder()->first();

        return [
            'partner_code' => $prefix.str_pad((string) $this->faker->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'type' => $type,
            'name' => $type === 'vendor' ? $this->faker->company().' Ltd.' : $this->faker->name().' Enterprise',
            'legal_name' => $this->faker->company(),
            'group_id' => $group?->id,
            'phone' => '01'.$this->faker->numerify('#########'),
            'email' => $this->faker->companyEmail(),
            'website' => $this->faker->url(),
            'trade_license' => 'TRAD-'.$this->faker->numerify('########'),
            'tin' => $this->faker->numerify('############'),
            'bin' => $this->faker->numerify('###########'),
            'credit_limit' => $this->faker->randomElement([0, 50000, 100000, 200000, 500000]),
            'payment_terms_days' => $this->faker->randomElement([0, 7, 15, 30, 45]),
            'payment_terms' => $this->faker->randomElement(['Net 7', 'Net 15', 'Net 30', 'Advance', 'COD']),
            'opening_balance' => $this->faker->randomFloat(2, 0, 10000),
            'current_balance' => 0,
            'currency' => 'BDT',
            'is_active' => true,
            'is_verified' => $this->faker->boolean(60),
            'verification_status' => $this->faker->randomElement(['pending', 'approved', 'approved', 'rejected']),
            'notes' => $this->faker->sentence(),
        ];
    }

    public function vendor(): static
    {
        return $this->state(fn (array $attrs) => [
            'type' => 'vendor',
            'partner_code' => 'VEN-'.str_pad((string) $this->faker->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
        ]);
    }

    public function customer(): static
    {
        return $this->state(fn (array $attrs) => [
            'type' => 'customer',
            'partner_code' => 'CUS-'.str_pad((string) $this->faker->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
        ]);
    }

    public function verified(): static
    {
        return $this->state(fn () => ['is_verified' => true, 'verification_status' => 'approved', 'verified_at' => now()]);
    }
}
