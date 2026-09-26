<?php

namespace Database\Factories;

use App\Enums\Category;
use App\Enums\MetricDirection;
use App\Enums\MetricUnit;
use App\Enums\PeriodType;
use App\Models\MetricDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MetricDefinition>
 */
class MetricDefinitionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => 'test.'.fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'category' => Category::Finance,
            'unit' => MetricUnit::Twd,
            'period_type' => PeriodType::Snapshot,
            'better' => MetricDirection::Up,
            'description' => fake()->sentence(),
        ];
    }
}
