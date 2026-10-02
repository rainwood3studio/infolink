<?php

namespace Database\Factories;

use App\Models\GithubBranch;
use App\Models\GithubRepo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GithubBranch>
 */
class GithubBranchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'github_repo_id' => GithubRepo::factory(),
            'name' => fake()->unique()->slug(2),
            'head_sha' => fake()->sha1(),
            'head_committed_at' => now(),
            'synced_at' => now(),
        ];
    }
}
