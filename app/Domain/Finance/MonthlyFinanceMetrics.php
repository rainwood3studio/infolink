<?php

namespace App\Domain\Finance;

use App\Domain\Alerts\Rules\VatReserveRule;
use App\Domain\Metrics\MetricRecorder;
use App\Enums\ReceivableStatus;
use App\Enums\Source;
use App\Enums\TransactionCategory;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\MetricValue;
use App\Models\Receivable;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Monthly cash-basis finance metrics, computed from line-level bank transactions (period_start = the 1st).
 *
 * - revenue.received: Σ deposit of category revenue (tax-inclusive, as received).
 * - cash.monthly_cost: Σ withdrawal excluding is_one_off lines and reimbursements ("regular outflow").
 * - cost.personnel_ratio: regular salary + insurance withdrawals ÷ regular outflow (0–1). 勞退 is booked in the
 *   insurance category together with 勞保/健保. Skipped when the regular outflow is 0.
 * - revenue.recurring_monthly: Σ amount_taxed of is_recurring receivables expected in the month, received or not
 *   (cancelled excluded). Skipped when the month has no recurring receivables at all.
 * - company.net_cashflow_ytd: previous month's YTD + this month's Σ deposit − Σ withdrawal over every category
 *   (reimbursements and one-offs included, so it equals month-end balance − prior year-end balance). January
 *   starts from 0; any other month needs the previous month's value, otherwise it is skipped.
 *
 * A month is only recorded when it has at least one transaction (no fake zeros). It is "partial" when no
 * transaction is dated after the month end yet; its values are then flagged 'partial month' in the notes and
 * will be overwritten by later runs. tax.vat_reserve is not recorded here: {@see VatReserveRule}
 * keeps its computation private and treats any recorded value as a manual override that beats its own estimate.
 */
class MonthlyFinanceMetrics
{
    public function __construct(protected MetricRecorder $recorder) {}

    /**
     * Record the metrics of the month containing `$month`.
     *
     * @return array<string, int|float> The recorded values by metric key; empty when the month has no transactions.
     */
    public function record(CarbonInterface $month, Source $source = Source::System): array
    {
        $start = CarbonImmutable::instance($month)->startOfMonth();
        $end = $start->endOfMonth()->startOfDay();
        $transactions = fn (): Builder => BankTransaction::query()
            ->whereDate('txn_date', '>=', $start)
            ->whereDate('txn_date', '<=', $end);

        if (! $transactions()->exists()) {
            return [];
        }

        $regular = fn (): Builder => $transactions()
            ->where('is_one_off', false)
            ->where('category', '!=', TransactionCategory::Reimbursement);

        $monthlyCost = (int) $regular()->sum('withdrawal');
        $values = [
            'revenue.received' => (int) $transactions()->where('category', TransactionCategory::Revenue)->sum('deposit'),
            'cash.monthly_cost' => $monthlyCost,
        ];

        if ($monthlyCost > 0) {
            $personnel = (int) $regular()->whereIn('category', [TransactionCategory::Salary, TransactionCategory::Insurance])->sum('withdrawal');
            $values['cost.personnel_ratio'] = round($personnel / $monthlyCost, 4);
        }

        $recurring = Receivable::query()
            ->where('is_recurring', true)
            ->where('status', '!=', ReceivableStatus::Cancelled)
            ->whereDate('expected_on', '>=', $start)
            ->whereDate('expected_on', '<=', $end);

        if ($recurring->clone()->exists()) {
            $values['revenue.recurring_monthly'] = (int) $recurring->sum('amount_taxed');
        }

        $ytd = $this->netCashflowYtd($start, (int) $transactions()->sum('deposit') - (int) $transactions()->sum('withdrawal'));

        if ($ytd !== null) {
            $values['company.net_cashflow_ytd'] = $ytd;
        }

        $notes = $this->isComplete($end) ? null : sprintf('partial month (data through %s)', $this->latestTransactionDate()?->toDateString());

        $this->recorder->recordMany(array_map(fn (string $key, int|float $value): array => [
            'key' => $key,
            'value' => $value,
            'period_start' => $start,
            'notes' => $notes,
        ], array_keys($values), $values), $source);

        return $values;
    }

    /**
     * Record every month from `$from` (default: the first transaction month) through the latest transaction month.
     *
     * @return array<string, array<string, int|float>> Recorded values by month (Y-m); months without data are absent.
     */
    public function recordRange(?CarbonInterface $from = null, Source $source = Source::System): array
    {
        $first = BankTransaction::query()->min('txn_date');
        $last = $this->latestTransactionDate();

        if ($first === null || $last === null) {
            return [];
        }

        $recorded = [];
        $month = CarbonImmutable::instance($from ?? CarbonImmutable::parse($first))->startOfMonth();

        for (; $month->lte($last); $month = $month->addMonthNoOverflow()) {
            $values = $this->record($month, $source);

            if ($values !== []) {
                $recorded[$month->format('Y-m')] = $values;
            }
        }

        return $recorded;
    }

    /**
     * Seed company.net_cashflow_ytd for months before line-level coverage from the vault's monthly bank summary
     * (shape: {account_name, rows: [{month: Y-m, deposit, withdrawal, net, closing_balance}]}).
     *
     * Only the running sum of `deposit − withdrawal` is used (the summary has no category split, so it cannot give
     * monthly cost or revenue). A year is only seeded from its January onwards with no gaps, only for months before
     * the first transaction, and only when the summary covers the sole bank account with transactions and its
     * closing balance for the month before the first transaction equals that transaction's opening balance.
     *
     * @param  array{account_name?: string, rows?: list<array{month: string, deposit: int, withdrawal: int, closing_balance?: int}>}  $summary
     * @return array{recorded: array<string, int>, skipped: ?string}
     */
    public function recordSummaryYtd(array $summary, ?string $vaultRef = null): array
    {
        $first = BankTransaction::query()->orderBy('txn_date')->orderBy('sequence')->orderBy('id')->first();

        if ($first === null) {
            return ['recorded' => [], 'skipped' => 'no bank transactions'];
        }

        $accountIds = BankTransaction::query()->distinct()->pluck('bank_account_id');
        $account = BankAccount::query()->find($first->bank_account_id);

        if ($accountIds->count() !== 1 || $account?->name !== ($summary['account_name'] ?? null)) {
            return ['recorded' => [], 'skipped' => 'summary does not cover exactly the account(s) with transactions'];
        }

        $firstMonth = $first->txn_date->format('Y-m');
        $rows = collect($summary['rows'] ?? [])->keyBy('month');
        $previousMonth = CarbonImmutable::parse($first->txn_date)->startOfMonth()->subMonthNoOverflow()->format('Y-m');
        $openingBalance = $first->balance - $first->deposit + $first->withdrawal;

        if (($rows[$previousMonth]['closing_balance'] ?? null) !== $openingBalance) {
            return ['recorded' => [], 'skipped' => sprintf('summary closing balance for %s does not match the opening balance %d of the first transaction', $previousMonth, $openingBalance)];
        }

        $recorded = [];
        $entries = [];
        $ytd = null;

        foreach ($rows->sortKeys() as $month => $row) {
            if ($month >= $firstMonth) {
                break;
            }

            $date = CarbonImmutable::createFromFormat('!Y-m', $month);

            if ($date->month === 1) {
                $ytd = 0;
            } elseif ($ytd === null || ! isset($recorded[$date->subMonthNoOverflow()->format('Y-m')])) {
                $ytd = null;

                continue;
            }

            $ytd += (int) $row['deposit'] - (int) $row['withdrawal'];
            $recorded[$month] = $ytd;
            $entries[] = [
                'key' => 'company.net_cashflow_ytd',
                'value' => $ytd,
                'period_start' => $date,
                'vault_ref' => $vaultRef,
                'notes' => 'from the monthly bank summary (deposit − withdrawal totals)',
            ];
        }

        $this->recorder->recordMany($entries, Source::Vault);

        return ['recorded' => $recorded, 'skipped' => null];
    }

    /**
     * Whether transactions have been imported past the given month end.
     */
    public function isComplete(CarbonInterface $monthEnd): bool
    {
        return BankTransaction::query()->whereDate('txn_date', '>', $monthEnd)->exists();
    }

    protected function netCashflowYtd(CarbonImmutable $start, int $net): ?int
    {
        if ($start->month === 1) {
            return $net;
        }

        $previous = MetricValue::query()
            ->where('metric_key', 'company.net_cashflow_ytd')
            ->where('dimension', '')
            ->whereDate('period_start', $start->subMonthNoOverflow()->toDateString())
            ->value('value');

        return $previous === null ? null : (int) round((float) $previous) + $net;
    }

    protected function latestTransactionDate(): ?CarbonImmutable
    {
        $latest = BankTransaction::query()->max('txn_date');

        return $latest === null ? null : CarbonImmutable::parse($latest)->startOfDay();
    }
}
