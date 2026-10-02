<?php

namespace Database\Factories;

use App\Models\GithubIdentity;
use App\Models\GithubPullRequest;
use App\Models\GithubReview;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GithubReview>
 */
class GithubReviewFactory extends Factory
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
            'github_pull_request_id' => GithubPullRequest::factory(),
            'github_identity_id' => GithubIdentity::factory(),
            'state' => 'APPROVED',
            'submitted_at' => now()->subHour(),
        ];
    }
}
