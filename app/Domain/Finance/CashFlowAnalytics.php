<?php

namespace App\Domain\Finance;

use App\Enums\ReceivableStatus;
use App\Enums\TransactionCategory;
use App\Models\BankTransaction;
use App\Models\Receivable;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Read-only cash-flow analytics over line-level bank transactions (cash basis, whole NTD), for a date range.
 *
 * - The period is inclusive; `null` bounds mean "from the first / through the latest transaction". A month-only
 *   bound (`YYYY-MM`) expands to the month start / end.
 * - Months without any transaction line in the period are omitted, never zero-filled (older history exists only
 *   as monthly summary metrics, which carry no category split).
 * - Balances are the bank-printed balance after each line, summed across accounts (an account contributes from
 *   its first line onwards, as in CashBalanceTrendChart). Carry-forward and trailing averages look back before
 *   the period start, so the first period month is not distorted.
 * - "Regular" outflow excludes is_one_off lines and reimbursements (代墊), as in MonthlyFinanceMetrics:
 *   outflow = regular + one-off + reimbursement out.
 * - Revenue is Σ deposit of category revenue, tax-inclusive as received. Its customer is the line's counterparty,
 *   else the linked receivable's customer, else {@see self::UNLABELLED}.
 * - Shares are percentages (0–100, one decimal); HHI is on the 0–10,000 scale (Σ share² over customers).
 */
class CashFlowAnalytics
{
    public const array FIXED_COST_CATEGORIES = [
        TransactionCategory::Salary,
        TransactionCategory::Insurance,
        TransactionCategory::Tax,
        TransactionCategory::Subscription,
        TransactionCategory::Rent,
    ];

    public const int RUNWAY_TRAILING_MONTHS = 3;

    public const int LARGEST_LINES = 5;

    public const string UNLABELLED = '（未標示）';

    public const string OTHERS = '其他';

    /**
     * Every line through `to` in statement order, with the all-account balance after it.
     *
     * @var list<array{date: string, month: string, summary: string, counterparty: ?string, customer: string, deposit: int, withdrawal: int, category: TransactionCategory, is_one_off: bool, total_balance: int}>|null
     */
    private ?array $ledger = null;

    private ?string $latestDate = null;

    public function __construct(
        public readonly ?CarbonImmutable $from = null,
        public readonly ?CarbonImmutable $to = null,
    ) {
        if ($from !== null && $to !== null && $from->gt($to)) {
            throw new InvalidArgumentException('The period start must not be after its end.');
        }
    }

    /**
     * A period from dates (`YYYY-MM-DD`), months (`YYYY-MM`, expanded to the whole month) or Carbon instances.
     */
    public static function between(CarbonInterface|string|null $from = null, CarbonInterface|string|null $to = null): self
    {
        return new self(self::parseBound($from, end: false), self::parseBound($to, end: true));
    }

    /**
     * The last `$months` calendar months ending with the month of the latest transaction (all data when none).
     */
    public static function lastMonths(int $months): self
    {
        $latest = BankTransaction::query()->max('txn_date');

        if ($latest === null) {
            return new self;
        }

        $end = CarbonImmutable::parse($latest)->endOfMonth()->startOfDay();

        return new self($end->startOfMonth()->subMonthsNoOverflow(max(1, $months) - 1), $end);
    }

    public static function parseBound(CarbonInterface|string|null $value, bool $end): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::instance($value)->startOfDay();
        }

        if (preg_match('/^\d{4}-\d{2}$/', $value) === 1) {
            $month = CarbonImmutable::createFromFormat('!Y-m', $value);

            return $end ? $month->endOfMonth()->startOfDay() : $month;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new InvalidArgumentException("Invalid date [{$value}]: use YYYY-MM or YYYY-MM-DD.");
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $value);
    }

    public function hasData(): bool
    {
        return $this->periodLines() !== [];
    }

    /**
     * The requested bounds and the months that actually have line-level data.
     *
     * @return array{from: ?string, to: ?string, first_date: ?string, last_date: ?string, months: list<string>}
     */
    public function period(): array
    {
        $lines = $this->periodLines();

        return [
            'from' => $this->from?->toDateString(),
            'to' => $this->to?->toDateString(),
            'first_date' => $lines[0]['date'] ?? null,
            'last_date' => $lines === [] ? null : $lines[array_key_last($lines)]['date'],
            'months' => array_keys($this->aggregateMonths($lines)),
        ];
    }

    /**
     * Per month: inflow, outflow, net, regular / one-off / reimbursement outflow, reimbursement inflow, month-end
     * balance, and the lowest balance after any line in the month with its date. `is_partial` when the data (or the
     * period) ends before the month does.
     *
     * @return list<array{month: string, inflow: int, outflow: int, net: int, regular_outflow: int, one_off_outflow: int, reimbursement_out: int, reimbursement_in: int, month_end_balance: int, min_balance: int, min_balance_date: string, lines: int, is_partial: bool}>
     */
    public function monthlyFlows(): array
    {
        $cutoff = min($this->latestDate() ?? '9999-12-31', $this->to?->toDateString() ?? '9999-12-31');

        return array_values(array_map(fn (array $month): array => [
            ...$month,
            'is_partial' => CarbonImmutable::createFromFormat('!Y-m', $month['month'])->endOfMonth()->toDateString() > $cutoff,
        ], $this->aggregateMonths($this->periodLines())));
    }

    /**
     * Withdrawals per month × category (categories ordered by period total, zero-filled within months that have
     * data) and the period totals with their share of all outflow.
     *
     * @return array{total: int, categories: list<array{category: string, label: string, amount: int, share_pct: float}>, months: array<string, array<string, int>>}
     */
    public function outflowByCategory(): array
    {
        $lines = $this->periodLines();
        $totals = [];

        foreach ($lines as $line) {
            if ($line['withdrawal'] > 0) {
                $totals[$line['category']->value] = ($totals[$line['category']->value] ?? 0) + $line['withdrawal'];
            }
        }

        arsort($totals);
        $total = array_sum($totals);
        $months = array_map(fn (): array => array_fill_keys(array_keys($totals), 0), $this->aggregateMonths($lines));

        foreach ($lines as $line) {
            if ($line['withdrawal'] > 0) {
                $months[$line['month']][$line['category']->value] += $line['withdrawal'];
            }
        }

        return [
            'total' => $total,
            'categories' => array_map(fn (string $category, int $amount): array => [
                'category' => $category,
                'label' => TransactionCategory::from($category)->getLabel(),
                'amount' => $amount,
                'share_pct' => self::percent($amount, $total),
            ], array_keys($totals), $totals),
            'months' => $months,
        ];
    }

    /**
     * Revenue deposits per month × customer (zero-filled within months that have data), period totals with share,
     * the top `$top` customers plus 其他, and concentration (top-1 / top-3 share, HHI).
     *
     * @return array{total: int, customers: list<array{customer: string, amount: int, share_pct: float, months_paid: int}>, top: list<array{customer: string, amount: int, share_pct: float}>, months: array<string, array<string, int>>, concentration: array{customer_count: int, top1_customer: ?string, top1_share_pct: float, top3_share_pct: float, hhi: int}}
     */
    public function revenueByCustomer(int $top = 5): array
    {
        $lines = array_filter($this->periodLines(), fn (array $line): bool => $line['category'] === TransactionCategory::Revenue && $line['deposit'] > 0);
        $totals = [];
        $paidMonths = [];

        foreach ($lines as $line) {
            $totals[$line['customer']] = ($totals[$line['customer']] ?? 0) + $line['deposit'];
            $paidMonths[$line['customer']][$line['month']] = true;
        }

        uksort($totals, fn (string $a, string $b): int => [$totals[$b], $a] <=> [$totals[$a], $b]);
        $total = array_sum($totals);
        $months = array_map(fn (): array => array_fill_keys(array_keys($totals), 0), $this->aggregateMonths($this->periodLines()));

        foreach ($lines as $line) {
            $months[$line['month']][$line['customer']] += $line['deposit'];
        }

        $customers = array_map(fn (string $customer, int $amount): array => [
            'customer' => $customer,
            'amount' => $amount,
            'share_pct' => self::percent($amount, $total),
            'months_paid' => count($paidMonths[$customer]),
        ], array_keys($totals), $totals);

        $topCustomers = array_map(fn (array $row): array => array_diff_key($row, ['months_paid' => true]), array_slice($customers, 0, max(1, $top)));
        $rest = $total - array_sum(array_column($topCustomers, 'amount'));

        if ($rest > 0) {
            $topCustomers[] = ['customer' => self::OTHERS, 'amount' => $rest, 'share_pct' => self::percent($rest, $total)];
        }

        $amounts = array_values($totals);

        return [
            'total' => $total,
            'customers' => $customers,
            'top' => $topCustomers,
            'months' => $months,
            'concentration' => [
                'customer_count' => count($totals),
                'top1_customer' => array_key_first($totals),
                'top1_share_pct' => self::percent($amounts[0] ?? 0, $total),
                'top3_share_pct' => self::percent(array_sum(array_slice($amounts, 0, 3)), $total),
                'hhi' => $total === 0 ? 0 : (int) round(array_sum(array_map(fn (int $amount): float => ($amount / $total * 100) ** 2, $amounts))),
            ],
        ];
    }

    /**
     * Regular (non-one-off) withdrawals per month for salary, insurance (勞健保＋勞退), tax, subscription and rent,
     * plus the per-month average over the period.
     *
     * @return array{categories: array<string, string>, months: array<string, array<string, int>>, averages: array<string, int>}
     */
    public function fixedCostTrend(): array
    {
        $keys = array_map(fn (TransactionCategory $category): string => $category->value, self::FIXED_COST_CATEGORIES);
        $lines = $this->periodLines();
        $months = array_map(fn (): array => [...array_fill_keys($keys, 0), 'total' => 0], $this->aggregateMonths($lines));

        foreach ($lines as $line) {
            if (! $line['is_one_off'] && in_array($line['category'], self::FIXED_COST_CATEGORIES, true)) {
                $months[$line['month']][$line['category']->value] += $line['withdrawal'];
                $months[$line['month']]['total'] += $line['withdrawal'];
            }
        }

        $averages = [];

        foreach ([...$keys, 'total'] as $key) {
            $averages[$key] = $months === [] ? 0 : (int) round(array_sum(array_column($months, $key)) / count($months));
        }

        return [
            'categories' => array_combine($keys, array_map(fn (TransactionCategory $category): string => $category->getLabel(), self::FIXED_COST_CATEGORIES)),
            'months' => $months,
            'averages' => $averages,
        ];
    }

    /**
     * End-of-day balance (all accounts) for every day from `$from` to `$to` (default: the period, clamped to the
     * data), carried forward over days without lines. Days in months without any line are omitted.
     *
     * @return list<array{date: string, balance: int, deposit: int, withdrawal: int}>
     */
    public function dailyBalance(CarbonInterface|string|null $from = null, CarbonInterface|string|null $to = null): array
    {
        $ledger = $this->ledger();

        if ($ledger === []) {
            return [];
        }

        $from = self::parseBound($from, end: false) ?? $this->from;
        $to = self::parseBound($to, end: true) ?? $this->to;
        $start = max($ledger[0]['date'], $from?->toDateString() ?? '');
        $end = min($ledger[array_key_last($ledger)]['date'], $to?->toDateString() ?? '9999-12-31');
        $months = array_flip(array_column($ledger, 'month'));

        /** @var array<string, array{balance: int, deposit: int, withdrawal: int}> $days */
        $days = [];

        foreach ($ledger as $line) {
            $day = $days[$line['date']] ?? ['balance' => 0, 'deposit' => 0, 'withdrawal' => 0];
            $days[$line['date']] = [
                'balance' => $line['total_balance'],
                'deposit' => $day['deposit'] + $line['deposit'],
                'withdrawal' => $day['withdrawal'] + $line['withdrawal'],
            ];
        }

        $series = [];
        $balance = null;

        foreach ($ledger as $line) {
            if ($line['date'] >= $start) {
                break;
            }

            $balance = $line['total_balance'];
        }

        for ($date = CarbonImmutable::parse($start); $date->toDateString() <= $end; $date = $date->addDay()) {
            $key = $date->toDateString();
            $balance = $days[$key]['balance'] ?? $balance;

            if ($balance === null || ! isset($months[$date->format('Y-m')])) {
                continue;
            }

            $series[] = [
                'date' => $key,
                'balance' => $balance,
                'deposit' => $days[$key]['deposit'] ?? 0,
                'withdrawal' => $days[$key]['withdrawal'] ?? 0,
            ];
        }

        return $series;
    }

    /**
     * How receivables received in the period were paid against their expected date (days_late > 0 = late,
     * on time = received on or before expected_on), overall and by customer, plus the receivables overdue today.
     *
     * @return array{overall: array{count: int, on_time: int, on_time_rate_pct: ?float, avg_days_late: ?float, avg_delay_when_late: ?float, max_days_late: ?int, amount_taxed: int}, by_customer: list<array{customer: string, count: int, on_time: int, on_time_rate_pct: float, avg_days_late: float, max_days_late: int, amount_taxed: int}>, overdue: array{count: int, amount_taxed: int, items: list<array{customer: string, item: string, expected_on: string, days_overdue: int, amount_taxed: int}>}}
     */
    public function collectionPerformance(): array
    {
        $received = Receivable::query()
            ->with('customer:id,name,short_name')
            ->where('status', ReceivableStatus::Received)
            ->whereNotNull('received_on')
            ->when($this->from, fn ($query) => $query->whereDate('received_on', '>=', $this->from->toDateString()))
            ->when($this->to, fn ($query) => $query->whereDate('received_on', '<=', $this->to->toDateString()))
            ->orderBy('received_on')
            ->get()
            ->map(fn (Receivable $receivable): array => [
                'customer' => self::customerName($receivable),
                'days_late' => (int) round($receivable->expected_on->diffInDays($receivable->received_on, false)),
                'amount_taxed' => (int) $receivable->amount_taxed,
            ]);

        $byCustomer = $received
            ->groupBy('customer')
            ->map(fn ($rows): array => ['customer' => $rows->first()['customer'], ...$this->collectionStats($rows->values()->all())])
            ->sortBy([['count', 'desc'], ['customer', 'asc']])
            ->values()
            ->all();

        $overdue = Receivable::query()
            ->with('customer:id,name,short_name')
            ->overdue()
            ->orderBy('expected_on')
            ->get()
            ->map(fn (Receivable $receivable): array => [
                'customer' => self::customerName($receivable),
                'item' => (string) $receivable->item,
                'expected_on' => $receivable->expected_on->toDateString(),
                'days_overdue' => (int) round($receivable->expected_on->diffInDays(today())),
                'amount_taxed' => (int) $receivable->amount_taxed,
            ]);

        return [
            'overall' => $this->collectionStats($received->all()),
            'by_customer' => array_map(fn (array $row): array => array_diff_key($row, ['avg_delay_when_late' => true]), $byCustomer),
            'overdue' => [
                'count' => $overdue->count(),
                'amount_taxed' => (int) $overdue->sum('amount_taxed'),
                'items' => $overdue->all(),
            ],
        ];
    }

    /**
     * Month-end balance ÷ the average regular outflow of that month and the (up to) two previous months with data.
     *
     * @return list<array{month: string, month_end_balance: int, avg_regular_outflow: int, months_averaged: int, runway_months: ?float}>
     */
    public function runwayHistory(): array
    {
        $all = array_values($this->aggregateMonths($this->ledger()));
        $periodMonths = array_flip($this->period()['months']);
        $history = [];

        foreach ($all as $index => $month) {
            if (! isset($periodMonths[$month['month']])) {
                continue;
            }

            $window = array_slice($all, max(0, $index - self::RUNWAY_TRAILING_MONTHS + 1), min(self::RUNWAY_TRAILING_MONTHS, $index + 1));
            $average = (int) round(array_sum(array_column($window, 'regular_outflow')) / count($window));

            $history[] = [
                'month' => $month['month'],
                'month_end_balance' => $month['month_end_balance'],
                'avg_regular_outflow' => $average,
                'months_averaged' => count($window),
                'runway_months' => $average > 0 ? round($month['month_end_balance'] / $average, 2) : null,
            ];
        }

        return $history;
    }

    /**
     * 代墊: money advanced (reimbursement withdrawals) vs repaid (reimbursement deposits). Cumulative figures run
     * from the first transaction, so `outstanding` is what is still owed back as of each month end / the period end.
     *
     * @return array{period_out: int, period_in: int, total_out: int, total_in: int, outstanding: int, months: list<array{month: string, out: int, in: int, cumulative_out: int, cumulative_in: int, outstanding: int}>}
     */
    public function reimbursementBalance(): array
    {
        $periodMonths = array_flip($this->period()['months']);
        $cumulativeOut = 0;
        $cumulativeIn = 0;
        $rows = [];

        foreach ($this->aggregateMonths($this->ledger()) as $month) {
            $cumulativeOut += $month['reimbursement_out'];
            $cumulativeIn += $month['reimbursement_in'];

            if (isset($periodMonths[$month['month']])) {
                $rows[] = [
                    'month' => $month['month'],
                    'out' => $month['reimbursement_out'],
                    'in' => $month['reimbursement_in'],
                    'cumulative_out' => $cumulativeOut,
                    'cumulative_in' => $cumulativeIn,
                    'outstanding' => $cumulativeOut - $cumulativeIn,
                ];
            }
        }

        $periodFlows = $this->aggregateMonths($this->periodLines());

        return [
            'period_out' => array_sum(array_column($periodFlows, 'reimbursement_out')),
            'period_in' => array_sum(array_column($periodFlows, 'reimbursement_in')),
            'total_out' => $cumulativeOut,
            'total_in' => $cumulativeIn,
            'outstanding' => $cumulativeOut - $cumulativeIn,
            'months' => $rows,
        ];
    }

    /**
     * Tax-category payments per month (0 in months with data but no payment), with the individual lines.
     *
     * @return array{total: int, months: list<array{month: string, amount: int, lines: list<array{date: string, summary: string, amount: int}>}>}
     */
    public function vatPayments(): array
    {
        $lines = $this->periodLines();
        $months = array_map(fn (array $month): array => ['month' => $month['month'], 'amount' => 0, 'lines' => []], $this->aggregateMonths($lines));

        foreach ($lines as $line) {
            if ($line['category'] === TransactionCategory::Tax && $line['withdrawal'] > 0) {
                $months[$line['month']]['amount'] += $line['withdrawal'];
                $months[$line['month']]['lines'][] = ['date' => $line['date'], 'summary' => $line['summary'], 'amount' => $line['withdrawal']];
            }
        }

        return ['total' => array_sum(array_column($months, 'amount')), 'months' => array_values($months)];
    }

    /**
     * Headline numbers for the period (or for `$from`–`$to` when given).
     *
     * @return array{period: array{from: ?string, to: ?string, first_date: ?string, last_date: ?string, months: list<string>}, months_with_data: int, total_inflow: int, total_outflow: int, net: int, revenue: int, avg_monthly_inflow: int, avg_monthly_regular_outflow: int, one_off_outflow: int, opening_balance: ?int, closing_balance: ?int, min_balance: ?int, min_balance_date: ?string, revenue_concentration: array{customer_count: int, top1_customer: ?string, top1_share_pct: float, top3_share_pct: float, hhi: int}, reimbursement_outstanding: int, largest_inflows: list<array<string, mixed>>, largest_outflows: list<array<string, mixed>>}
     */
    public function summary(CarbonInterface|string|null $from = null, CarbonInterface|string|null $to = null): array
    {
        if ($from !== null || $to !== null) {
            return self::between($from ?? $this->from, $to ?? $this->to)->summary();
        }

        $lines = $this->periodLines();
        $months = $this->aggregateMonths($lines);
        $count = count($months);
        $revenue = $this->revenueByCustomer();
        $minimum = $months === [] ? null : array_reduce($months, fn (?array $carry, array $month): array => $carry === null || $month['min_balance'] < $carry['min_balance'] ? $month : $carry);

        return [
            'period' => $this->period(),
            'months_with_data' => $count,
            'total_inflow' => $inflow = array_sum(array_column($months, 'inflow')),
            'total_outflow' => $outflow = array_sum(array_column($months, 'outflow')),
            'net' => $inflow - $outflow,
            'revenue' => $revenue['total'],
            'avg_monthly_inflow' => $count === 0 ? 0 : (int) round($inflow / $count),
            'avg_monthly_regular_outflow' => $count === 0 ? 0 : (int) round(array_sum(array_column($months, 'regular_outflow')) / $count),
            'one_off_outflow' => array_sum(array_column($months, 'one_off_outflow')),
            'opening_balance' => $this->openingBalance(),
            'closing_balance' => $lines === [] ? null : $lines[array_key_last($lines)]['total_balance'],
            'min_balance' => $minimum['min_balance'] ?? null,
            'min_balance_date' => $minimum['min_balance_date'] ?? null,
            'revenue_concentration' => $revenue['concentration'],
            'reimbursement_outstanding' => $this->reimbursementBalance()['outstanding'],
            'largest_inflows' => $this->largestLines('deposit'),
            'largest_outflows' => $this->largestLines('withdrawal'),
        ];
    }

    /**
     * The biggest single lines of the period by deposit or withdrawal.
     *
     * @param  'deposit'|'withdrawal'  $side
     * @return list<array{date: string, summary: string, counterparty: ?string, category: string, category_label: string, is_one_off: bool, amount: int}>
     */
    public function largestLines(string $side, int $limit = self::LARGEST_LINES): array
    {
        $lines = array_filter($this->periodLines(), fn (array $line): bool => $line[$side] > 0);
        usort($lines, fn (array $a, array $b): int => [$b[$side], $a['date']] <=> [$a[$side], $b['date']]);

        return array_map(fn (array $line): array => [
            'date' => $line['date'],
            'summary' => $line['summary'],
            'counterparty' => $line['counterparty'],
            'category' => $line['category']->value,
            'category_label' => $line['category']->getLabel(),
            'is_one_off' => $line['is_one_off'],
            'amount' => $line[$side],
        ], array_slice($lines, 0, $limit));
    }

    /**
     * @param  list<array{date: string, month: string, deposit: int, withdrawal: int, category: TransactionCategory, is_one_off: bool, total_balance: int}>  $lines
     * @return array<string, array{month: string, inflow: int, outflow: int, net: int, regular_outflow: int, one_off_outflow: int, reimbursement_out: int, reimbursement_in: int, month_end_balance: int, min_balance: int, min_balance_date: string, lines: int}>
     */
    protected function aggregateMonths(array $lines): array
    {
        $months = [];

        foreach ($lines as $line) {
            $month = $months[$line['month']] ?? [
                'month' => $line['month'],
                'inflow' => 0,
                'outflow' => 0,
                'net' => 0,
                'regular_outflow' => 0,
                'one_off_outflow' => 0,
                'reimbursement_out' => 0,
                'reimbursement_in' => 0,
                'month_end_balance' => $line['total_balance'],
                'min_balance' => $line['total_balance'],
                'min_balance_date' => $line['date'],
                'lines' => 0,
            ];

            $isReimbursement = $line['category'] === TransactionCategory::Reimbursement;
            $month['inflow'] += $line['deposit'];
            $month['outflow'] += $line['withdrawal'];
            $month['net'] = $month['inflow'] - $month['outflow'];
            $month['reimbursement_in'] += $isReimbursement ? $line['deposit'] : 0;

            match (true) {
                $isReimbursement => $month['reimbursement_out'] += $line['withdrawal'],
                $line['is_one_off'] => $month['one_off_outflow'] += $line['withdrawal'],
                default => $month['regular_outflow'] += $line['withdrawal'],
            };

            $month['month_end_balance'] = $line['total_balance'];
            $month['lines']++;

            if ($line['total_balance'] < $month['min_balance']) {
                $month['min_balance'] = $line['total_balance'];
                $month['min_balance_date'] = $line['date'];
            }

            $months[$line['month']] = $month;
        }

        return $months;
    }

    /**
     * @param  list<array{customer: string, days_late: int, amount_taxed: int}>  $rows
     * @return array{count: int, on_time: int, on_time_rate_pct: ?float, avg_days_late: ?float, avg_delay_when_late: ?float, max_days_late: ?int, amount_taxed: int}
     */
    protected function collectionStats(array $rows): array
    {
        $delays = array_column($rows, 'days_late');
        $late = array_filter($delays, fn (int $days): bool => $days > 0);
        $onTime = count($delays) - count($late);

        return [
            'count' => count($delays),
            'on_time' => $onTime,
            'on_time_rate_pct' => $delays === [] ? null : self::percent($onTime, count($delays)),
            'avg_days_late' => $delays === [] ? null : round(array_sum($delays) / count($delays), 1),
            'avg_delay_when_late' => $late === [] ? null : round(array_sum($late) / count($late), 1),
            'max_days_late' => $delays === [] ? null : max($delays),
            'amount_taxed' => array_sum(array_column($rows, 'amount_taxed')),
        ];
    }

    protected function openingBalance(): ?int
    {
        $start = $this->periodLines()[0]['date'] ?? null;
        $balance = null;

        foreach ($this->ledger() as $line) {
            if ($start === null || $line['date'] >= $start) {
                break;
            }

            $balance = $line['total_balance'];
        }

        return $balance;
    }

    /**
     * @return list<array{date: string, month: string, summary: string, counterparty: ?string, customer: string, deposit: int, withdrawal: int, category: TransactionCategory, is_one_off: bool, total_balance: int}>
     */
    protected function periodLines(): array
    {
        $from = $this->from?->toDateString();

        return array_values(array_filter($this->ledger(), fn (array $line): bool => $from === null || $line['date'] >= $from));
    }

    /**
     * @return list<array{date: string, month: string, summary: string, counterparty: ?string, customer: string, deposit: int, withdrawal: int, category: TransactionCategory, is_one_off: bool, total_balance: int}>
     */
    protected function ledger(): array
    {
        if ($this->ledger !== null) {
            return $this->ledger;
        }

        $balances = [];
        $this->ledger = [];

        $transactions = BankTransaction::query()
            ->with('receivable.customer:id,name,short_name')
            ->when($this->to, fn ($query) => $query->whereDate('txn_date', '<=', $this->to->toDateString()))
            ->orderBy('txn_date')
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        foreach ($transactions as $transaction) {
            $balances[$transaction->bank_account_id] = $transaction->balance;
            $counterparty = filled($transaction->counterparty) ? trim($transaction->counterparty) : null;

            $this->ledger[] = [
                'date' => $transaction->txn_date->toDateString(),
                'month' => $transaction->txn_date->format('Y-m'),
                'summary' => $transaction->summary,
                'counterparty' => $counterparty,
                'customer' => $counterparty ?? ($transaction->receivable ? self::customerName($transaction->receivable) : self::UNLABELLED),
                'deposit' => $transaction->deposit,
                'withdrawal' => $transaction->withdrawal,
                'category' => $transaction->category ?? TransactionCategory::Other,
                'is_one_off' => $transaction->is_one_off,
                'total_balance' => array_sum($balances),
            ];
        }

        return $this->ledger;
    }

    protected function latestDate(): ?string
    {
        if ($this->latestDate === null) {
            $latest = BankTransaction::query()->max('txn_date');
            $this->latestDate = $latest === null ? null : CarbonImmutable::parse($latest)->toDateString();
        }

        return $this->latestDate;
    }

    protected static function customerName(Receivable $receivable): string
    {
        return $receivable->customer?->short_name ?: ($receivable->customer?->name ?: self::UNLABELLED);
    }

    protected static function percent(int|float $part, int|float $whole): float
    {
        return $whole == 0 ? 0.0 : round($part / $whole * 100, 1);
    }
}
