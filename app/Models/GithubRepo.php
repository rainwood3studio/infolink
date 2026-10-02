<?php

namespace App\Models;

use Database\Factories\GithubRepoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Read-only mirror of a GitHub repository; the id is GitHub's databaseId. Optionally tied to a company Project.
 */
#[Fillable(['id', 'owner', 'name', 'full_name', 'default_branch', 'is_private', 'is_archived', 'pushed_at', 'project_id', 'last_synced_at'])]
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

    public function url(): string
    {
        return 'https://github.com/'.$this->full_name;
    }
}
