<?php

namespace Database\Factories;

use App\Models\GithubRepo;
use App\Models\Project;
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
            'deal_id' => null,
            'branch_projects' => null,
            'last_synced_at' => now(),
        ];
    }

    /**
     * Commits first seen on `$branch` belong to `$project` instead of the repo's own project.
     */
    public function branchProject(string $branch, Project $project): static
    {
        return $this->state(fn (array $attributes): array => [
            'branch_projects' => [...($attributes['branch_projects'] ?? []), ['branch' => $branch, 'project_id' => $project->id]],
        ]);
    }
}
