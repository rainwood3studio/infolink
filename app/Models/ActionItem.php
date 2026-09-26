<?php

namespace App\Models;

use App\Enums\ActionItemPriority;
use App\Enums\ActionItemStatus;
use App\Models\Concerns\HasSource;
use Database\Factories\ActionItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Something that needs doing (as opposed to an Insight, which is something to know).
 */
#[Fillable(['title', 'detail', 'priority', 'status', 'due_on', 'owner', 'related_type', 'related_id', 'completed_at'])]
class ActionItem extends Model
{
    /** @use HasFactory<ActionItemFactory> */
    use HasFactory, HasSource;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => ActionItemPriority::class,
            'status' => ActionItemStatus::class,
            'due_on' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereIn('status', [ActionItemStatus::Todo, ActionItemStatus::Doing, ActionItemStatus::Waiting]);
    }
}
