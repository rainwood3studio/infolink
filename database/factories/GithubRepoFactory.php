<?php

namespace Database\Factories;

use App\Models\GithubRepo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GithubRepo>
 */
class GithubRepoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => fake()->unique()->numberBetween(100_000_000, 999_999_999),
            'owner' => 'infolinktw',
            'name' => $name = fake()->unique()->slug(2),
            'full_name' => 'infolinktw/'.$name,
            'default_branch' => 'main',
            'is_private' => true,
            'is_archived' => false,
            'pushed_at' => now(),
            'project_id' => null,
            'last_synced_at' => now(),
        ];
    }
}
