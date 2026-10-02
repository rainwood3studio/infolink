<?php

namespace Database\Factories;

use App\Models\GithubIdentity;
use App\Models\GithubPullRequest;
use App\Models\GithubRepo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GithubPullRequest>
 */
class GithubPullRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => fake()->unique()->numberBetween(1_000_000_000, 9_999_999_999),
            'github_repo_id' => GithubRepo::factory(),
            'number' => fake()->unique()->numberBetween(1, 5000),
            'github_identity_id' => GithubIdentity::factory(),
            'merged_by_identity_id' => null,
            'title' => fake()->sentence(5),
            'state' => GithubPullRequest::STATE_OPEN,
            'is_draft' => false,
            'head_ref' => 'feature/'.fake()->slug(2),
            'base_ref' => 'main',
            'opened_at' => now()->subDay(),
            'merged_at' => null,
            'closed_at' => null,
            'additions' => fake()->numberBetween(1, 400),
            'deletions' => fake()->numberBetween(0, 100),
            'redmine_issue_ids' => [],
        ];
    }

    public function merged(): static
    {
        return $this->state(fn (): array => [
            'state' => GithubPullRequest::STATE_MERGED,
            'merged_at' => now()->subHour(),
            'closed_at' => now()->subHour(),
        ]);
    }
}
