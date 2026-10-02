<?php

namespace App\Models;

use Database\Factories\GithubReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A submitted pull-request review (APPROVED, CHANGES_REQUESTED, COMMENTED or DISMISSED); id is GitHub's databaseId.
 */
#[Fillable(['id', 'github_pull_request_id', 'github_identity_id', 'state', 'submitted_at'])]
class GithubReview extends Model
{
    /** @use HasFactory<GithubReviewFactory> */
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
            'submitted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<GithubPullRequest, $this>
     */
    public function pullRequest(): BelongsTo
    {
        return $this->belongsTo(GithubPullRequest::class, 'github_pull_request_id');
    }

    /**
     * @return BelongsTo<GithubIdentity, $this>
     */
    public function identity(): BelongsTo
    {
        return $this->belongsTo(GithubIdentity::class, 'github_identity_id');
    }
}
