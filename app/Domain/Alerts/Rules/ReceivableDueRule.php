<?php

namespace App\Domain\Alerts\Rules;

use App\Domain\Alerts\Firing;
use App\Enums\Confidence;
use App\Enums\InsightKind;
use App\Enums\ReceivableStatus;
use App\Models\AlertRule;
use App\Models\Receivable;
use Carbon\CarbonImmutable;

/**
 * 應收即將到期: a HIGH-confidence receivable still `planned` (no invoice date) whose `expected_on` is today or within
 * `threshold` days (default 7). Recurring fees are included unless params `include_recurring` is false: they need
 * an invoice every month too.
 *
 * Template variables: id, label, amount (萬), amount_taxed, date (m/d), days.
 */
class ReceivableDueRule extends BaseRule
{
    public static function label(): string
    {
        return '應收即將到期（未開發票）';
    }

    public function evaluate(AlertRule $rule, CarbonImmutable $now): array
    {
        $today = $now->startOfDay();
        $days = (int) $this->threshold($rule, 7);

        return Receivable::query()
            ->with('customer')
            ->where('status', ReceivableStatus::Planned)
            ->whereNull('invoiced_on')
            ->where('confidence', Confidence::High)
            ->whereDate('expected_on', '>=', $today->toDateString())
            ->whereDate('expected_on', '<=', $today->addDays($days)->toDateString())
            ->when(! $this->boolParam($rule, 'include_recurring', true), fn ($query) => $query->where('is_recurring', false))
            ->orderBy('expected_on')
            ->orderBy('id')
            ->get()
            ->map(function (Receivable $receivable) use ($today): Firing {
                $daysLeft = (int) $today->diffInDays($receivable->expected_on);
                $label = ReceivableOverdueRule::labelFor($receivable);

                return new Firing(
                    vars: [
                        'id' => $receivable->id,
                        'label' => $label,
                        'amount' => self::wan($receivable->amount_taxed),
                        'amount_taxed' => number_format($receivable->amount_taxed),
                        'date' => $receivable->expected_on->format('n/j'),
                        'days' => $daysLeft,
                    ],
                    body: implode("\n", [
                        "- 應收：{$label}（#{$receivable->id}".($receivable->is_recurring ? '，經常性' : '').'）',
                        sprintf('- 金額：含稅 NT$%s（未稅 %s）', number_format($receivable->amount_taxed), number_format($receivable->amount_untaxed)),
                        sprintf('- 預計收款：%s（%s）', $receivable->expected_on->toDateString(), $daysLeft === 0 ? '今天' : "{$daysLeft} 天後"),
                        '- 狀態：尚未開發票（高確定性）',
                        '',
                        '**建議**：開立並寄出發票，向客戶確認付款流程與日期；日期若會延後，請更新預計收款日。',
                    ]),
                    evidence: [
                        'receivable_id' => $receivable->id,
                        'expected_on' => $receivable->expected_on->toDateString(),
                        'amount_taxed' => $receivable->amount_taxed,
                        'days_until_due' => $daysLeft,
                        'is_recurring' => $receivable->is_recurring,
                    ],
                    kind: InsightKind::Reminder,
                );
            })
            ->values()
            ->all();
    }
}
