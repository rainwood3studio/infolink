<?php

namespace Database\Factories;

use App\Enums\Category;
use App\Enums\InsightSeverity;
use App\Models\AlertRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AlertRule>
 */
class AlertRuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => 'test-'.fake()->unique()->slug(2),
            'name' => fake()->words(3, true),
            'metric_key' => 'cash.runway_months',
            'operator' => '<',
            'threshold' => 3,
            'severity' => InsightSeverity::Warning,
            'category' => Category::Finance,
            'title_template' => '現金可撐 {value} 個月',
            'fingerprint_template' => 'cash-runway',
            'is_active' => true,
        ];
    }
}
