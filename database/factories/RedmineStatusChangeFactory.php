<?php

namespace Database\Factories;

use App\Models\RedmineStatusChange;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RedmineStatusChange>
 */
class RedmineStatusChangeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'issue_id' => fake()->numberBetween(1, 999_999),
            'project_identifier' => 'tcsb-5f-b2c',
            'from_status' => '實作中',
            'to_status' => '驗證中',
            'assignee_name' => '文豪',
            'changed_at' => now(),
        ];
    }
}
