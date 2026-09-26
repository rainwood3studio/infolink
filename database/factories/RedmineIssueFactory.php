<?php

namespace Database\Factories;

use App\Models\RedmineIssue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RedmineIssue>
 */
class RedmineIssueFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => fake()->unique()->numberBetween(1, 999_999),
            'project_id' => 15,
            'project_identifier' => 'tcsb-5f-b2c',
            'project_name' => '墊腳石 | 5F | B2C',
            'tracker_id' => 4,
            'tracker' => 'Issue',
            'status_id' => 1,
            'status' => '新建立',
            'is_closed' => false,
            'priority_id' => 2,
            'priority' => '正常',
            'assignee_id' => 10,
            'assignee_name' => '文豪',
            'author_id' => 6,
            'author_name' => '永彬',
            'subject' => fake()->sentence(),
            'done_ratio' => 0,
            'created_on' => now()->subDays(10),
            'updated_on' => now()->subDay(),
            'raw' => [],
            'synced_at' => now(),
        ];
    }
}
