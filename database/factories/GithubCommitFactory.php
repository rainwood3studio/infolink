<?php

namespace Database\Factories;

use App\Enums\CommitType;
use App\Models\GithubCommit;
use App\Models\GithubIdentity;
use App\Models\GithubRepo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GithubCommit>
 */
class GithubCommitFactory extends Factory
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
            'sha' => fake()->unique()->sha1(),
            'github_identity_id' => GithubIdentity::factory(),
            'branch' => 'main',
            'authored_at' => now()->subHour(),
            'committed_at' => now()->subHour(),
            'subject' => $subject = 'feat(pos): '.fake()->sentence(4),
            'message' => $subject,
            'type' => CommitType::Feat,
            'scope' => 'pos',
            'redmine_issue_ids' => [],
            'is_merge' => false,
            'is_ai_assisted' => false,
            'additions' => fake()->numberBetween(1, 200),
            'deletions' => fake()->numberBetween(0, 80),
            'changed_files' => fake()->numberBetween(1, 8),
            'effective_additions' => null,
            'effective_deletions' => null,
        ];
    }

    public function merge(): static
    {
        return $this->state(fn (): array => [
            'subject' => 'Merge branch \'develop\'',
            'message' => 'Merge branch \'develop\'',
            'type' => CommitType::Other,
            'scope' => null,
            'is_merge' => true,
        ]);
    }
}
