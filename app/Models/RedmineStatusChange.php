<?php

namespace App\Models;

use Database\Factories\RedmineStatusChangeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A status transition observed by the sync (stored status differs from Redmine's). Basis for
 * "推進到驗證中" — the real throughput metric, since closing is always done by the acceptor.
 */
#[Fillable(['issue_id', 'project_identifier', 'from_status', 'to_status', 'assignee_name', 'previous_assignee_name', 'changed_at'])]
class RedmineStatusChange extends Model
{
    /** @use HasFactory<RedmineStatusChangeFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'changed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<RedmineIssue, $this>
     */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(RedmineIssue::class, 'issue_id')->withTrashed();
    }
}
