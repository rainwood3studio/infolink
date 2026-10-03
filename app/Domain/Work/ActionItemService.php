<?php

namespace App\Domain\Work;

use App\Enums\ActionItemStatus;
use App\Enums\InsightSeverity;
use App\Enums\Source;
use App\Models\ActionItem;
use App\Models\Developer;
use App\Models\Insight;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * The single write path for action items, plus the "今天要處理" attention list and the 「已交辦」 list.
 *
 * Ownership: an item with no owner, or owned by config `infolink.owner_name`, is "mine"; any other owner means it
 * was delegated to a colleague (see {@see ActionItem::isMine()}).
 */
class ActionItemService
{
    /**
     * Create an action item. With an `external_key` the call is idempotent: the same source + key updates the row.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, Source $source = Source::Manual): ActionItem
    {
        if (blank($attributes['title'] ?? null)) {
            throw new InvalidArgumentException('An action item needs a title.');
        }

        $attributes = $this->withCompletionTimestamp($attributes, null);

        if (filled($attributes['external_key'] ?? null)) {
            return ActionItem::upsertFromSource($source, $attributes['external_key'], $attributes);
        }

        return ActionItem::query()->create([...$attributes, 'source' => $source]);
    }

    /**
     * Update an action item; moving into / out of `done` sets / clears `completed_at`.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(ActionItem $actionItem, array $attributes): ActionItem
    {
        $actionItem->fill($this->withCompletionTimestamp($attributes, $actionItem))->save();

        return $actionItem;
    }

    public function complete(ActionItem $actionItem): ActionItem
    {
        return $this->update($actionItem, ['status' => ActionItemStatus::Done]);
    }

    public function reopen(ActionItem $actionItem): ActionItem
    {
        return $this->update($actionItem, ['status' => ActionItemStatus::Todo]);
    }

    /**
     * Move the due date (the 「延後」 triage choice).
     */
    public function postpone(ActionItem $actionItem, CarbonInterface $dueOn): ActionItem
    {
        return $this->update($actionItem, ['due_on' => $dueOn->toDateString()]);
    }

    /**
     * Hand the item to a colleague (the 「交辦」 triage choice).
     */
    public function delegate(ActionItem $actionItem, string $owner): ActionItem
    {
        if (blank($owner)) {
            throw new InvalidArgumentException('Delegating an action item needs an owner.');
        }

        return $this->update($actionItem, ['owner' => trim($owner)]);
    }

    /**
     * Make a delegated item the app owner's again (「收回」).
     */
    public function takeBack(ActionItem $actionItem): ActionItem
    {
        return $this->update($actionItem, ['owner' => ActionItem::ownerName()]);
    }

    /**
     * Give up on the item (「放棄」): status `dropped`, never deleted.
     */
    public function drop(ActionItem $actionItem): ActionItem
    {
        return $this->update($actionItem, ['status' => ActionItemStatus::Dropped]);
    }

    /**
     * Who an action item can belong to: the app owner first, then the active developers by name.
     *
     * @return list<string>
     */
    public function owners(): array
    {
        return collect([ActionItem::ownerName()])
            ->concat(Developer::query()->where('is_active', true)->orderBy('name')->pluck('name'))
            ->map(fn (string $name): string => trim($name))
            ->filter()
            ->unique(fn (string $name): string => mb_strtolower($name))
            ->values()
            ->all();
    }

    /**
     * The colleagues an item can be delegated to: {@see owners()} without the app owner.
     *
     * @return list<string>
     */
    public function delegates(): array
    {
        return array_slice($this->owners(), 1);
    }

    /**
     * The "今天要處理" list for every owner, in display order:
     * unresolved critical insights → unresolved warning insights (each newest `last_seen_at` first)
     * → pending action items due on or before today (oldest due date first, then priority).
     *
     * Delegated items are included (the MCP briefing reports them with their owner); the dashboard shows
     * {@see myAttentionList()} and {@see delegatedList()} instead.
     *
     * @return Collection<int, AttentionItem>
     */
    public function attentionList(?CarbonInterface $today = null): Collection
    {
        $today ??= today();

        return $this->attentionInsights()->concat($this->dueActionItems($today, mineOnly: false))->values();
    }

    /**
     * {@see attentionList()} narrowed to the app owner's own action items (insights are unchanged).
     *
     * @return Collection<int, AttentionItem>
     */
    public function myAttentionList(?CarbonInterface $today = null): Collection
    {
        $today ??= today();

        return $this->attentionInsights()->concat($this->dueActionItems($today, mineOnly: true))->values();
    }

    /**
     * The app owner's pending action items due on or before today: most overdue first, then priority.
     *
     * @return Collection<int, AttentionItem>
     */
    public function myDueItems(?CarbonInterface $today = null): Collection
    {
        return $this->dueActionItems($today ?? today(), mineOnly: true);
    }

    /**
     * The 「已交辦」 list: every pending action item owned by someone else, whatever its due date. Dated items come
     * first, oldest due date first (so overdue ones lead), then priority; undated items last.
     *
     * @return Collection<int, AttentionItem>
     */
    public function delegatedList(?CarbonInterface $today = null): Collection
    {
        $today ??= today();

        return ActionItem::query()
            ->pending()
            ->delegated()
            ->orderByRaw('case when due_on is null then 1 else 0 end')
            ->orderBy('due_on')
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->map(fn (ActionItem $actionItem): AttentionItem => AttentionItem::fromActionItem($actionItem, $today));
    }

    /**
     * @return Collection<int, AttentionItem>
     */
    protected function attentionInsights(): Collection
    {
        return Insight::query()
            ->unresolved()
            ->whereIn('severity', [InsightSeverity::Critical, InsightSeverity::Warning])
            ->orderByRaw('case when severity = ? then 0 else 1 end', [InsightSeverity::Critical->value])
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Insight $insight): AttentionItem => AttentionItem::fromInsight($insight));
    }

    /**
     * @return Collection<int, AttentionItem>
     */
    protected function dueActionItems(CarbonInterface $today, bool $mineOnly): Collection
    {
        return ActionItem::query()
            ->pending()
            ->when($mineOnly, fn (Builder $query) => $query->mine())
            ->whereNotNull('due_on')
            ->whereDate('due_on', '<=', $today->toDateString())
            ->orderBy('due_on')
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->map(fn (ActionItem $actionItem): AttentionItem => AttentionItem::fromActionItem($actionItem, $today));
    }

    /**
     * Keep `completed_at` consistent with the status being written.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function withCompletionTimestamp(array $attributes, ?ActionItem $actionItem): array
    {
        if (! array_key_exists('status', $attributes)) {
            return $attributes;
        }

        $status = $attributes['status'] instanceof ActionItemStatus
            ? $attributes['status']
            : ActionItemStatus::from($attributes['status']);

        if ($status === ActionItemStatus::Done) {
            $attributes['completed_at'] ??= $actionItem?->completed_at ?? now();
        } else {
            $attributes['completed_at'] = null;
        }

        return $attributes;
    }
}
