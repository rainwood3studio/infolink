<?php

namespace Database\Factories;

use App\Enums\DealStage;
use App\Models\Deal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deal>
 */
class DealFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'prospect_name' => fake()->company(),
            'title' => fake()->words(3, true),
            'stage' => DealStage::Proposal,
            'amount_untaxed' => fake()->numberBetween(10, 300) * 10_000,
            'probability' => 50,
            'expected_close_on' => today()->addMonths(2),
            'next_action' => '追蹤提案回覆',
            'next_action_on' => today()->addWeek(),
        ];
    }
}
