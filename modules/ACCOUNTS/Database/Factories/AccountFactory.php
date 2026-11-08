<?php

namespace Modules\ACCOUNTS\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ACCOUNTS\Models\Account;

class AccountFactory extends Factory
{
    protected $model = Account::class;

    public function definition(): array
    {
        $types = ['asset', 'liability', 'equity', 'income', 'expense'];
        $type = $this->faker->randomElement($types);
        $map = ['asset' => 'debit', 'expense' => 'debit', 'liability' => 'credit', 'equity' => 'credit', 'income' => 'credit'];

        return [
            'code' => $this->faker->unique()->numerify('9###'),
            'name' => $this->faker->words(2, true),
            'account_type' => $type,
            'nature' => $map[$type],
            'is_group' => false,
            'is_active' => true,
            'is_system' => false,
            'currency' => 'BDT',
            'level' => 1,
        ];
    }
}
