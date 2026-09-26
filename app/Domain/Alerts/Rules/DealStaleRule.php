<?php

namespace App\Domain\Alerts\Rules;

use App\Domain\Alerts\Firing;
use App\Models\AlertRule;
use App\Models\Deal;
use Carbon\CarbonImmutable;

/**
 * 業務斷層: an open deal that has had no usable next action for at least `threshold` days (default 7), one insight
 * per deal (`deal-stale:<id>`). This is the per-deal form of "`sales.deals_no_next_action` > 0 持續 7 天".
 *
 * How long a deal has been without a next action:
 * - `next_action_on` set but past: days since `next_action_on` (the step was due that day and nothing replaced it).
 * - `next_action_on` empty: days since the deal was last updated (`updated_at`). The table keeps no history of when
 *   the date was cleared, so any edit to the deal restarts the clock; logging an event does not touch the deal.
 *
 * The insight auto-resolves once the deal gets a future next action, or is won / lost.
 *
 * Template variables: id, label (party「title」), party, deal (title), stage, days, reason, next_action, next_action_on.
 */
class DealStaleRule extends BaseRule
{
    public static function label(): string
    {
        return '業務斷層';
    }

    public function evaluate(AlertRule $rule, CarbonImmutable $now): array
    {
        $today = $now->startOfDay();
        $minimumDays = (int) $this->threshold($rule, 7);

        return Deal::query()
            ->with('customer')
            ->withoutNextAction($today)
            ->orderByRaw('case when next_action_on is null then 0 else 1 end')
            ->orderBy('next_action_on')
            ->orderBy('id')
            ->get()
            ->map(function (Deal $deal) use ($rule, $today, $minimumDays): ?Firing {
                $since = CarbonImmutable::instance($deal->next_action_on ?? $deal->updated_at)->startOfDay();
                $days = (int) $since->diffInDays($today);

                if (! self::breaches($rule->operator ?? '>=', $days, $minimumDays)) {
                    return null;
                }

                $missing = $deal->next_action_on === null;
                $reason = $missing
                    ? "沒有下一步已 {$days} 天"
                    : "下一步「{$deal->next_action}」已逾期 {$days} 天";

                return new Firing(
                    vars: [
                        'id' => $deal->id,
                        'label' => "{$deal->party_name}「{$deal->title}」",
                        'party' => $deal->party_name,
                        'deal' => $deal->title,
                        'stage' => $deal->stage->getLabel(),
                        'days' => $days,
                        'reason' => $reason,
                        'next_action' => (string) $deal->next_action,
                        'next_action_on' => $deal->next_action_on?->toDateString() ?? '',
                    ],
                    body: implode("\n", [
                        "- 機會：{$deal->party_name}「{$deal->title}」（#{$deal->id}，{$deal->stage->getLabel()}）",
                        sprintf('- 金額：未稅 NT$%s × %d%% = 加權 NT$%s', number_format($deal->amount_untaxed ?? 0), $deal->probability, number_format($deal->weighted_amount)),
                        $missing
                            ? sprintf('- 下一步：**未設定**（上次更新 %s，已 %d 天）', $deal->updated_at->toDateString(), $days)
                            : sprintf('- 下一步：%s（%s，**已逾期 %d 天**）', $deal->next_action ?: '（未填內容）', $deal->next_action_on->toDateString(), $days),
                        '',
                        '**建議**：和窗口確認進度，設定下一步與日期；若機會已不成立，改為未成交（lost）結案。',
                    ]),
                    evidence: [
                        'deal_id' => $deal->id,
                        'stage' => $deal->stage->value,
                        'next_action' => $deal->next_action,
                        'next_action_on' => $deal->next_action_on?->toDateString(),
                        'updated_at' => $deal->updated_at->toIso8601String(),
                        'days_without_next_action' => $days,
                        'weighted_amount' => $deal->weighted_amount,
                    ],
                );
            })
            ->filter()
            ->values()
            ->all();
    }
}
