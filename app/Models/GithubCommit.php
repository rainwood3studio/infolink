<?php

namespace App\Models;

use App\Enums\CommitType;
use Database\Factories\GithubCommitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A commit reachable from any branch of a mirrored repo (deduplicated per repo by sha), authored since
 * the retention window (services.github.retention_months). `effective_*` are the line counts without lock files, generated and vendored files;
 * null means the commit was small enough that the raw counts are used as-is.
 */
#[Fillable([
    'github_repo_id', 'sha', 'github_identity_id', 'branch', 'authored_at', 'committed_at', 'subject', 'message',
    'type', 'scope', 'redmine_issue_ids', 'is_merge', 'is_ai_assisted', 'additions', 'deletions', 'changed_files',
    'effective_additions', 'effective_deletions',
])]
class GithubCommit extends Model
{
    /** @use HasFactory<GithubCommitFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'authored_at' => 'datetime',
            'committed_at' => 'datetime',
            'type' => CommitType::class,
            'redmine_issue_ids' => 'array',
            'is_merge' => 'boolean',
            'is_ai_assisted' => 'boolean',
            'additions' => 'integer',
            'deletions' => 'integer',
            'changed_files' => 'integer',
            'effective_additions' => 'integer',
            'effective_deletions' => 'integer',
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
     * Commits that represent authored work (merge commits only record integration).
     *
     * @param  Builder<static>  $query
     */
    public function scopeAuthored(Builder $query): void
    {
        $query->where('is_merge', false);
    }

    public function linesAdded(): int
    {
        return $this->effective_additions ?? $this->additions;
    }

    public function linesDeleted(): int
    {
        return $this->effective_deletions ?? $this->deletions;
    }

    public function url(): string
    {
        return 'https://github.com/'.$this->repo->full_name.'/commit/'.$this->sha;
    }
}
