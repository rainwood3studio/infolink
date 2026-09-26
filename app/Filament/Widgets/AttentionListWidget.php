<?php

namespace App\Filament\Widgets;

use App\Domain\Insights\InsightService;
use App\Domain\Work\ActionItemService;
use App\Domain\Work\AttentionItem;
use App\Models\ActionItem;
use App\Models\Insight;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * 「今天要處理」: unresolved critical/warning insights plus overdue or due-today action items.
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
        return app(ActionItemService::class)->attentionList();
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
}
