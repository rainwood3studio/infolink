<?php

namespace App\Domain\Work;

use App\Enums\ActionItemPriority;
use App\Enums\InsightSeverity;
use App\Models\ActionItem;
use App\Models\Insight;
use Carbon\CarbonInterface;

/**
 * One row of the "今天要處理" list: either an unresolved critical/warning insight or a pending action item that is
 * overdue or due today. Lets the dashboard render both kinds uniformly. The 「已交辦」 list reuses it for delegated
 * action items, which may also be due later or undated.
 */
final readonly class AttentionItem
{
    public const string TYPE_INSIGHT = 'insight';

    public const string TYPE_ACTION_ITEM = 'action_item';

    /**
     * @param  'insight'|'action_item'  $type
     * @param  string  $color  Filament colour name for the badge (danger, warning, ...).
     * @param  int  $daysOverdue  Days past the due date; 0 for insights and for items that are not overdue.
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
        public int $daysOverdue = 0,
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
        $today = $today->copy()->startOfDay();
        $dueOn = $actionItem->due_on;
        $daysOverdue = $dueOn !== null && $dueOn->lt($today) ? (int) $dueOn->diffInDays($today) : 0;

        [$color, $badge] = match (true) {
            $daysOverdue > 0 => ['danger', "逾期 {$daysOverdue} 天"],
            $dueOn === null => ['gray', '未排期'],
            $dueOn->isSameDay($today) => ['warning', '今天到期'],
            default => ['gray', '未到期'],
        };

        return new self(
            type: self::TYPE_ACTION_ITEM,
            model: $actionItem,
            title: $actionItem->title,
            severity: null,
            priority: $actionItem->priority,
            dueOn: $dueOn,
            color: $color,
            badge: $badge,
            daysOverdue: $daysOverdue,
        );
    }

    public function isInsight(): bool
    {
        return $this->type === self::TYPE_INSIGHT;
    }

    public function isOverdue(): bool
    {
        return $this->daysOverdue > 0;
    }
}
