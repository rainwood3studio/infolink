<?php

namespace Database\Factories;

use App\Models\RedmineTimeEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RedmineTimeEntry>
 */
class RedmineTimeEntryFactory extends Factory
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
            'project_identifier' => 'tcsb-5f-b2c',
            'user_id' => 6,
            'user_name' => '永彬',
            'activity' => '開發',
            'hours' => 1.5,
            'spent_on' => today(),
            'updated_on' => now(),
            'raw' => [],
            'synced_at' => now(),
        ];
    }
}
