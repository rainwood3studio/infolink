<?php

namespace App\Domain\Alerts\Rules;

use App\Domain\Alerts\Firing;
use App\Enums\InsightKind;
use App\Enums\ReportType;
use App\Models\AlertRule;
use App\Models\Report;
use Carbon\CarbonImmutable;

/**
 * 每日簡報未產生: on a weekday at or after params `after` (default 10:00), no `daily_brief` report for today
 * (period_start = today). Resolves itself once the report exists (or on the next day). Public holidays are not
 * known, so a holiday without a brief fires too.
 *
 * Template variables: date (Y-m-d), after.
 */
class BriefMissingRule extends BaseRule
{
    public static function label(): string
    {
        return '每日簡報未產生';
    }

    public function evaluate(AlertRule $rule, CarbonImmutable $now): array
    {
        $after = (string) $this->param($rule, 'after', '10:00');
        [$hour, $minute] = array_map('intval', explode(':', $after.':0'));

        if (! $now->isWeekday() || $now->lt($now->setTime($hour, $minute))) {
            return [];
        }

        $exists = Report::query()
            ->where('type', ReportType::DailyBrief)
            ->whereDate('period_start', $now->toDateString())
            ->exists();

        if ($exists) {
            return [];
        }

        $latest = Report::query()->where('type', ReportType::DailyBrief)->latest('period_start')->first();

        return [new Firing(
            vars: [
                'date' => $now->toDateString(),
                'after' => $after,
            ],
            body: implode("\n", [
                sprintf('- 今天（%s）%s 後仍沒有每日簡報', $now->toDateString(), $after),
                '- 最近一份：'.($latest !== null ? $latest->period_start->toDateString().'「'.$latest->title.'」' : '（沒有）'),
                '',
                '**建議**：檢查 launchd 排程（平日 08:30）與 Claude CLI 的執行紀錄；可以手動請 Claude 產生今天的簡報。',
            ]),
            evidence: [
                'date' => $now->toDateString(),
                'latest_brief_date' => $latest?->period_start->toDateString(),
            ],
            kind: InsightKind::Anomaly,
        )];
    }
}
