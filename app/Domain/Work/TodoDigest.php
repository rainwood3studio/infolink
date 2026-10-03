<?php

namespace App\Domain\Work;

use App\Domain\Notify\MessageFormatter;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The morning LINE nudge (`infolink:notify-todos`): the owner's three most pressing action items, how many more
 * are waiting, how many delegated items are overdue, and a link to the list. Plain text.
 */
class TodoDigest
{
    public const int TOP_ITEMS = 3;

    public const int TITLE_LENGTH = 40;

    public function __construct(
        protected ActionItemService $actionItems,
        protected MessageFormatter $formatter,
    ) {}

    /**
     * The message for `$today`, or null when nothing of the owner's is due and nothing delegated is overdue.
     */
    public function message(?CarbonInterface $today = null): ?string
    {
        $today ??= today();

        $mine = $this->actionItems->myDueItems($today);
        $delegatedOverdue = $this->actionItems->delegatedList($today)->filter->isOverdue()->values();

        if ($mine->isEmpty() && $delegatedOverdue->isEmpty()) {
            return null;
        }

        $top = $mine->take(self::TOP_ITEMS);

        $lines = [$this->headline($top->count())];

        foreach ($top as $item) {
            $lines[] = '• '.$this->formatter->truncate($item->title, self::TITLE_LENGTH)."（{$item->badge}）";
        }

        $summary = array_filter([
            $mine->count() > $top->count() ? '另有 '.($mine->count() - $top->count()).' 件到期／逾期' : null,
            $delegatedOverdue->isEmpty() ? null : $this->delegatedSummary($delegatedOverdue),
        ]);

        if ($summary !== []) {
            $lines[] = implode('；', $summary);
        }

        $lines[] = $this->formatter->actionItemsUrl();

        return implode("\n", $lines);
    }

    protected function headline(int $count): string
    {
        return match ($count) {
            0 => '今天沒有到期的待辦',
            1 => '今天先做這一件',
            2 => '今天先做這兩件',
            default => '今天先做這三件',
        };
    }

    /**
     * e.g. 「已交辦逾期 3 件（裕樺 2、文豪 1）」, the owner with the most overdue items first.
     *
     * @param  Collection<int, AttentionItem>  $delegatedOverdue
     */
    protected function delegatedSummary(Collection $delegatedOverdue): string
    {
        $byOwner = $delegatedOverdue
            ->countBy(fn (AttentionItem $item): string => trim((string) $item->model->owner))
            ->sortDesc()
            ->map(fn (int $count, string $owner): string => "{$owner} {$count}")
            ->implode('、');

        return "已交辦逾期 {$delegatedOverdue->count()} 件（{$byOwner}）";
    }
}
