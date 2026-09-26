<?php

namespace Database\Factories;

use App\Enums\ReportType;
use App\Models\Report;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => ReportType::DailyBrief,
            'period_start' => today(),
            'title' => fake()->sentence(4),
            'body' => '## 摘要

'.fake()->paragraph(),
        ];
    }
}
