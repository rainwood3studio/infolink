<?php

namespace App\Domain\Work;

use App\Enums\ActionItemPriority;
use App\Enums\InsightSeverity;
use App\Models\ActionItem;
use App\Models\Insight;
use Carbon\CarbonInterface;

/**
 * One row of the "今天要處理" list: either an unresolved critical/warning insight or a pending action item that is
 * overdue or due today. Lets the dashboard render both kinds uniformly.
 */
final readonly class AttentionItem
{
    public const string TYPE_INSIGHT = 'insight';

    public const string TYPE_ACTION_ITEM = 'action_item';

    /**
     * @param  'insight'|'action_item'  $type
     * @param  string  $color  Filament colour name for the badge (danger, warning, ...).
     */
    public function __construct(
        public string $type,
        public Insight|ActionItem $model,
        public string $title,
        public ?InsightSeverity $severity,
        public ?ActionItemPriority $priority,
        public ?CarbonInterface $dueOn,
        public string $color,
        public string $badge,
    ) {}

    public static function fromInsight(Insight $insight): self
    {
        return new self(
            type: self::TYPE_INSIGHT,
            model: $insight,
            title: $insight->title,
            severity: $insight->severity,
            priority: null,
            dueOn: null,
            color: $insight->severity->getColor(),
            badge: $insight->severity->getLabel(),
        );
    }

    public static function fromActionItem(ActionItem $actionItem, CarbonInterface $today): self
    {
        $daysOverdue = (int) $actionItem->due_on->diffInDays($today->copy()->startOfDay());

        return new self(
            type: self::TYPE_ACTION_ITEM,
            model: $actionItem,
            title: $actionItem->title,
            severity: null,
            priority: $actionItem->priority,
            dueOn: $actionItem->due_on,
            color: $daysOverdue > 0 ? 'danger' : 'warning',
            badge: $daysOverdue > 0 ? "逾期 {$daysOverdue} 天" : '今天到期',
        );
    }

    public function isInsight(): bool
    {
        return $this->type === self::TYPE_INSIGHT;
    }
}
