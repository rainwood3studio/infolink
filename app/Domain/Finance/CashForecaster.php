<?php

namespace App\Domain\Finance;

use App\Enums\Confidence;
use App\Enums\Source;
use App\Enums\TransactionCategory;
use App\Models\BankTransaction;
use App\Models\CashForecast;
use App\Models\CostBaseline;
use App\Models\PlannedCashFlow;
use App\Models\Receivable;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Month-end cash forecast, reproducing the method of the vault note 《2026 下半年現金推估》.
 *
 * Method (per month from the as_of month through `until`):
 * - Inflow: every HIGH-confidence outstanding (planned/invoiced) receivable at its tax-inclusive amount, in the
 *   month of `expected_on`. Recurring fees are receivables too (one row per month), so they flow in the same way.
 *   Receivables already overdue (expected_on ≤ as_of) are still expected and are counted in the as_of month.
 *   Low-confidence receivables are excluded and only listed in the assumptions.
 * - Outflow: the cost baseline effective in that month. For the as_of month only the part not yet paid is
 *   deducted: max(0, monthly_cost − regular withdrawals booked from the 1st to as_of), where regular excludes
 *   one-off lines and reimbursements.
 * - Planned cash flows (VAT top-ups, bonuses, …) with flow_on after as_of are added in their month
 *   (negative = outflow, positive = inflow).
 * - min_balance is taken over month-end rows only; the opening balance is not a candidate.
 */
class CashForecaster
{
    public function __construct(private ReceivableService $receivables) {}

    /**
     * @param  CarbonInterface|null  $asOf  Defaults to the date of the latest bank transaction when the opening balance is also defaulted, otherwise today.
     * @param  int|null  $openingBalance  Defaults to {@see ReceivableService::currentCashBalance()} as of `$asOf`.
     * @param  CarbonInterface|string|null  $until  Last date (or `Y-m` month) to forecast; defaults to the end of as_of's year.
     */
    public function calculate(?CarbonInterface $asOf = null, ?int $openingBalance = null, CarbonInterface|string|null $until = null): ForecastResult
    {
        $openingBalanceSource = 'given';

        if ($openingBalance === null) {
            $cash = $this->receivables->currentCashBalance($asOf);
            $asOf ??= $cash['as_of'] ?? null;
            $openingBalance = $cash['balance'] ?? 0;
            $openingBalanceSource = $cash === null ? 'none' : 'bank';
        }

        $asOf = CarbonImmutable::parse($asOf ?? today())->startOfDay();
        $until = $this->resolveUntil($asOf, $until);

        $months = $this->months($asOf, $until);
        $rows = array_fill_keys($months, ['inflow' => 0, 'outflow' => 0, 'items' => []]);
        $assumptions = [
            'method' => 'high-confidence outstanding receivables (taxed, by expected_on month; overdue in as_of month) + planned cash flows after as_of − cost baseline (as_of month: unpaid remainder only)',
            'opening_balance_source' => $openingBalanceSource,
        ];

        $assumptions['receivables'] = $this->addReceivables($rows, $asOf, $until);
        $assumptions['excluded_low_confidence'] = $this->lowConfidenceSummary($until);
        $assumptions['planned_cash_flows'] = $this->addPlannedCashFlows($rows, $asOf, $until);
        [$assumptions['cost_baselines'], $assumptions['as_of_month_cost']] = $this->addCosts($rows, $asOf);

        return $this->buildResult($asOf, $openingBalance, $rows, $assumptions);
    }

    /**
     * Calculate and persist an immutable snapshot.
     */
    public function snapshot(?CarbonInterface $asOf = null, ?int $openingBalance = null, CarbonInterface|string|null $until = null, Source $source = Source::System): CashForecast
    {
        $result = $this->calculate($asOf, $openingBalance, $until);

        return CashForecast::query()->create([
            ...$result->toArray(),
            'source' => $source,
        ]);
    }

    protected function resolveUntil(CarbonImmutable $asOf, CarbonInterface|string|null $until): CarbonImmutable
    {
        if ($until === null) {
            return $asOf->endOfYear()->startOfDay();
        }

        if (is_string($until) && preg_match('/^\d{4}-\d{2}$/', $until) === 1) {
            $resolved = CarbonImmutable::createFromFormat('!Y-m', $until)->endOfMonth()->startOfDay();
        } else {
            $resolved = CarbonImmutable::parse($until)->startOfDay();
        }

        if ($resolved->lt($asOf->startOfMonth())) {
            throw new InvalidArgumentException('Forecast end must not be before the as_of month.');
        }

        return $resolved;
    }

    /**
     * @return list<string>
     */
    protected function months(CarbonImmutable $asOf, CarbonImmutable $until): array
    {
        $months = [];

        for ($month = $asOf->startOfMonth(); $month->lte($until); $month = $month->addMonthNoOverflow()) {
            $months[] = $month->format('Y-m');
        }

        return $months;
    }

    /**
     * @param  array<string, array{inflow:int, outflow:int, items:list<array{label:string, amount:int}>}>  $rows
     * @return list<array{id:int, label:string, expected_on:string, month:string, amount_taxed:int, is_recurring:bool, overdue:bool}>
     */
    protected function addReceivables(array &$rows, CarbonImmutable $asOf, CarbonImmutable $until): array
    {
        $asOfMonth = $asOf->format('Y-m');
        $used = [];

        foreach ($this->outstanding(Confidence::High, $until) as $receivable) {
            $month = max($receivable->expected_on->format('Y-m'), $asOfMonth);
            $label = $this->receivableLabel($receivable);

            $rows[$month]['inflow'] += $receivable->amount_taxed;
            $rows[$month]['items'][] = ['label' => $label, 'amount' => $receivable->amount_taxed];

            $used[] = [
                'id' => $receivable->id,
                'label' => $label,
                'expected_on' => $receivable->expected_on->toDateString(),
                'month' => $month,
                'amount_taxed' => $receivable->amount_taxed,
                'is_recurring' => $receivable->is_recurring,
                'overdue' => $receivable->expected_on->lte($asOf),
            ];
        }

        return $used;
    }

    /**
     * @return array{count:int, amount_taxed:int, ids:list<int>}
     */
    protected function lowConfidenceSummary(CarbonImmutable $until): array
    {
        $low = $this->outstanding(Confidence::Low, $until);

        return [
            'count' => $low->count(),
            'amount_taxed' => (int) $low->sum('amount_taxed'),
            'ids' => $low->pluck('id')->all(),
        ];
    }

    /**
     * @return Collection<int, Receivable>
     */
    protected function outstanding(Confidence $confidence, CarbonImmutable $until): Collection
    {
        return Receivable::query()
            ->with('customer')
            ->outstanding()
            ->where('confidence', $confidence)
            ->whereDate('expected_on', '<=', $until)
            ->orderBy('expected_on')
            ->orderBy('id')
            ->get();
    }

    protected function receivableLabel(Receivable $receivable): string
    {
        return trim(($receivable->customer?->short_name ?? '').' '.$receivable->item);
    }

    /**
     * @param  array<string, array{inflow:int, outflow:int, items:list<array{label:string, amount:int}>}>  $rows
     * @return list<array{id:int, flow_on:string, amount:int, description:string}>
     */
    protected function addPlannedCashFlows(array &$rows, CarbonImmutable $asOf, CarbonImmutable $until): array
    {
        $flows = PlannedCashFlow::query()
            ->whereDate('flow_on', '>', $asOf)
            ->whereDate('flow_on', '<=', $until)
            ->orderBy('flow_on')
            ->orderBy('id')
            ->get();

        $used = [];

        foreach ($flows as $flow) {
            $month = $flow->flow_on->format('Y-m');

            if ($flow->amount >= 0) {
                $rows[$month]['inflow'] += $flow->amount;
            } else {
                $rows[$month]['outflow'] += -$flow->amount;
            }

            $rows[$month]['items'][] = ['label' => $flow->description, 'amount' => $flow->amount];
            $used[] = [
                'id' => $flow->id,
                'flow_on' => $flow->flow_on->toDateString(),
                'amount' => $flow->amount,
                'description' => $flow->description,
            ];
        }

        return $used;
    }

    /**
     * @param  array<string, array{inflow:int, outflow:int, items:list<array{label:string, amount:int}>}>  $rows
     * @return array{0:list<array{id:int, effective_from:string, monthly_cost:int, months:list<string>}>, 1:array{month:string, monthly_cost:int, booked_regular_outflow:int, deducted:int}}
     */
    protected function addCosts(array &$rows, CarbonImmutable $asOf): array
    {
        $baselines = CostBaseline::query()->orderBy('effective_from')->get();
        $used = [];
        $asOfMonthCost = null;

        foreach (array_keys($rows) as $month) {
            $monthEnd = CarbonImmutable::createFromFormat('!Y-m', $month)->endOfMonth();
            $baseline = $baselines->last(fn (CostBaseline $candidate): bool => $candidate->effective_from->lte($monthEnd));
            $monthlyCost = $baseline?->monthly_cost ?? 0;
            $cost = $monthlyCost;

            if ($month === $asOf->format('Y-m')) {
                $booked = $this->bookedRegularOutflow($asOf);
                $cost = max(0, $monthlyCost - $booked);
                $asOfMonthCost = [
                    'month' => $month,
                    'monthly_cost' => $monthlyCost,
                    'booked_regular_outflow' => $booked,
                    'deducted' => $cost,
                ];
            }

            if ($baseline !== null) {
                $used[$baseline->id] ??= [
                    'id' => $baseline->id,
                    'effective_from' => $baseline->effective_from->toDateString(),
                    'monthly_cost' => $baseline->monthly_cost,
                    'months' => [],
                ];
                $used[$baseline->id]['months'][] = $month;
            }

            if ($cost > 0) {
                $rows[$month]['outflow'] += $cost;
                $rows[$month]['items'][] = ['label' => '月成本', 'amount' => -$cost];
            }
        }

        return [array_values($used), $asOfMonthCost];
    }

    /**
     * Regular (not one-off, not reimbursement) withdrawals from the 1st of the as_of month through as_of, all accounts.
     */
    protected function bookedRegularOutflow(CarbonImmutable $asOf): int
    {
        return (int) BankTransaction::query()
            ->whereDate('txn_date', '>=', $asOf->startOfMonth())
            ->whereDate('txn_date', '<=', $asOf)
            ->where('is_one_off', false)
            ->where('category', '!=', TransactionCategory::Reimbursement)
            ->sum('withdrawal');
    }

    /**
     * @param  array<string, array{inflow:int, outflow:int, items:list<array{label:string, amount:int}>}>  $rows
     * @param  array<string, mixed>  $assumptions
     */
    protected function buildResult(CarbonImmutable $asOf, int $openingBalance, array $rows, array $assumptions): ForecastResult
    {
        $balance = $openingBalance;
        $resultRows = [];
        $minBalance = null;
        $minBalanceMonth = '';

        foreach ($rows as $month => $row) {
            $balance += $row['inflow'] - $row['outflow'];
            $resultRows[] = [
                'month' => $month,
                'inflow' => $row['inflow'],
                'outflow' => $row['outflow'],
                'balance' => $balance,
                'items' => $row['items'],
            ];

            if ($minBalance === null || $balance < $minBalance) {
                $minBalance = $balance;
                $minBalanceMonth = $month;
            }
        }

        return new ForecastResult(
            asOf: $asOf,
            openingBalance: $openingBalance,
            rows: $resultRows,
            minBalance: $minBalance ?? $openingBalance,
            minBalanceMonth: $minBalanceMonth,
            yearEndBalance: $balance,
            assumptions: $assumptions,
        );
    }
}
