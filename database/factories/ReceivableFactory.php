<?php

namespace Database\Factories;

use App\Enums\Confidence;
use App\Enums\ReceivableStatus;
use App\Models\Customer;
use App\Models\Receivable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Receivable>
 */
class ReceivableFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'item' => fake()->randomElement(['期中款', '尾款', '維運費']),
            'amount_untaxed' => fake()->numberBetween(10, 500) * 1_000,
            'tax_rate' => 0.05,
            'expected_on' => fake()->dateTimeBetween('now', '+3 months'),
            'confidence' => Confidence::High,
            'status' => ReceivableStatus::Planned,
            'is_recurring' => false,
        ];
    }
}
