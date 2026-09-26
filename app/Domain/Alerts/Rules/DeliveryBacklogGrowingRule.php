<?php

namespace App\Domain\Alerts\Rules;

use App\Domain\Alerts\Firing;
use App\Enums\InsightKind;
use App\Models\AlertRule;
use App\Models\MetricValue;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * backlog 成長: `delivery.net_flow` (created − closed per ISO week) above `threshold` (default 0) for params `weeks`
 * (default 3) consecutive COMPLETED weeks ending last week. The current week is ignored: its value is partial and
 * changes daily. Without last week's value (stale data) the rule does not fire.
 *
 * Template variables: weeks, total, from, to.
 */
class DeliveryBacklogGrowingRule extends BaseRule
{
    public static function label(): string
    {
        return 'backlog 連續成長';
    }

    public function evaluate(AlertRule $rule, CarbonImmutable $now): array
    {
        $weeks = max(1, (int) $this->param($rule, 'weeks', 3));
        $threshold = $this->threshold($rule, 0);
        $currentWeek = $now->startOfWeek(CarbonInterface::MONDAY)->startOfDay();

        $recent = $this->weekly('delivery.net_flow', $currentWeek, $weeks);

        $isCurrent = $recent->keys()->first() === $currentWeek->subWeek()->toDateString();

        if ($recent->count() < $weeks || ! $isCurrent || ! $this->consecutive($recent->keys()) || $recent->contains(fn (float $value): bool => $value <= $threshold)) {
            return [];
        }

        $created = $this->weekly('delivery.created', $currentWeek, $weeks);
        $closed = $this->weekly('delivery.closed', $currentWeek, $weeks);
        $total = $recent->sum();
        $ordered = $recent->sortKeys();

        $rows = $ordered
            ->map(fn (float $net, string $week): string => sprintf(
                '| %s | %s | %s | %s |',
                $week,
                isset($created[$week]) ? self::number($created[$week]) : '—',
                isset($closed[$week]) ? self::number($closed[$week]) : '—',
                self::signed($net),
            ))
            ->implode("\n");

        return [new Firing(
            vars: [
                'weeks' => $weeks,
                'total' => self::signed($total),
                'from' => (string) $ordered->keys()->first(),
                'to' => (string) $ordered->keys()->last(),
            ],
            body: implode("\n", [
                "連續 {$weeks} 週新增議題多於結案，未結存量累計 **".self::signed($total).'**。',
                '',
                '| 週（週一） | 新增 | 結案 | 淨增 |',
                '| --- | ---: | ---: | ---: |',
                $rows,
                '',
                '**建議**：檢視新增來源（哪個專案、誰開的），請文豪集中驗收已到驗證中的議題，並暫緩非必要的新需求。',
            ]),
            evidence: [
                'metric_key' => 'delivery.net_flow',
                'weeks' => $ordered->all(),
                'total' => $total,
            ],
            kind: InsightKind::Anomaly,
        )];
    }

    /**
     * The latest `$limit` weekly totals before the current week, keyed by week start (Y-m-d), newest first.
     *
     * @return Collection<string, float>
     */
    protected function weekly(string $key, CarbonImmutable $currentWeek, int $limit): Collection
    {
        return MetricValue::query()
            ->where('metric_key', $key)
            ->where('dimension', '')
            ->whereDate('period_start', '<', $currentWeek->toDateString())
            ->orderByDesc('period_start')
            ->limit($limit)
            ->get()
            ->mapWithKeys(fn (MetricValue $row): array => [$row->period_start->toDateString() => (float) $row->value]);
    }

    /**
     * @param  Collection<int, string>  $weeks  newest first
     */
    protected function consecutive(Collection $weeks): bool
    {
        return $weeks->sliding(2)->every(fn (Collection $pair): bool => CarbonImmutable::parse($pair->last())->addWeek()->isSameDay(CarbonImmutable::parse($pair->first())));
    }
}
