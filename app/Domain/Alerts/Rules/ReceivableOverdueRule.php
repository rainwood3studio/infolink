<?php

namespace App\Domain\Alerts\Rules;

use App\Domain\Alerts\Firing;
use App\Enums\InsightSeverity;
use App\Enums\ReceivableStatus;
use App\Models\AlertRule;
use App\Models\Receivable;
use Carbon\CarbonImmutable;

/**
 * 應收逾期: an outstanding (planned/invoiced) receivable more than `threshold` days (default 3) past `expected_on`;
 * more than `critical_threshold` days (default 30) is critical. Any confidence. Recurring fees are included unless
 * params `include_recurring` is false (an unpaid maintenance fee is still money not received).
 *
 * Template variables: id, label, amount (萬), amount_taxed, date (m/d), days.
 */
class ReceivableOverdueRule extends BaseRule
{
    public static function label(): string
    {
        return '應收逾期';
    }

    public function evaluate(AlertRule $rule, CarbonImmutable $now): array
    {
        $today = $now->startOfDay();
        $graceDays = (int) $this->threshold($rule, 3);
        $criticalDays = $this->criticalThreshold($rule) ?? 30.0;

        return Receivable::query()
            ->with('customer')
            ->outstanding()
            ->whereDate('expected_on', '<', $today->subDays($graceDays)->toDateString())
            ->when(! $this->boolParam($rule, 'include_recurring', true), fn ($query) => $query->where('is_recurring', false))
            ->orderBy('expected_on')
            ->orderBy('id')
            ->get()
            ->map(function (Receivable $receivable) use ($rule, $today, $criticalDays): Firing {
                $days = (int) $receivable->expected_on->diffInDays($today);
                $label = self::labelFor($receivable);
                $invoiced = $receivable->status === ReceivableStatus::Invoiced || $receivable->invoiced_on !== null;

                return new Firing(
                    vars: [
                        'id' => $receivable->id,
                        'label' => $label,
                        'amount' => self::wan($receivable->amount_taxed),
                        'amount_taxed' => number_format($receivable->amount_taxed),
                        'date' => $receivable->expected_on->format('n/j'),
                        'days' => $days,
                    ],
                    body: implode("\n", [
                        "- 應收：{$label}（#{$receivable->id}，{$receivable->confidence->getLabel()}確定性".($receivable->is_recurring ? '，經常性' : '').'）',
                        sprintf('- 金額：含稅 NT$%s（未稅 %s）', number_format($receivable->amount_taxed), number_format($receivable->amount_untaxed)),
                        sprintf('- 預計收款：%s，已逾期 **%d 天**', $receivable->expected_on->toDateString(), $days),
                        '- 發票：'.($invoiced ? '已開立'.($receivable->invoiced_on ? '（'.$receivable->invoiced_on->toDateString().'）' : '') : '**尚未開立**'),
                        '',
                        '**建議**：'.($invoiced ? '' : '先開立發票；').'聯絡客戶確認付款時程。已收到請標記收款（或匯入銀行明細沖銷）；確定延後請更新預計收款日。',
                    ]),
                    evidence: [
                        'receivable_id' => $receivable->id,
                        'expected_on' => $receivable->expected_on->toDateString(),
                        'days_overdue' => $days,
                        'amount_taxed' => $receivable->amount_taxed,
                        'status' => $receivable->status->value,
                        'is_recurring' => $receivable->is_recurring,
                    ],
                    severity: $days > $criticalDays ? InsightSeverity::Critical : $rule->severity,
                );
            })
            ->values()
            ->all();
    }

    /**
     * 「長照期中款」: customer short name + item.
     */
    public static function labelFor(Receivable $receivable): string
    {
        return ($receivable->customer?->short_name ?? '').$receivable->item;
    }
}
