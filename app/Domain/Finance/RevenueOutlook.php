<?php

namespace App\Domain\Finance;

use App\Domain\Delivery\ClosingBoard;
use App\Enums\Confidence;
use App\Enums\ReceivableStatus;
use App\Models\Deal;
use App\Models\Receivable;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Read model for the 收入展望 page and the `revenue_outlook` MCP tool: month by month, what comes in and goes out
 * over the next year, when cash starts falling, when it reaches zero and how much new business closes the gap.
 *
 * It builds on {@see CashForecaster} (same as_of, opening balance and method), so with no delay the confirmed
 * balances are exactly the existing forecast, and splits each month into layers (whole NTD):
 * - `receivables`: high-confidence outstanding receivables that are not recurring, tax included. With a delay every
 *   one of them moves that many months later (overdue ones are first counted in the as_of month, then moved); those
 *   pushed past the horizon drop out and are reported in `assumptions.delayed_beyond_horizon`.
 * - `recurring`: the recurring fee rows that exist (one receivable per month), tax included. Never delayed.
 * - `recurring_assumed`: an ASSUMPTION, not a booking. Recurring rows are only entered some months ahead, so every
 *   month after the last month that has a recurring row (and after the as_of month) repeats that last month's total,
 *   i.e. the maintenance contracts are assumed to be renewed at the same fee.
 * - `low_confidence`: low-confidence outstanding receivables, tax included; listed always, counted in the base
 *   balance only when asked for. Not delayed.
 * - `planned_inflow` / `planned_outflow`: planned cash flows (VAT, bonuses, …). `cost`: the cost baseline (as_of
 *   month: only the part not yet paid).
 * - `pipeline_weighted`: open deals that have an amount and an expected close date inside the horizon:
 *   amount × probability in the close month plus recurring_monthly × probability in every later month. UNTAXED and
 *   not cash timing — a deal closing is not money arriving — so it only feeds `balance_with_pipeline`.
 *
 * Balances are month-end: `balance_confirmed` = booked rows only; `balance_base` = confirmed + recurring_assumed
 * (+ low_confidence when included), the headline scenario; `balance_with_pipeline` = base + cumulative pipeline.
 */
class RevenueOutlook
{
    public const int DEFAULT_MONTHS = 12;

    public const int MAX_MONTHS = 36;

    public const int MAX_DELAY_MONTHS = 12;

    public const string MISSING_AMOUNT = '金額';

    public const string MISSING_CLOSE_DATE = '預計成交日';

    public const string MISSING_NEXT_ACTION_DATE = '下一步日期';

    /** The deal has no amount or no expected close date. */
    public const string NOT_COUNTED_MISSING_FIELDS = 'missing_fields';

    /** The expected close month is before the as_of month: the date needs updating. */
    public const string NOT_COUNTED_CLOSE_DATE_PASSED = 'close_date_passed';

    /** The expected close month is after the last month of the outlook. */
    public const string NOT_COUNTED_BEYOND_HORIZON = 'beyond_horizon';

    public function __construct(
        private CashForecaster $forecaster,
        private ReceivableService $receivables,
        private ClosingBoard $closingBoard,
    ) {}

    /**
     * @param  int  $months  How many month rows, starting with the as_of month (1–36).
     * @param  int  $delayMonths  Move every non-recurring high-confidence receivable this many months later (0–12).
     * @param  bool  $includeLowConfidence  Count low-confidence receivables in the base balance.
     * @return array{
     *     summary: array{
     *         as_of: string,
     *         has_bank_data: bool,
     *         opening_balance: int,
     *         months: int,
     *         horizon_end: string,
     *         monthly_cost: int,
     *         recurring_monthly: int,
     *         monthly_gap: int,
     *         annual_gap: int,
     *         peak: array{month: string, balance: int},
     *         first_declining_month: ?string,
     *         zero_month: ?array{month: string, extrapolated: bool, months_of_runway_after_horizon: ?int},
     *         zero_month_confirmed: ?array{month: string, extrapolated: bool, months_of_runway_after_horizon: ?int},
     *         end_balance_confirmed: int,
     *         end_balance_base: int,
     *         end_balance_with_pipeline: int,
     *         pipeline_weighted_total: int,
     *         open_deals: int,
     *         counted_deals: int,
     *     },
     *     options: array{months: int, delay_months: int, include_low_confidence: bool},
     *     assumptions: array{
     *         recurring_last_month: ?string,
     *         recurring_assumed_from: ?string,
     *         low_confidence_taxed: int,
     *         delayed_beyond_horizon: array{count: int, amount_taxed: int},
     *         as_of_month_cost: ?array{month: string, monthly_cost: int, booked_regular_outflow: int, deducted: int},
     *     },
     *     months: list<array{
     *         month: string,
     *         receivables: int,
     *         recurring: int,
     *         recurring_assumed: int,
     *         low_confidence: int,
     *         planned_inflow: int,
     *         cost: int,
     *         planned_outflow: int,
     *         pipeline_weighted: int,
     *         net_confirmed: int,
     *         net_base: int,
     *         balance_confirmed: int,
     *         balance_base: int,
     *         balance_with_pipeline: int,
     *         items: array{
     *             receivables: list<array{label: string, amount: int}>,
     *             recurring: list<array{label: string, amount: int}>,
     *             low_confidence: list<array{label: string, amount: int}>,
     *             planned: list<array{label: string, amount: int}>,
     *             pipeline: list<array{label: string, amount: int}>,
     *         },
     *     }>,
     *     deals: list<array{
     *         id: int,
     *         party: string,
     *         title: string,
     *         stage: string,
     *         stage_label: string,
     *         amount_untaxed: ?int,
     *         probability: int,
     *         weighted: int,
     *         recurring_monthly: ?int,
     *         expected_close_on: ?string,
     *         next_action: ?string,
     *         next_action_on: ?string,
     *         missing: list<string>,
     *         counted: bool,
     *         not_counted_reason: ?string,
     *     }>,
     *     closing_receivables: list<array{
     *         id: int,
     *         label: string,
     *         item: string,
     *         amount_taxed: int,
     *         expected_on: string,
     *         is_overdue: bool,
     *         confidence: string,
     *         month: ?string,
     *         at_risk: bool,
     *         project: array{
     *             id: int,
     *             name: string,
     *             target_close_date: ?string,
     *             days_left: ?int,
     *             is_overdue: bool,
     *             open: int,
     *             burn_from: ?array{date: string, open: int, days_ago: int},
     *             burn_per_day: ?float,
     *             projection: string,
     *             projected_close_date: ?string,
     *             days_late: ?int,
     *         },
     *     }>,
     * }
     */
    public function calculate(int $months = self::DEFAULT_MONTHS, int $delayMonths = 0, bool $includeLowConfidence = false): array
    {
        if ($months < 1 || $months > self::MAX_MONTHS) {
            throw new InvalidArgumentException('The outlook covers 1 to '.self::MAX_MONTHS.' months.');
        }

        if ($delayMonths < 0 || $delayMonths > self::MAX_DELAY_MONTHS) {
            throw new InvalidArgumentException('The delay must be between 0 and '.self::MAX_DELAY_MONTHS.' months.');
        }

        $cash = $this->receivables->currentCashBalance();
        $asOf = CarbonImmutable::parse($cash['as_of'] ?? today())->startOfDay();
        $until = $asOf->startOfMonth()->addMonthsNoOverflow($months - 1)->endOfMonth()->startOfDay();
        $forecast = $this->forecaster->calculate($asOf, $cash['balance'] ?? 0, $until);

        $asOfMonth = $asOf->format('Y-m');
        $rows = [];

        foreach ($forecast->rows as $row) {
            $rows[$row['month']] = [
                'month' => $row['month'],
                'receivables' => 0,
                'recurring' => 0,
                'recurring_assumed' => 0,
                'low_confidence' => 0,
                'planned_inflow' => 0,
                'cost' => $row['outflow'],
                'planned_outflow' => 0,
                'pipeline_weighted' => 0,
                'items' => ['receivables' => [], 'recurring' => [], 'low_confidence' => [], 'planned' => [], 'pipeline' => []],
            ];
        }

        $delayedBeyondHorizon = $this->addConfirmedReceivables($rows, $forecast->assumptions['receivables'], $delayMonths);
        $this->addPlannedCashFlows($rows, $forecast->assumptions['planned_cash_flows']);
        $lowConfidenceTaxed = $this->addLowConfidence($rows, $asOfMonth, $until);
        $recurring = $this->addAssumedRecurring($rows, $asOfMonth);
        $deals = $this->addPipeline($rows, $asOfMonth);

        $monthlyCost = $this->steadyMonthlyCost($forecast->assumptions['cost_baselines'], (string) array_key_last($rows));
        $monthRows = $this->withBalances($rows, $forecast->openingBalance, $includeLowConfidence);
        $last = $monthRows[array_key_last($monthRows)];
        $monthlyGap = $monthlyCost - $recurring['monthly'];
        $recurringBookedPastHorizon = $recurring['last_month'] !== null && $recurring['last_month'] > $last['month'];

        return [
            'summary' => [
                'as_of' => $asOf->toDateString(),
                'has_bank_data' => $cash !== null,
                'opening_balance' => $forecast->openingBalance,
                'months' => $months,
                'horizon_end' => $last['month'],
                'monthly_cost' => $monthlyCost,
                'recurring_monthly' => $recurring['monthly'],
                'monthly_gap' => $monthlyGap,
                'annual_gap' => $monthlyGap * 12,
                'peak' => $this->peak($monthRows),
                'first_declining_month' => $this->firstDecliningMonth($monthRows),
                'zero_month' => $this->zeroMonth($monthRows, 'balance_base', $monthlyGap),
                'zero_month_confirmed' => $this->zeroMonth($monthRows, 'balance_confirmed', $recurringBookedPastHorizon ? $monthlyGap : $monthlyCost),
                'end_balance_confirmed' => $last['balance_confirmed'],
                'end_balance_base' => $last['balance_base'],
                'end_balance_with_pipeline' => $last['balance_with_pipeline'],
                'pipeline_weighted_total' => (int) array_sum(array_column($monthRows, 'pipeline_weighted')),
                'open_deals' => count($deals),
                'counted_deals' => count(array_filter($deals, fn (array $deal): bool => $deal['counted'])),
            ],
            'options' => [
                'months' => $months,
                'delay_months' => $delayMonths,
                'include_low_confidence' => $includeLowConfidence,
            ],
            'assumptions' => [
                'recurring_last_month' => $recurring['last_month'],
                'recurring_assumed_from' => $recurring['assumed_from'],
                'low_confidence_taxed' => $lowConfidenceTaxed,
                'delayed_beyond_horizon' => $delayedBeyondHorizon,
                'as_of_month_cost' => $forecast->assumptions['as_of_month_cost'],
            ],
            'months' => $monthRows,
            'deals' => $deals,
            'closing_receivables' => $this->closingReceivables($rows, $asOfMonth, $delayMonths),
        ];
    }

    /**
     * Split the forecaster's receivables into the recurring layer (as booked) and the project layer (delayed).
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @param  list<array{id: int, label: string, expected_on: string, month: string, amount_taxed: int, is_recurring: bool, overdue: bool}>  $receivables
     * @return array{count: int, amount_taxed: int} what the delay pushed past the horizon
     */
    protected function addConfirmedReceivables(array &$rows, array $receivables, int $delayMonths): array
    {
        $beyond = ['count' => 0, 'amount_taxed' => 0];

        foreach ($receivables as $receivable) {
            $item = ['label' => $receivable['label'].($receivable['overdue'] ? '（已逾期）' : ''), 'amount' => $receivable['amount_taxed']];

            if ($receivable['is_recurring']) {
                $rows[$receivable['month']]['recurring'] += $receivable['amount_taxed'];
                $rows[$receivable['month']]['items']['recurring'][] = $item;

                continue;
            }

            $month = self::shiftMonth($receivable['month'], $delayMonths);

            if (! isset($rows[$month])) {
                $beyond['count']++;
                $beyond['amount_taxed'] += $receivable['amount_taxed'];

                continue;
            }

            $rows[$month]['receivables'] += $receivable['amount_taxed'];
            $rows[$month]['items']['receivables'][] = $item;
        }

        return $beyond;
    }

    /**
     * Move the planned cash flows out of the forecaster's outflow into their own layers; what is left is the cost.
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @param  list<array{id: int, flow_on: string, amount: int, description: string}>  $flows
     */
    protected function addPlannedCashFlows(array &$rows, array $flows): void
    {
        foreach ($flows as $flow) {
            $month = substr($flow['flow_on'], 0, 7);

            if ($flow['amount'] >= 0) {
                $rows[$month]['planned_inflow'] += $flow['amount'];
            } else {
                $rows[$month]['planned_outflow'] += abs($flow['amount']);
                $rows[$month]['cost'] -= abs($flow['amount']);
            }

            $rows[$month]['items']['planned'][] = ['label' => $flow['description'], 'amount' => $flow['amount']];
        }
    }

    /**
     * Low-confidence outstanding receivables by month, overdue ones in the as_of month (the forecaster's rule).
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @return int their tax-included total inside the horizon
     */
    protected function addLowConfidence(array &$rows, string $asOfMonth, CarbonImmutable $until): int
    {
        $receivables = Receivable::query()
            ->with('customer')
            ->outstanding()
            ->where('confidence', Confidence::Low)
            ->whereDate('expected_on', '<=', $until)
            ->orderBy('expected_on')
            ->orderBy('id')
            ->get();

        foreach ($receivables as $receivable) {
            $month = max($receivable->expected_on->format('Y-m'), $asOfMonth);

            $rows[$month]['low_confidence'] += $receivable->amount_taxed;
            $rows[$month]['items']['low_confidence'][] = ['label' => self::receivableLabel($receivable), 'amount' => $receivable->amount_taxed];
        }

        return (int) $receivables->sum('amount_taxed');
    }

    /**
     * Repeat the last booked month of recurring fees in every later month of the outlook.
     *
     * The run-rate is the total of the high-confidence recurring rows (any status but cancelled) expected in the last
     * month that has one, so a fee already collected in that month still counts.
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @return array{monthly: int, last_month: ?string, assumed_from: ?string}
     */
    protected function addAssumedRecurring(array &$rows, string $asOfMonth): array
    {
        $byMonth = Receivable::query()
            ->where('is_recurring', true)
            ->where('confidence', Confidence::High)
            ->where('status', '!=', ReceivableStatus::Cancelled)
            ->get(['id', 'expected_on', 'amount_taxed'])
            ->groupBy(fn (Receivable $receivable): string => $receivable->expected_on->format('Y-m'))
            ->map(fn (Collection $group): int => (int) $group->sum('amount_taxed'));

        if ($byMonth->isEmpty()) {
            return ['monthly' => 0, 'last_month' => null, 'assumed_from' => null];
        }

        $lastMonth = (string) $byMonth->keys()->max();
        $monthly = $byMonth[$lastMonth];
        $assumedFrom = null;

        foreach (array_keys($rows) as $month) {
            if ($month <= $lastMonth || $month <= $asOfMonth || $monthly === 0) {
                continue;
            }

            $rows[$month]['recurring_assumed'] = $monthly;
            $assumedFrom ??= $month;
        }

        return ['monthly' => $monthly, 'last_month' => $lastMonth, 'assumed_from' => $assumedFrom];
    }

    /**
     * Every open deal, and the weighted pipeline of those that can be placed on the calendar.
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function addPipeline(array &$rows, string $asOfMonth): array
    {
        $lastMonth = (string) array_key_last($rows);

        return Deal::query()
            ->open()
            ->with('customer')
            ->orderByRaw('expected_close_on is null')
            ->orderBy('expected_close_on')
            ->orderBy('id')
            ->get()
            ->map(function (Deal $deal) use (&$rows, $asOfMonth, $lastMonth): array {
                $amount = (int) $deal->amount_untaxed;
                $recurringMonthly = (int) $deal->recurring_monthly;
                $closeMonth = $deal->expected_close_on?->format('Y-m');

                $missing = array_values(array_filter([
                    $amount <= 0 && $recurringMonthly <= 0 ? self::MISSING_AMOUNT : null,
                    $closeMonth === null ? self::MISSING_CLOSE_DATE : null,
                    $deal->next_action_on === null ? self::MISSING_NEXT_ACTION_DATE : null,
                ]));

                $reason = match (true) {
                    in_array(self::MISSING_AMOUNT, $missing, true), $closeMonth === null => self::NOT_COUNTED_MISSING_FIELDS,
                    $closeMonth < $asOfMonth => self::NOT_COUNTED_CLOSE_DATE_PASSED,
                    $closeMonth > $lastMonth => self::NOT_COUNTED_BEYOND_HORIZON,
                    default => null,
                };

                if ($reason === null) {
                    $label = trim($deal->party_name.' '.$deal->title);
                    $weightedRecurring = (int) round($recurringMonthly * $deal->probability / 100);

                    foreach (array_keys($rows) as $month) {
                        $weighted = match (true) {
                            $month === $closeMonth => $deal->weighted_amount,
                            $month > $closeMonth => $weightedRecurring,
                            default => 0,
                        };

                        if ($weighted !== 0) {
                            $rows[$month]['pipeline_weighted'] += $weighted;
                            $rows[$month]['items']['pipeline'][] = ['label' => $label.($month === $closeMonth ? '' : '（每月）'), 'amount' => $weighted];
                        }
                    }
                }

                return [
                    'id' => $deal->id,
                    'party' => $deal->party_name,
                    'title' => $deal->title,
                    'stage' => $deal->stage->value,
                    'stage_label' => $deal->stage->getLabel(),
                    'amount_untaxed' => $deal->amount_untaxed,
                    'probability' => $deal->probability,
                    'weighted' => $deal->weighted_amount,
                    'recurring_monthly' => $deal->recurring_monthly,
                    'expected_close_on' => $deal->expected_close_on?->toDateString(),
                    'next_action' => $deal->next_action,
                    'next_action_on' => $deal->next_action_on?->toDateString(),
                    'missing' => $missing,
                    'counted' => $reason === null,
                    'not_counted_reason' => $reason,
                ];
            })
            ->all();
    }

    /**
     * The cost baseline in force in the last month of the outlook: what a month costs once the horizon is reached.
     *
     * @param  list<array{id: int, effective_from: string, monthly_cost: int, months: list<string>}>  $baselines
     */
    protected function steadyMonthlyCost(array $baselines, string $lastMonth): int
    {
        foreach ($baselines as $baseline) {
            if (in_array($lastMonth, $baseline['months'], true)) {
                return $baseline['monthly_cost'];
            }
        }

        return 0;
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function withBalances(array $rows, int $openingBalance, bool $includeLowConfidence): array
    {
        $confirmed = $openingBalance;
        $base = $openingBalance;
        $pipeline = 0;
        $result = [];

        foreach ($rows as $row) {
            $netConfirmed = $row['receivables'] + $row['recurring'] + $row['planned_inflow'] - $row['cost'] - $row['planned_outflow'];
            $netBase = $netConfirmed + $row['recurring_assumed'] + ($includeLowConfidence ? $row['low_confidence'] : 0);

            $confirmed += $netConfirmed;
            $base += $netBase;
            $pipeline += $row['pipeline_weighted'];

            $items = $row['items'];
            unset($row['items']);

            $result[] = [
                ...$row,
                'net_confirmed' => $netConfirmed,
                'net_base' => $netBase,
                'balance_confirmed' => $confirmed,
                'balance_base' => $base,
                'balance_with_pipeline' => $base + $pipeline,
                'items' => $items,
            ];
        }

        return $result;
    }

    /**
     * The highest month-end base balance (the earliest month on a tie).
     *
     * @param  list<array<string, mixed>>  $monthRows
     * @return array{month: string, balance: int}
     */
    protected function peak(array $monthRows): array
    {
        $peak = $monthRows[0];

        foreach ($monthRows as $row) {
            if ($row['balance_base'] > $peak['balance_base']) {
                $peak = $row;
            }
        }

        return ['month' => $peak['month'], 'balance' => $peak['balance_base']];
    }

    /**
     * The first month from which the base net is negative in every month through the end of the outlook.
     *
     * @param  list<array<string, mixed>>  $monthRows
     */
    protected function firstDecliningMonth(array $monthRows): ?string
    {
        $month = null;

        foreach (array_reverse($monthRows) as $row) {
            if ($row['net_base'] >= 0) {
                break;
            }

            $month = $row['month'];
        }

        return $month;
    }

    /**
     * The first month-end below zero. When the outlook ends above zero it is continued in a straight line at
     * `$burnPerMonth`; null when it is not burning.
     *
     * @param  list<array<string, mixed>>  $monthRows
     * @return array{month: string, extrapolated: bool, months_of_runway_after_horizon: ?int}|null
     */
    protected function zeroMonth(array $monthRows, string $balanceKey, int $burnPerMonth): ?array
    {
        foreach ($monthRows as $row) {
            if ($row[$balanceKey] < 0) {
                return ['month' => $row['month'], 'extrapolated' => false, 'months_of_runway_after_horizon' => null];
            }
        }

        if ($burnPerMonth <= 0) {
            return null;
        }

        $last = $monthRows[array_key_last($monthRows)];
        $monthsAfter = intdiv($last[$balanceKey], $burnPerMonth) + 1;

        return [
            'month' => self::shiftMonth($last['month'], $monthsAfter),
            'extrapolated' => true,
            'months_of_runway_after_horizon' => $monthsAfter,
        ];
    }

    /**
     * Outstanding non-recurring receivables that hang on a project still being closed, earliest first, with what
     * the closing board projects for that project.
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function closingReceivables(array $rows, string $asOfMonth, int $delayMonths): array
    {
        $projects = $this->closingBoard->projects();
        $models = Receivable::query()
            ->with('customer')
            ->findMany($projects->pluck('receivables')->flatten(1)->pluck('id'))
            ->keyBy('id');

        $result = [];

        foreach ($projects as $project) {
            $projectAtRisk = $project['is_overdue']
                || $project['projection'] === ClosingBoard::PROJECTION_NOT_CONVERGING
                || ($project['days_late'] ?? 0) > 0;

            foreach ($project['receivables'] as $receivable) {
                $model = $models[$receivable['id']];

                if ($model->is_recurring) {
                    continue;
                }

                $month = max(substr($receivable['expected_on'], 0, 7), $asOfMonth);

                if ($model->confidence === Confidence::High) {
                    $month = self::shiftMonth($month, $delayMonths);
                }

                $result[] = [
                    'id' => $receivable['id'],
                    'label' => self::receivableLabel($model),
                    'item' => $receivable['item'],
                    'amount_taxed' => $receivable['amount_taxed'],
                    'expected_on' => $receivable['expected_on'],
                    'is_overdue' => $receivable['is_overdue'],
                    'confidence' => $model->confidence->value,
                    'month' => isset($rows[$month]) ? $month : null,
                    'at_risk' => $projectAtRisk
                        || ($project['projected_close_date'] !== null && $project['projected_close_date'] > $receivable['expected_on']),
                    'project' => [
                        'id' => $project['id'],
                        'name' => $project['name'],
                        'target_close_date' => $project['target_close_date'],
                        'days_left' => $project['days_left'],
                        'is_overdue' => $project['is_overdue'],
                        'open' => $project['open'],
                        'burn_from' => $project['burn_from'],
                        'burn_per_day' => $project['burn_per_day'],
                        'projection' => $project['projection'],
                        'projected_close_date' => $project['projected_close_date'],
                        'days_late' => $project['days_late'],
                    ],
                ];
            }
        }

        usort($result, fn (array $a, array $b): int => [$a['expected_on'], $a['id']] <=> [$b['expected_on'], $b['id']]);

        return $result;
    }

    protected static function shiftMonth(string $month, int $months): string
    {
        return CarbonImmutable::createFromFormat('!Y-m', $month)->addMonthsNoOverflow($months)->format('Y-m');
    }

    protected static function receivableLabel(Receivable $receivable): string
    {
        return trim(($receivable->customer?->short_name ?? '').' '.$receivable->item);
    }
}
