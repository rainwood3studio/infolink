<?php

namespace Database\Factories;

use App\Models\PlannedCashFlow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlannedCashFlow>
 */
class PlannedCashFlowFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'flow_on' => fake()->dateTimeBetween('now', '+3 months'),
            'amount' => -fake()->numberBetween(10, 100) * 1_000,
            'description' => fake()->randomElement(['營業稅', '年終獎金', '設備採購']),
        ];
    }
}
