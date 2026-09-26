<?php

namespace Database\Factories;

use App\Models\RedmineStatusSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RedmineStatusSnapshot>
 */
class RedmineStatusSnapshotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'snapshot_date' => today(),
            'project_identifier' => 'tcsb-5f-b2c',
            'status' => '新建立',
            'assignee_name' => '',
            'count' => fake()->numberBetween(1, 50),
        ];
    }
}
