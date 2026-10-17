<?php

namespace App\Domain\Alerts\Rules;

use App\Domain\Alerts\Firing;
use App\Domain\Finance\ReceivableService;
use App\Enums\InsightKind;
use App\Models\AlertRule;
use App\Models\RecurringExpense;
use Carbon\CarbonImmutable;

/**
 * 固定支出即將到期: an occurrence of an active recurring expense ({@see RecurringExpense}) falls due today or within
 * params `window_days` (default 7) days. Expenses already inside the cost baseline (`in_baseline`) only fire when the
 * amount is at least params `min_amount` (default 50,000) — a big dated payment still needs the cash on the day even
 * if the monthly budget covers it; the small ones are left to the 支出日曆.
 *
 * The body shows the cash balance after paying it (latest bank balance − amount). One insight per occurrence:
 * fingerprint `expense-due:{id}:{due_on}`.
 *
 * Template variables: id, name, amount (萬), amount_ntd, date (m/d), due_on (Y-m-d), days.
 */
class ExpenseDueRule extends BaseRule
{
    public function __construct(protected ReceivableService $receivables) {}

    public static function label(): string
    {
        return '固定支出即將到期';
    }

    public function evaluate(AlertRule $rule, CarbonImmutable $now): array
    {
        $today = $now->startOfDay();
        $windowDays = (int) $this->param($rule, 'window_days', 7);
        $minAmount = (int) $this->param($rule, 'min_amount', 50_000);
        $windowEnd = $today->addDays($windowDays);
        $cash = $this->receivables->currentCashBalance();

        $firings = [];

        $expenses = RecurringExpense::query()
            ->active()
            ->whereDate('starts_on', '<=', $windowEnd)
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today))
            ->orderBy('id')
            ->get();

        foreach ($expenses as $expense) {
            if ($expense->in_baseline && $expense->amount < $minAmount) {
                continue;
            }

            foreach ($expense->occurrencesBetween($today, $windowEnd) as $occurrence) {
                $firings[] = $this->firing($expense, $occurrence['due_on'], $today, $cash);
            }
        }

        usort($firings, fn (Firing $a, Firing $b): int => [$a->vars['due_on'], $a->vars['id']] <=> [$b->vars['due_on'], $b->vars['id']]);

        return $firings;
    }

    /**
     * @param  array{balance:int, as_of:CarbonImmutable}|null  $cash
     */
    protected function firing(RecurringExpense $expense, CarbonImmutable $dueOn, CarbonImmutable $today, ?array $cash): Firing
    {
        $days = (int) $today->diffInDays($dueOn);
        $after = $cash === null ? null : $cash['balance'] - $expense->amount;

        return new Firing(
            vars: [
                'id' => $expense->id,
                'name' => $expense->name,
                'amount' => self::wan($expense->amount),
                'amount_ntd' => number_format($expense->amount),
                'date' => $dueOn->format('n/j'),
                'due_on' => $dueOn->toDateString(),
                'days' => $days,
            ],
            body: implode("\n", array_filter([
                sprintf('- 固定支出：%s（%s，%s）', $expense->name, $expense->category->getLabel(), $expense->cadence->getLabel()),
                sprintf('- 金額：NT$%s%s', number_format($expense->amount), $expense->in_baseline ? '（已包含在月成本基準裡）' : ''),
                sprintf('- 到期日：%s（%s）', $dueOn->toDateString(), $days === 0 ? '今天' : "{$days} 天後"),
                $cash === null
                    ? '- 目前餘額：尚無銀行明細'
                    : sprintf('- 目前餘額：NT$%s（%s），付完剩 **NT$%s**', number_format($cash['balance']), $cash['as_of']->toDateString(), number_format($after)),
                '',
                '**建議**：確認帳戶餘額足夠並排定付款；金額或日期有變就更新固定支出設定。',
            ])),
            evidence: [
                'recurring_expense_id' => $expense->id,
                'due_on' => $dueOn->toDateString(),
                'amount' => $expense->amount,
                'in_baseline' => $expense->in_baseline,
                'days_until_due' => $days,
                'balance' => $cash['balance'] ?? null,
                'balance_after' => $after,
            ],
            kind: InsightKind::Reminder,
        );
    }
}
