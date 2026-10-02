<?php

namespace App\Models;

use Database\Factories\GithubBranchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Last seen head of a branch; a sync only walks branches whose head moved since the previous run.
 */
#[Fillable(['github_repo_id', 'name', 'head_sha', 'head_committed_at', 'synced_at'])]
class GithubBranch extends Model
{
    /** @use HasFactory<GithubBranchFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'head_committed_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<GithubRepo, $this>
     */
    public function repo(): BelongsTo
    {
        return $this->belongsTo(GithubRepo::class, 'github_repo_id');
    }
}
