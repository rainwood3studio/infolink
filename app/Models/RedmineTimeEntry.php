<?php

namespace App\Models;

use Database\Factories\RedmineTimeEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read-only mirror of a Redmine time entry. Only a few people log time, so treat hours as directional only.
 */
#[Fillable(['id', 'issue_id', 'project_identifier', 'user_id', 'user_name', 'activity', 'hours', 'spent_on', 'comments', 'updated_on', 'raw', 'synced_at'])]
class RedmineTimeEntry extends Model
{
    /** @use HasFactory<RedmineTimeEntryFactory> */
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
            'hours' => 'decimal:2',
            'spent_on' => 'date',
            'updated_on' => 'datetime',
            'raw' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<RedmineIssue, $this>
     */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(RedmineIssue::class, 'issue_id');
    }
}
