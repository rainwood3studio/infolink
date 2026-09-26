<?php

namespace App\Domain\Alerts\Rules;

use App\Domain\Alerts\Firing;
use App\Domain\Finance\CashForecaster;
use App\Domain\Finance\FinancePosition;
use App\Domain\Finance\ReceivableService;
use App\Models\AlertRule;
use Carbon\CarbonImmutable;

/**
 * 現金低點: the lowest balance in the next 90 days (`cash.forecast_min_90d`, computed live exactly like
 * {@see FinancePosition}: the current balance and the month-end forecast rows up to as_of + 90 days) below
 * `threshold` (default 500,000). The month of the low point goes into the fingerprint, so a low point that moves
 * to another month is a new insight and the old one auto-resolves.
 *
 * Template variables: amount (萬), min_balance, month, as_of, balance (萬).
 */
class CashLowRule extends BaseRule
{
    public function __construct(
        protected ReceivableService $receivables,
        protected CashForecaster $forecaster,
    ) {}

    public static function label(): string
    {
        return '現金低點（90 天）';
    }

    public function evaluate(AlertRule $rule, CarbonImmutable $now): array
    {
        $cash = $this->receivables->currentCashBalance();

        if ($cash === null) {
            return [];
        }

        $forecast = $this->forecaster->calculate(until: $cash['as_of']->addDays(FinancePosition::LOOKAHEAD_DAYS)->format('Y-m'));
        $minBalance = $cash['balance'];
        $minMonth = $cash['as_of']->format('Y-m');

        foreach ($forecast->rows as $row) {
            if ($row['balance'] < $minBalance) {
                $minBalance = $row['balance'];
                $minMonth = $row['month'];
            }
        }

        $threshold = $this->threshold($rule, 500_000);

        if (! self::breaches((string) ($rule->operator ?: '<'), $minBalance, $threshold)) {
            return [];
        }

        $rows = collect($forecast->rows)
            ->map(fn (array $row): string => sprintf('| %s | %s | %s | %s |', $row['month'], number_format($row['inflow']), number_format($row['outflow']), number_format($row['balance'])))
            ->implode("\n");

        return [new Firing(
            vars: [
                'amount' => self::wan($minBalance),
                'min_balance' => number_format($minBalance),
                'month' => $minMonth,
                'as_of' => $cash['as_of']->toDateString(),
                'balance' => self::wan($cash['balance']),
            ],
            body: implode("\n", [
                sprintf('- 目前餘額：NT$%s（%s）', number_format($cash['balance']), $cash['as_of']->toDateString()),
                sprintf('- 90 天內最低：**NT$%s**（%s），門檻 %s', number_format($minBalance), $minMonth, number_format($threshold)),
                '',
                '| 月份 | 流入 | 流出 | 月底餘額 |',
                '| --- | ---: | ---: | ---: |',
                $rows,
                '',
                '**建議**：優先催收高確定性應收、確認低確定性應收能否提前，延後非必要支出；必要時準備週轉。',
            ]),
            evidence: [
                'as_of' => $cash['as_of']->toDateString(),
                'balance' => $cash['balance'],
                'min_balance' => $minBalance,
                'min_month' => $minMonth,
                'threshold' => $threshold,
            ],
        )];
    }
}
