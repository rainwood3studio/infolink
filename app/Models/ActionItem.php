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
     * The name of the person using the app (config infolink.owner_name); a blank `owner` means them too.
     */
    public static function ownerName(): string
    {
        return trim((string) config('infolink.owner_name', 'Kenneth'));
    }

    /**
     * Whether the item is the app owner's own: no owner, or the owner name (case-insensitive).
     */
    public function isMine(): bool
    {
        $owner = trim((string) $this->owner);

        return $owner === '' || mb_strtolower($owner) === mb_strtolower(self::ownerName());
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereIn('status', [ActionItemStatus::Todo, ActionItemStatus::Doing, ActionItemStatus::Waiting]);
    }

    /**
     * Items the app owner has to do themselves (see {@see isMine()}).
     *
     * @param  Builder<static>  $query
     */
    public function scopeMine(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query
            ->whereNull('owner')
            ->orWhereRaw("trim(owner) = ''")
            ->orWhereRaw('lower(trim(owner)) = ?', [mb_strtolower(self::ownerName())]));
    }

    /**
     * Items handed to someone else.
     *
     * @param  Builder<static>  $query
     */
    public function scopeDelegated(Builder $query): void
    {
        $query->whereNotNull('owner')
            ->whereRaw("trim(owner) <> ''")
            ->whereRaw('lower(trim(owner)) <> ?', [mb_strtolower(self::ownerName())]);
    }
}
