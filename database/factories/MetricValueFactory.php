<?php

namespace Database\Factories;

use App\Models\MetricDefinition;
use App\Models\MetricValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MetricValue>
 */
class MetricValueFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'metric_key' => MetricDefinition::factory(),
            'period_start' => today(),
            'dimension' => '',
            'value' => fake()->numberBetween(0, 1_000_000),
        ];
    }
}
