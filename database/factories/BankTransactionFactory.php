<?php

namespace Database\Factories;

use App\Enums\TransactionCategory;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankTransaction>
 */
class BankTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bank_account_id' => BankAccount::factory(),
            'txn_date' => fake()->dateTimeBetween('-1 month'),
            'summary' => fake()->sentence(3),
            'withdrawal' => 0,
            'deposit' => fake()->numberBetween(1_000, 100_000),
            'balance' => fake()->numberBetween(100_000, 2_000_000),
            'category' => TransactionCategory::Other,
            'is_one_off' => false,
        ];
    }
}
