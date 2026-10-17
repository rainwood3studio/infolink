<?php

namespace App\Domain\Finance;

use App\Enums\MaintenanceCycle;
use App\Enums\TransactionCategory;
use App\Models\BankTransaction;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * Read model for the 客戶收款 page: every customer's bank deposits by month, and — for customers with a maintenance
 * contract — whether each billing period's fee actually arrived.
 *
 * Definitions:
 * - A deposit is a bank line of category `revenue` with `deposit > 0`, tax-inclusive as received. It belongs to the
 *   customer of its linked receivable; without one, to the customer whose bank alias matches the counterparty
 *   ({@see CounterpartyMatcher}: exact, else prefix either way, longest alias wins). Deposits nobody matches are
 *   reported as `unmatched` so an alias can be added.
 * - Maintenance periods run from `maintenance_since`, one per month or per year, up to the current period (and not past
 *   `maintenance_until`). A deposit lands in the period its date falls in, except that one arriving within
 *   {@see self::GRACE_DAYS} of a period's start first tops the previous period up to the TAXED fee if that one is
 *   still short (墊腳石 pays the month's fee on the 1st–2nd of the next month).
 * - A period is `paid` when it received at least the UNTAXED fee less {@see self::FEE_TOLERANCE} (remittance fees are
 *   deducted by the payer's bank; bundled project money counts too — the bank does not say which invoice a deposit
 *   settles; see the page subheading), `short` when something smaller arrived, `missing` when nothing arrived and the
 *   grace days are over, `pending` while it is still open. A later catch-up payment does NOT back-fill an earlier
 *   period: it shows as a bigger bar in the month it arrived.
 * - `months` are zero-filled over the window so every customer's chart shares the same axis. `as_of` is the latest bank
 *   line of any kind (how fresh the statement is). Amounts are whole NTD.
 *
 * Aggregated in PHP so sqlite and Postgres behave the same (a few thousand lines at most).
 */
class CustomerCollections
{
    /** @var array<string, string> Window keys of the page filter, in display order. */
    public const array WINDOWS = [
        '12' => '近 12 個月',
        '18' => '近 18 個月',
        '24' => '近 24 個月',
        'all' => '全部',
    ];

    /** A year and a half: long enough to see a yearly fee twice and a monthly one through a full year of bank lines. */
    public const string DEFAULT_WINDOW = '18';

    /** Days after a period starts during which a deposit may still settle the previous period. */
    public const int GRACE_DAYS = 5;

    /** NTD a period may fall short of the untaxed fee and still count as paid (remittance fees the payer's bank deducts). */
    public const int FEE_TOLERANCE = 30;

    /** Assumed VAT when turning the untaxed fee into the amount the bank should show. */
    public const float TAX_RATE = 0.05;

    /**
     * @return array{
     *     window: string,
     *     as_of: ?string,
     *     has_bank_data: bool,
     *     months: list<string>,
     *     customers: list<array{
     *         id: int, name: string, short_name: string, status: string, status_label: string,
     *         matched_counterparties: list<string>, total: int, total_all_time: int, count: int,
     *         first_on: ?string, last_on: ?string, by_month: array<string, int>,
     *         maintenance: ?array{
     *             fee_untaxed: int, fee_taxed: int, cycle: string, cycle_label: string, per_label: string,
     *             since: string, until: ?string, is_ended: bool,
     *             periods: list<array{key: string, label: string, from: string, to: string, expected_untaxed: int, expected_taxed: int, received: int, status: string, status_label: string}>,
     *             paid: int, short: int, missing: int, pending: int, next_due: ?string,
     *         },
     *     }>,
     *     unmatched: list<array{counterparty: string, total: int, count: int, first_on: string, last_on: string}>,
     *     summary: array{customers: int, with_deposits: int, maintenance_customers: int, missing_periods: int, short_periods: int, matched_total: int, unmatched_total: int},
     * }
     */
    public function calculate(string $window = self::DEFAULT_WINDOW, ?CarbonImmutable $now = null): array
    {
        $today = ($now ?? CarbonImmutable::now(config('app.timezone')))->startOfDay();
        $window = array_key_exists($window, self::WINDOWS) ? $window : self::DEFAULT_WINDOW;

        $customers = Customer::query()->orderBy('short_name')->get();
        $lines = BankTransaction::query()
            ->with('receivable:id,customer_id')
            ->where('category', TransactionCategory::Revenue)
            ->where('deposit', '>', 0)
            ->orderBy('txn_date')
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        $deposits = array_fill_keys($customers->pluck('id')->all(), []);
        $unmatched = [];
        $matcher = new CounterpartyMatcher($customers);

        foreach ($lines as $line) {
            $customerId = $this->customerIdFor($line, $matcher);
            $deposit = ['date' => $line->txn_date->toDateString(), 'amount' => (int) $line->deposit, 'counterparty' => filled($line->counterparty) ? trim($line->counterparty) : null];

            if ($customerId !== null) {
                $deposits[$customerId][] = $deposit;
            } else {
                $key = $deposit['counterparty'] ?? CashFlowAnalytics::UNLABELLED;
                $unmatched[$key] ??= ['counterparty' => $key, 'total' => 0, 'count' => 0, 'first_on' => $deposit['date'], 'last_on' => $deposit['date']];
                $unmatched[$key]['total'] += $deposit['amount'];
                $unmatched[$key]['count']++;
                $unmatched[$key]['last_on'] = $deposit['date'];
            }
        }

        $months = $this->months($window, $lines->first()?->txn_date, $customers, $today);
        $firstMonth = $months[0] ?? $today->format('Y-m');

        $rows = $customers->map(function (Customer $customer) use ($deposits, $months, $firstMonth, $today): array {
            $own = $deposits[$customer->id];
            $inWindow = array_values(array_filter($own, fn (array $deposit): bool => substr($deposit['date'], 0, 7) >= $firstMonth));
            $byMonth = array_fill_keys($months, 0);

            foreach ($inWindow as $deposit) {
                $byMonth[substr($deposit['date'], 0, 7)] += $deposit['amount'];
            }

            return [
                'id' => $customer->id,
                'name' => $customer->name,
                'short_name' => $customer->short_name,
                'status' => $customer->status->value,
                'status_label' => $customer->status->getLabel(),
                'matched_counterparties' => array_values(array_unique(array_filter(array_column($own, 'counterparty')))),
                'total' => array_sum(array_column($inWindow, 'amount')),
                'total_all_time' => array_sum(array_column($own, 'amount')),
                'count' => count($inWindow),
                'first_on' => $own[0]['date'] ?? null,
                'last_on' => $own === [] ? null : $own[count($own) - 1]['date'],
                'by_month' => $byMonth,
                'maintenance' => $customer->hasMaintenanceContract() ? $this->maintenance($customer, $own, $today) : null,
            ];
        })->all();

        usort($rows, fn (array $a, array $b): int => [
            $b['maintenance'] !== null,
            ($b['maintenance']['missing'] ?? 0) + ($b['maintenance']['short'] ?? 0),
            $b['maintenance']['fee_untaxed'] ?? 0,
            $b['total'],
            $a['short_name'],
        ] <=> [
            $a['maintenance'] !== null,
            ($a['maintenance']['missing'] ?? 0) + ($a['maintenance']['short'] ?? 0),
            $a['maintenance']['fee_untaxed'] ?? 0,
            $a['total'],
            $b['short_name'],
        ]);

        $unmatched = array_values($unmatched);
        usort($unmatched, fn (array $a, array $b): int => [$b['total'], $a['counterparty']] <=> [$a['total'], $b['counterparty']]);

        $maintenanceRows = array_filter($rows, fn (array $row): bool => $row['maintenance'] !== null);
        $latestLine = BankTransaction::query()->max('txn_date');

        return [
            'window' => $window,
            'as_of' => $latestLine === null ? null : CarbonImmutable::parse($latestLine)->toDateString(),
            'has_bank_data' => $lines->isNotEmpty(),
            'months' => $months,
            'customers' => $rows,
            'unmatched' => $unmatched,
            'summary' => [
                'customers' => count($rows),
                'with_deposits' => count(array_filter($rows, fn (array $row): bool => $row['count'] > 0)),
                'maintenance_customers' => count($maintenanceRows),
                'missing_periods' => array_sum(array_map(fn (array $row): int => $row['maintenance']['missing'], $maintenanceRows)),
                'short_periods' => array_sum(array_map(fn (array $row): int => $row['maintenance']['short'], $maintenanceRows)),
                'matched_total' => array_sum(array_column($rows, 'total')),
                'unmatched_total' => array_sum(array_column($unmatched, 'total')),
            ],
        ];
    }

    /**
     * Expected and received amount per billing period of the customer's maintenance contract.
     *
     * @param  list<array{date: string, amount: int, counterparty: ?string}>  $deposits
     * @return array{
     *     fee_untaxed: int, fee_taxed: int, cycle: string, cycle_label: string, per_label: string, since: string, until: ?string, is_ended: bool,
     *     periods: list<array{key: string, label: string, from: string, to: string, expected_untaxed: int, expected_taxed: int, received: int, status: string, status_label: string}>,
     *     paid: int, short: int, missing: int, pending: int, next_due: ?string,
     * }
     */
    protected function maintenance(Customer $customer, array $deposits, CarbonImmutable $today): array
    {
        $cycle = $customer->maintenance_cycle;
        $fee = (int) $customer->maintenance_fee_untaxed;
        $feeTaxed = (int) round($fee * (1 + self::TAX_RATE));
        $since = CarbonImmutable::instance($customer->maintenance_since)->startOfDay();
        $until = $customer->maintenance_until === null ? null : CarbonImmutable::instance($customer->maintenance_until)->startOfDay();

        $periods = [];
        for ($start = $since; $start->lte($today) && ($until === null || $start->lte($until)); $start = $this->next($start, $cycle)) {
            $end = $this->next($start, $cycle)->subDay();
            $periods[] = ['from' => $start, 'to' => $until !== null && $end->gt($until) ? $until : $end, 'received' => 0];
        }

        foreach ($deposits as $deposit) {
            $date = CarbonImmutable::parse($deposit['date']);
            $index = $this->periodIndex($periods, $date);

            if ($index === null) {
                continue;
            }

            $amount = $deposit['amount'];

            if ($index > 0 && $date->lte($periods[$index]['from']->addDays(self::GRACE_DAYS)) && $periods[$index - 1]['received'] < $feeTaxed) {
                $topUp = min($amount, $feeTaxed - $periods[$index - 1]['received']);
                $periods[$index - 1]['received'] += $topUp;
                $amount -= $topUp;
            }

            $periods[$index]['received'] += $amount;
        }

        $counts = ['paid' => 0, 'short' => 0, 'missing' => 0, 'pending' => 0];
        $rows = [];

        foreach ($periods as $period) {
            $status = match (true) {
                $period['received'] >= $fee - self::FEE_TOLERANCE => 'paid',
                $period['received'] > 0 => 'short',
                $today->gt($period['to']->addDays(self::GRACE_DAYS)) => 'missing',
                default => 'pending',
            };
            $counts[$status]++;
            $rows[] = [
                'key' => $period['from']->toDateString(),
                'label' => $this->periodLabel($period['from'], $period['to'], $cycle),
                'from' => $period['from']->toDateString(),
                'to' => $period['to']->toDateString(),
                'expected_untaxed' => $fee,
                'expected_taxed' => $feeTaxed,
                'received' => $period['received'],
                'status' => $status,
                'status_label' => self::statusLabel($status),
            ];
        }

        $last = $periods === [] ? $since->subDay() : $periods[count($periods) - 1]['to'];
        $nextDue = $last->addDay();
        $isEnded = $until !== null && $until->lt($today);

        return [
            'fee_untaxed' => $fee,
            'fee_taxed' => $feeTaxed,
            'cycle' => $cycle->value,
            'cycle_label' => $cycle->getLabel(),
            'per_label' => $cycle->perLabel(),
            'since' => $since->toDateString(),
            'until' => $until?->toDateString(),
            'is_ended' => $isEnded,
            'periods' => $rows,
            ...$counts,
            'next_due' => $isEnded || ($until !== null && $nextDue->gt($until)) ? null : $nextDue->toDateString(),
        ];
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'paid' => '已入帳',
            'short' => '金額不足',
            'missing' => '未入帳',
            default => '本期尚未入帳',
        };
    }

    /**
     * The customer a deposit belongs to: the linked receivable's, else the best bank-alias match on the counterparty.
     */
    protected function customerIdFor(BankTransaction $line, CounterpartyMatcher $matcher): ?int
    {
        if ($line->receivable?->customer_id !== null) {
            return $line->receivable->customer_id;
        }

        return $matcher->match($line->counterparty)?->id;
    }

    /**
     * Zero-filled `YYYY-MM` labels of the window, oldest first, ending in the current month.
     *
     * @param  Collection<int, Customer>  $customers
     * @return list<string>
     */
    protected function months(string $window, ?CarbonInterface $firstLine, $customers, CarbonImmutable $today): array
    {
        $end = $today->startOfMonth();

        if ($window === 'all') {
            $candidates = array_filter([
                $firstLine === null ? null : CarbonImmutable::instance($firstLine)->startOfMonth(),
                ...$customers->map(fn (Customer $customer): ?CarbonImmutable => $customer->hasMaintenanceContract() ? CarbonImmutable::instance($customer->maintenance_since)->startOfMonth() : null)->all(),
            ]);
            $start = $candidates === [] ? $end->subMonths(11) : min($candidates);
        } else {
            $start = $end->subMonths((int) $window - 1);
        }

        return array_map(fn (CarbonImmutable $month): string => $month->format('Y-m'), iterator_to_array(CarbonPeriod::create($start, '1 month', $end)->setDateClass(CarbonImmutable::class), false));
    }

    protected function next(CarbonImmutable $start, MaintenanceCycle $cycle): CarbonImmutable
    {
        return $cycle === MaintenanceCycle::Monthly ? $start->addMonthNoOverflow() : $start->addYearNoOverflow();
    }

    /**
     * @param  list<array{from: CarbonImmutable, to: CarbonImmutable, received: int}>  $periods
     */
    protected function periodIndex(array $periods, CarbonImmutable $date): ?int
    {
        foreach ($periods as $index => $period) {
            if ($date->between($period['from'], $period['to'])) {
                return $index;
            }
        }

        return null;
    }

    protected function periodLabel(CarbonImmutable $from, CarbonImmutable $to, MaintenanceCycle $cycle): string
    {
        if ($cycle === MaintenanceCycle::Monthly && $from->day === 1) {
            return $from->format('Y/m');
        }

        return $from->format('Y/m/d').' – '.$to->format('Y/m/d');
    }
}
