<?php

namespace App\Filament\Widgets;

use App\Domain\Insights\InsightService;
use App\Domain\Work\ActionItemService;
use App\Domain\Work\AttentionItem;
use App\Enums\ActionItemPostpone;
use App\Models\ActionItem;
use App\Models\Insight;
use Carbon\CarbonInterface;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * 「今天要處理」: unresolved critical/warning insights plus the owner's own overdue or due-today action items, each
 * with quick triage (完成 / 延後 / 交辦 / 放棄). Underneath, 「已交辦」 lists the pending items handed to colleagues.
 */
class AttentionListWidget extends Widget
{
    protected string $view = 'filament.widgets.attention-list-widget';

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    /**
     * @return Collection<int, AttentionItem>
     */
    public function getItems(): Collection
    {
        return app(ActionItemService::class)->myAttentionList();
    }

    /**
     * @return Collection<int, AttentionItem>
     */
    public function getDelegatedItems(): Collection
    {
        return app(ActionItemService::class)->delegatedList();
    }

    /**
     * The 「延後」 menu: each choice with the date it resolves to today.
     *
     * @return list<array{value: string, label: string, date: string}>
     */
    public function getPostponeOptions(): array
    {
        return array_map(fn (ActionItemPostpone $choice): array => [
            'value' => $choice->value,
            'label' => $choice->getLabel(),
            'date' => self::shortDate($choice->dueOn()),
        ], ActionItemPostpone::cases());
    }

    /**
     * The colleagues the 「交辦」 menu offers (active developers, without the owner).
     *
     * @return list<string>
     */
    public function getDelegates(): array
    {
        return app(ActionItemService::class)->delegates();
    }

    public function acknowledgeInsight(int $insightId): void
    {
        app(InsightService::class)->acknowledge(Insight::findOrFail($insightId));

        Notification::make()->title('已確認')->success()->send();
    }

    public function resolveInsight(int $insightId): void
    {
        app(InsightService::class)->resolve(Insight::findOrFail($insightId));

        Notification::make()->title('已標記為已處理')->success()->send();
    }

    public function createActionItemFromInsight(int $insightId): void
    {
        $insight = Insight::findOrFail($insightId);
        $insightService = app(InsightService::class);

        $insightService->createActionItem($insight);
        $insightService->acknowledge($insight);

        Notification::make()->title('已建立待辦')->success()->send();
    }

    public function completeActionItem(int $actionItemId): void
    {
        app(ActionItemService::class)->complete(ActionItem::findOrFail($actionItemId));

        Notification::make()->title('待辦已完成')->success()->send();
    }

    public function postponeActionItem(int $actionItemId, string $choice): void
    {
        $dueOn = ActionItemPostpone::from($choice)->dueOn();

        app(ActionItemService::class)->postpone(ActionItem::findOrFail($actionItemId), $dueOn);

        Notification::make()->title('已延後到 '.self::shortDate($dueOn))->success()->send();
    }

    public function delegateActionItem(int $actionItemId, string $owner): void
    {
        $service = app(ActionItemService::class);

        abort_unless(in_array($owner, $service->delegates(), true), 422);

        $service->delegate(ActionItem::findOrFail($actionItemId), $owner);

        Notification::make()->title("已交辦給 {$owner}")->success()->send();
    }

    public function takeBackActionItem(int $actionItemId): void
    {
        app(ActionItemService::class)->takeBack(ActionItem::findOrFail($actionItemId));

        Notification::make()->title('已收回')->success()->send();
    }

    public function dropActionItem(int $actionItemId): void
    {
        app(ActionItemService::class)->drop(ActionItem::findOrFail($actionItemId));

        Notification::make()->title('已放棄')->success()->send();
    }

    /**
     * e.g. 「10/05（一）」.
     */
    protected static function shortDate(CarbonInterface $date): string
    {
        return $date->format('m/d').'（'.['日', '一', '二', '三', '四', '五', '六'][$date->dayOfWeek].'）';
    }
}
