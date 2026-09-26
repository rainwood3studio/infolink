<?php

namespace App\Models;

use Database\Factories\RedmineIssueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Read-only mirror of a Redmine issue; the id is Redmine's own. Soft-deleted when it disappears from Redmine.
 */
#[Fillable(['id', 'project_id', 'project_identifier', 'project_name', 'tracker_id', 'tracker', 'status_id', 'status', 'is_closed', 'priority_id', 'priority', 'assignee_id', 'assignee_name', 'author_id', 'author_name', 'subject', 'start_date', 'due_date', 'done_ratio', 'estimated_hours', 'created_on', 'updated_on', 'closed_on', 'raw', 'synced_at'])]
class RedmineIssue extends Model
{
    /** @use HasFactory<RedmineIssueFactory> */
    use HasFactory, SoftDeletes;

    public const string STATUS_VERIFYING = '驗證中';

    public $incrementing = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_closed' => 'boolean',
            'start_date' => 'date',
            'due_date' => 'date',
            'estimated_hours' => 'decimal:2',
            'created_on' => 'datetime',
            'updated_on' => 'datetime',
            'closed_on' => 'datetime',
            'raw' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<RedmineTimeEntry, $this>
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(RedmineTimeEntry::class, 'issue_id');
    }

    /**
     * @return HasMany<RedmineStatusChange, $this>
     */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(RedmineStatusChange::class, 'issue_id');
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('is_closed', false);
    }

    /**
     * Whether an assignee display name belongs to the final acceptor (config services.redmine.acceptor_name).
     */
    public static function isAcceptor(?string $assigneeName): bool
    {
        $acceptor = (string) config('services.redmine.acceptor_name');

        return $assigneeName !== null && $acceptor !== '' && str_starts_with($assigneeName, $acceptor);
    }

    public function url(): string
    {
        return rtrim((string) config('services.redmine.url'), '/').'/issues/'.$this->id;
    }
}
