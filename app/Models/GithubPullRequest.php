<?php

namespace App\Models;

use Database\Factories\GithubPullRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Read-only mirror of a pull request; the id is GitHub's databaseId. `state` is OPEN, CLOSED or MERGED.
 */
#[Fillable([
    'id', 'github_repo_id', 'number', 'github_identity_id', 'merged_by_identity_id', 'title', 'state', 'is_draft',
    'head_ref', 'base_ref', 'opened_at', 'merged_at', 'closed_at', 'additions', 'deletions', 'redmine_issue_ids',
])]
class GithubPullRequest extends Model
{
    /** @use HasFactory<GithubPullRequestFactory> */
    use HasFactory;

    public const string STATE_OPEN = 'OPEN';

    public const string STATE_CLOSED = 'CLOSED';

    public const string STATE_MERGED = 'MERGED';

    public $incrementing = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_draft' => 'boolean',
            'opened_at' => 'datetime',
            'merged_at' => 'datetime',
            'closed_at' => 'datetime',
            'additions' => 'integer',
            'deletions' => 'integer',
            'redmine_issue_ids' => 'array',
        ];
    }

    /**
     * @return BelongsTo<GithubRepo, $this>
     */
    public function repo(): BelongsTo
    {
        return $this->belongsTo(GithubRepo::class, 'github_repo_id');
    }

    /**
     * @return BelongsTo<GithubIdentity, $this>
     */
    public function identity(): BelongsTo
    {
        return $this->belongsTo(GithubIdentity::class, 'github_identity_id');
    }

    /**
     * @return BelongsTo<GithubIdentity, $this>
     */
    public function mergedBy(): BelongsTo
    {
        return $this->belongsTo(GithubIdentity::class, 'merged_by_identity_id');
    }

    /**
     * @return HasMany<GithubReview, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(GithubReview::class);
    }

    public function url(): string
    {
        return 'https://github.com/'.$this->repo->full_name.'/pull/'.$this->number;
    }
}
