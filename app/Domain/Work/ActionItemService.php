<?php

namespace App\Domain\Work;

use App\Enums\ActionItemStatus;
use App\Enums\InsightSeverity;
use App\Enums\Source;
use App\Models\ActionItem;
use App\Models\Insight;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * The single write path for action items, plus the "今天要處理" attention list.
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
     * The "今天要處理" list, in display order:
     * unresolved critical insights → unresolved warning insights (each newest `last_seen_at` first)
     * → pending action items due on or before today (oldest due date first, then priority).
     *
     * @return Collection<int, AttentionItem>
     */
    public function attentionList(?CarbonInterface $today = null): Collection
    {
        $today ??= today();

        $insights = Insight::query()
            ->unresolved()
            ->whereIn('severity', [InsightSeverity::Critical, InsightSeverity::Warning])
            ->orderByRaw('case when severity = ? then 0 else 1 end', [InsightSeverity::Critical->value])
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Insight $insight): AttentionItem => AttentionItem::fromInsight($insight));

        $actionItems = ActionItem::query()
            ->pending()
            ->whereNotNull('due_on')
            ->whereDate('due_on', '<=', $today->toDateString())
            ->orderBy('due_on')
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->map(fn (ActionItem $actionItem): AttentionItem => AttentionItem::fromActionItem($actionItem, $today));

        return $insights->concat($actionItems)->values();
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
