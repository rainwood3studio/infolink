<?php

namespace Database\Factories;

use App\Enums\Category;
use App\Enums\InsightKind;
use App\Enums\InsightSeverity;
use App\Enums\InsightStatus;
use App\Models\Insight;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Insight>
 */
class InsightFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => InsightKind::Risk,
            'severity' => InsightSeverity::Warning,
            'category' => Category::Finance,
            'title' => fake()->sentence(),
            'fingerprint' => 'test:'.fake()->unique()->uuid(),
            'status' => InsightStatus::Open,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
