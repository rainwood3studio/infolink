<?php

namespace App\Models;

use Database\Factories\GithubRepoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Read-only mirror of a GitHub repository; the id is GitHub's databaseId. The work in it is attributed by hand:
 * `project_id` ties the repo to a company Project, `deal_id` to a Deal (work for a prospect that has no contract yet;
 * the project wins when both are set), and `branch_projects` overrides the project for single branches — a list of
 * `{"branch": "dycare-dev", "project_id": 6}` — for repos that serve one project on the default branch and another
 * on a long-lived branch. See {@see self::projectIdForBranch()}.
 *
 * @property array<int, array{branch: string, project_id: int}>|null $branch_projects
 */
#[Fillable(['id', 'owner', 'name', 'full_name', 'default_branch', 'is_private', 'is_archived', 'pushed_at', 'project_id', 'deal_id', 'branch_projects', 'last_synced_at'])]
class GithubRepo extends Model
{
    /** @use HasFactory<GithubRepoFactory> */
    use HasFactory;

    public $incrementing = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_private' => 'boolean',
            'is_archived' => 'boolean',
            'pushed_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'branch_projects' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Deal, $this>
     */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /**
     * @return HasMany<GithubBranch, $this>
     */
    public function branches(): HasMany
    {
        return $this->hasMany(GithubBranch::class);
    }

    /**
     * @return HasMany<GithubCommit, $this>
     */
    public function commits(): HasMany
    {
        return $this->hasMany(GithubCommit::class);
    }

    /**
     * @return HasMany<GithubPullRequest, $this>
     */
    public function pullRequests(): HasMany
    {
        return $this->hasMany(GithubPullRequest::class);
    }

    /**
     * The project a commit on `$branch` belongs to: the override for exactly that branch name when there is one,
     * otherwise the repo's own project (null = not tied to a project).
     */
    public function projectIdForBranch(?string $branch): ?int
    {
        if ($branch !== null) {
            foreach ($this->branch_projects ?? [] as $override) {
                if (($override['branch'] ?? null) === $branch && filled($override['project_id'] ?? null)) {
                    return (int) $override['project_id'];
                }
            }
        }

        return $this->project_id;
    }

    /**
     * Clean a list of branch overrides for storage: rows without a branch or a project are dropped, a branch listed
     * twice keeps its last project, and an empty list becomes null.
     *
     * @param  array<int, array<string, mixed>>|null  $overrides
     * @return list<array{branch: string, project_id: int}>|null
     */
    public static function normalizeBranchProjects(?array $overrides): ?array
    {
        $byBranch = [];

        foreach ($overrides ?? [] as $override) {
            $branch = trim((string) ($override['branch'] ?? ''));

            if ($branch !== '' && filled($override['project_id'] ?? null)) {
                $byBranch[$branch] = ['branch' => $branch, 'project_id' => (int) $override['project_id']];
            }
        }

        return $byBranch === [] ? null : array_values($byBranch);
    }

    public function url(): string
    {
        return 'https://github.com/'.$this->full_name;
    }
}
