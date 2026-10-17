<?php

namespace App\Domain\Sales;

use App\Enums\DealStage;
use App\Enums\MaintenanceCycle;
use App\Enums\ProjectStatus;
use App\Enums\ReceivableStatus;
use App\Enums\TransactionCategory;
use App\Models\BankTransaction;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\RedmineIssue;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * Read model for the 客戶總覽 page and the `customer_overview` MCP tool: everything the company knows about one
 * customer on a single screen — maintenance contract, projects with their money, open receivables, bank deposits,
 * open deals, open Redmine issues and a list of things to fix.
 *
 * Definitions:
 * - Money is untaxed NTD unless a key says `taxed`; `fee_taxed` assumes {@see self::TAX_RATE} VAT.
 * - `maintenance` is null unless {@see Customer::hasMaintenanceContract()}. `days_until_end` is signed (negative =
 *   ended); `is_expiring_soon` = ends within {@see self::EXPIRING_DAYS} days from today (today included); `is_ended` =
 *   `maintenance_until` is before today. `annualised_fee_untaxed` is the yearly fee or 12 × the monthly fee.
 * - `projects`: every project of the customer with the roll-up of its non-cancelled receivables — `scheduled` (all of
 *   them), `received` (status received), `outstanding` (planned or invoiced) and `overdue` (outstanding with
 *   `expected_on` before today). `open_issues` is the open issue count of the linked Redmine project, null when none
 *   is linked.
 * - `receivables.items` lists the outstanding NON-recurring receivables (recurring fees have their own section),
 *   oldest expected first, with `days` = signed days until `expected_on` (negative = overdue). The totals cover ALL
 *   outstanding receivables, recurring included, so they match the 未收應收 column of the customer list.
 * - `recurring`: the customer's last {@see self::RECURRING_ROWS} recurring receivables, newest first.
 * - `deposits` are bank lines of category `revenue` with `deposit > 0` (tax-inclusive as received). A line belongs to
 *   the customer of its linked receivable; without one, to the customer whose bank alias ({@see Customer::bankAliases()})
 *   best matches the counterparty — exactly, or one being a prefix of the other, the longest alias winning, aliases
 *   shorter than {@see self::MIN_PREFIX_LENGTH} never prefix-matching (the same rule as 客戶收款, evaluated against
 *   EVERY customer so a deposit is never counted for two of them). `by_month` is zero-filled over the last
 *   {@see self::MONTHS} months ending this month.
 * - `deals`: the customer's open deals (lead / proposal / negotiation), next action first.
 * - `open_issues`: open Redmine issues across the customer's `redmine_project_ids` and its projects'
 *   `redmine_project_id`, de-duplicated by Redmine project; `redmine` lists them per Redmine project.
 * - `flags` (Chinese, for the page badges and the MCP caller): a non-closed project without a contract amount, a
 *   maintenance contract ending within {@see self::EXPIRING_DAYS} days or already ended, overdue receivables, and
 *   「入帳對不到戶名」 when money was marked received in the last {@see self::MONTHS} months but no bank deposit
 *   matched the customer in that time although revenue lines exist — the bank probably prints a name nobody told
 *   us about yet.
 *
 * Aggregated in PHP so sqlite and Postgres behave the same (one customer's rows at most).
 */
class CustomerOverview
{
    /** Months of deposit history on the page. */
    public const int MONTHS = 12;

    /** A maintenance contract ending within this many days is 「即將到期」. */
    public const int EXPIRING_DAYS = 45;

    /** Recurring receivables listed. */
    public const int RECURRING_ROWS = 6;

    /** Assumed VAT when turning an untaxed fee into what the bank should show. */
    public const float TAX_RATE = 0.05;

    /** Aliases shorter than this never prefix-match, so 「我」 does not swallow every counterparty. */
    protected const int MIN_PREFIX_LENGTH = 2;

    /**
     * @return array{
     *     id: int, name: string, short_name: string, status: string, status_label: string,
     *     counterparties: list<string>, bank_aliases: list<string>, redmine_project_ids: list<int>, as_of: string,
     *     maintenance: ?array{fee_untaxed: int, fee_taxed: int, cycle: string, cycle_label: string, per_label: string, since: string, until: ?string, days_until_end: ?int, is_ended: bool, is_expiring_soon: bool, annualised_fee_untaxed: int},
     *     projects: list<array{id: int, name: string, status: string, status_label: string, contract_amount_untaxed: ?int, target_close_date: ?string, redmine_project_id: ?int, open_issues: ?int, receivables: array{scheduled_untaxed: int, received_untaxed: int, outstanding_untaxed: int, overdue_untaxed: int, outstanding_taxed: int}}>,
     *     receivables: array{items: list<array{id: int, item: string, project_id: ?int, project: ?string, amount_untaxed: int, amount_taxed: int, expected_on: string, status: string, status_label: string, confidence: string, invoiced_on: ?string, is_overdue: bool, days: int}>, outstanding_count: int, outstanding_untaxed: int, outstanding_taxed: int, overdue_count: int, overdue_untaxed: int, overdue_taxed: int},
     *     recurring: list<array{id: int, item: string, amount_untaxed: int, amount_taxed: int, expected_on: string, status: string, status_label: string, received_on: ?string, is_overdue: bool}>,
     *     deposits: array{total_12m: int, count_12m: int, total_all_time: int, last_on: ?string, last_amount: ?int, by_month: array<string, int>, matched_counterparties: list<string>},
     *     deals: list<array{id: int, title: string, stage: string, stage_label: string, amount_untaxed: ?int, probability: int, weighted_amount: int, expected_close_on: ?string, next_action: ?string, next_action_on: ?string}>,
     *     open_issues: int,
     *     redmine: list<array{project_id: int, identifier: ?string, name: ?string, open: int}>,
     *     flags: list<string>,
     * }
     */
    public function calculate(Customer $customer, ?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::now(config('app.timezone')))->startOfDay();
        $months = $this->months($today);

        $projects = $customer->projects()->orderBy('name')->get();
        $receivables = Receivable::query()
            ->where('customer_id', $customer->id)
            ->where('status', '!=', ReceivableStatus::Cancelled)
            ->with('project:id,name')
            ->orderBy('expected_on')
            ->orderBy('id')
            ->get();

        $redmine = $this->redmine($customer, $projects);
        $deposits = $this->deposits($customer, $months, $today);
        $maintenance = $this->maintenance($customer, $today);
        $projectRows = $this->projects($projects, $receivables, $redmine['by_project'], $today);
        $receivableRows = $this->receivables($receivables, $today);

        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'short_name' => $customer->short_name,
            'status' => $customer->status->value,
            'status_label' => $customer->status->getLabel(),
            'counterparties' => array_values($customer->counterparties ?? []),
            'bank_aliases' => $customer->bankAliases(),
            'redmine_project_ids' => array_values(array_map(intval(...), $customer->redmine_project_ids ?? [])),
            'as_of' => $today->toDateString(),
            'maintenance' => $maintenance,
            'projects' => $projectRows,
            'receivables' => $receivableRows,
            'recurring' => $this->recurring($receivables, $today),
            'deposits' => $deposits,
            'deals' => $this->deals($customer),
            'open_issues' => (int) array_sum(array_column($redmine['rows'], 'open')),
            'redmine' => $redmine['rows'],
            'flags' => $this->flags($projectRows, $maintenance, $receivableRows, $deposits, $receivables, $today),
        ];
    }

    /**
     * @return ?array{fee_untaxed: int, fee_taxed: int, cycle: string, cycle_label: string, per_label: string, since: string, until: ?string, days_until_end: ?int, is_ended: bool, is_expiring_soon: bool, annualised_fee_untaxed: int}
     */
    protected function maintenance(Customer $customer, CarbonImmutable $today): ?array
    {
        if (! $customer->hasMaintenanceContract()) {
            return null;
        }

        $fee = (int) $customer->maintenance_fee_untaxed;
        $cycle = $customer->maintenance_cycle;
        $until = $customer->maintenance_until === null ? null : CarbonImmutable::instance($customer->maintenance_until)->startOfDay();
        $daysLeft = $customer->maintenanceDaysLeft($today);

        return [
            'fee_untaxed' => $fee,
            'fee_taxed' => (int) round($fee * (1 + self::TAX_RATE)),
            'cycle' => $cycle->value,
            'cycle_label' => $cycle->getLabel(),
            'per_label' => $cycle->perLabel(),
            'since' => CarbonImmutable::instance($customer->maintenance_since)->toDateString(),
            'until' => $until?->toDateString(),
            'days_until_end' => $daysLeft,
            'is_ended' => $daysLeft !== null && $daysLeft < 0,
            'is_expiring_soon' => $daysLeft !== null && $daysLeft >= 0 && $daysLeft <= self::EXPIRING_DAYS,
            'annualised_fee_untaxed' => $cycle === MaintenanceCycle::Monthly ? $fee * 12 : $fee,
        ];
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @param  Collection<int, Receivable>  $receivables
     * @param  array<int, int>  $openByRedmineProject
     * @return list<array{id: int, name: string, status: string, status_label: string, contract_amount_untaxed: ?int, target_close_date: ?string, redmine_project_id: ?int, open_issues: ?int, receivables: array{scheduled_untaxed: int, received_untaxed: int, outstanding_untaxed: int, overdue_untaxed: int, outstanding_taxed: int}}>
     */
    protected function projects(Collection $projects, Collection $receivables, array $openByRedmineProject, CarbonImmutable $today): array
    {
        $order = [ProjectStatus::Active->value => 0, ProjectStatus::Closing->value => 1, ProjectStatus::Closed->value => 2];

        return $projects
            ->sortBy(fn (Project $project): array => [$order[$project->status->value] ?? 9, $project->name])
            ->map(function (Project $project) use ($receivables, $openByRedmineProject, $today): array {
                $rollup = ['scheduled_untaxed' => 0, 'received_untaxed' => 0, 'outstanding_untaxed' => 0, 'overdue_untaxed' => 0, 'outstanding_taxed' => 0];

                foreach ($receivables->where('project_id', $project->id) as $receivable) {
                    $rollup['scheduled_untaxed'] += $receivable->amount_untaxed;

                    if ($receivable->status === ReceivableStatus::Received) {
                        $rollup['received_untaxed'] += $receivable->amount_untaxed;

                        continue;
                    }

                    $rollup['outstanding_untaxed'] += $receivable->amount_untaxed;
                    $rollup['outstanding_taxed'] += $receivable->amount_taxed;

                    if ($receivable->expected_on->lt($today)) {
                        $rollup['overdue_untaxed'] += $receivable->amount_untaxed;
                    }
                }

                return [
                    'id' => $project->id,
                    'name' => $project->name,
                    'status' => $project->status->value,
                    'status_label' => $project->status->getLabel(),
                    'contract_amount_untaxed' => $project->contract_amount_untaxed,
                    'target_close_date' => $project->target_close_date?->toDateString(),
                    'redmine_project_id' => $project->redmine_project_id,
                    'open_issues' => $project->redmine_project_id === null ? null : ($openByRedmineProject[$project->redmine_project_id] ?? 0),
                    'receivables' => $rollup,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Receivable>  $receivables
     * @return array{items: list<array{id: int, item: string, project_id: ?int, project: ?string, amount_untaxed: int, amount_taxed: int, expected_on: string, status: string, status_label: string, confidence: string, invoiced_on: ?string, is_overdue: bool, days: int}>, outstanding_count: int, outstanding_untaxed: int, outstanding_taxed: int, overdue_count: int, overdue_untaxed: int, overdue_taxed: int}
     */
    protected function receivables(Collection $receivables, CarbonImmutable $today): array
    {
        $outstanding = $receivables->filter(fn (Receivable $receivable): bool => in_array($receivable->status, [ReceivableStatus::Planned, ReceivableStatus::Invoiced], true));
        $overdue = $outstanding->filter(fn (Receivable $receivable): bool => $receivable->expected_on->lt($today));

        return [
            'items' => $outstanding
                ->reject(fn (Receivable $receivable): bool => $receivable->is_recurring)
                ->map(fn (Receivable $receivable): array => [
                    'id' => $receivable->id,
                    'item' => $receivable->item,
                    'project_id' => $receivable->project_id,
                    'project' => $receivable->project?->name,
                    'amount_untaxed' => $receivable->amount_untaxed,
                    'amount_taxed' => $receivable->amount_taxed,
                    'expected_on' => $receivable->expected_on->toDateString(),
                    'status' => $receivable->status->value,
                    'status_label' => $receivable->status->getLabel(),
                    'confidence' => $receivable->confidence->value,
                    'invoiced_on' => $receivable->invoiced_on?->toDateString(),
                    'is_overdue' => $receivable->expected_on->lt($today),
                    'days' => (int) $today->diffInDays(CarbonImmutable::instance($receivable->expected_on)->startOfDay(), false),
                ])
                ->values()
                ->all(),
            'outstanding_count' => $outstanding->count(),
            'outstanding_untaxed' => (int) $outstanding->sum('amount_untaxed'),
            'outstanding_taxed' => (int) $outstanding->sum('amount_taxed'),
            'overdue_count' => $overdue->count(),
            'overdue_untaxed' => (int) $overdue->sum('amount_untaxed'),
            'overdue_taxed' => (int) $overdue->sum('amount_taxed'),
        ];
    }

    /**
     * @param  Collection<int, Receivable>  $receivables
     * @return list<array{id: int, item: string, amount_untaxed: int, amount_taxed: int, expected_on: string, status: string, status_label: string, received_on: ?string, is_overdue: bool}>
     */
    protected function recurring(Collection $receivables, CarbonImmutable $today): array
    {
        return $receivables
            ->filter(fn (Receivable $receivable): bool => $receivable->is_recurring)
            ->sortByDesc(fn (Receivable $receivable): array => [$receivable->expected_on->timestamp, $receivable->id])
            ->take(self::RECURRING_ROWS)
            ->map(fn (Receivable $receivable): array => [
                'id' => $receivable->id,
                'item' => $receivable->item,
                'amount_untaxed' => $receivable->amount_untaxed,
                'amount_taxed' => $receivable->amount_taxed,
                'expected_on' => $receivable->expected_on->toDateString(),
                'status' => $receivable->status->value,
                'status_label' => $receivable->status->getLabel(),
                'received_on' => $receivable->received_on?->toDateString(),
                'is_overdue' => in_array($receivable->status, [ReceivableStatus::Planned, ReceivableStatus::Invoiced], true) && $receivable->expected_on->lt($today),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $months
     * @return array{total_12m: int, count_12m: int, total_all_time: int, last_on: ?string, last_amount: ?int, by_month: array<string, int>, matched_counterparties: list<string>}
     */
    protected function deposits(Customer $customer, array $months, CarbonImmutable $today): array
    {
        $customers = Customer::query()->get();
        $lines = BankTransaction::query()
            ->with('receivable:id,customer_id')
            ->where('category', TransactionCategory::Revenue)
            ->where('deposit', '>', 0)
            ->orderBy('txn_date')
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        $byMonth = array_fill_keys($months, 0);
        $firstMonth = $months[0];
        $total = 0;
        $totalWindow = 0;
        $countWindow = 0;
        $last = null;
        $counterparties = [];

        foreach ($lines as $line) {
            if ($this->customerIdFor($line, $customers) !== $customer->id) {
                continue;
            }

            $amount = (int) $line->deposit;
            $month = $line->txn_date->format('Y-m');
            $total += $amount;
            $last = ['date' => $line->txn_date->toDateString(), 'amount' => $amount];

            if (filled($line->counterparty)) {
                $counterparties[trim($line->counterparty)] = true;
            }

            if ($month >= $firstMonth) {
                $totalWindow += $amount;
                $countWindow++;
                $byMonth[$month] = ($byMonth[$month] ?? 0) + $amount;
            }
        }

        return [
            'total_12m' => $totalWindow,
            'count_12m' => $countWindow,
            'total_all_time' => $total,
            'last_on' => $last['date'] ?? null,
            'last_amount' => $last['amount'] ?? null,
            'by_month' => $byMonth,
            'matched_counterparties' => array_keys($counterparties),
        ];
    }

    /**
     * @return list<array{id: int, title: string, stage: string, stage_label: string, amount_untaxed: ?int, probability: int, weighted_amount: int, expected_close_on: ?string, next_action: ?string, next_action_on: ?string}>
     */
    protected function deals(Customer $customer): array
    {
        return Deal::query()
            ->where('customer_id', $customer->id)
            ->whereIn('stage', DealStage::open())
            ->orderByRaw('case when next_action_on is null then 1 else 0 end')
            ->orderBy('next_action_on')
            ->orderBy('id')
            ->get()
            ->map(fn (Deal $deal): array => [
                'id' => $deal->id,
                'title' => $deal->title,
                'stage' => $deal->stage->value,
                'stage_label' => $deal->stage->getLabel(),
                'amount_untaxed' => $deal->amount_untaxed,
                'probability' => (int) $deal->probability,
                'weighted_amount' => $deal->weighted_amount,
                'expected_close_on' => $deal->expected_close_on?->toDateString(),
                'next_action' => $deal->next_action,
                'next_action_on' => $deal->next_action_on?->toDateString(),
            ])
            ->all();
    }

    /**
     * Open Redmine issues per Redmine project the customer is tied to (its own ids and its projects' links, de-duplicated).
     *
     * @param  Collection<int, Project>  $projects
     * @return array{rows: list<array{project_id: int, identifier: ?string, name: ?string, open: int}>, by_project: array<int, int>}
     */
    protected function redmine(Customer $customer, Collection $projects): array
    {
        $ids = array_values(array_unique(array_filter(array_map(intval(...), [
            ...($customer->redmine_project_ids ?? []),
            ...$projects->pluck('redmine_project_id')->filter()->all(),
        ]))));

        if ($ids === []) {
            return ['rows' => [], 'by_project' => []];
        }

        $open = RedmineIssue::query()
            ->open()
            ->whereIn('project_id', $ids)
            ->selectRaw('project_id, count(*) as aggregate')
            ->groupBy('project_id')
            ->pluck('aggregate', 'project_id')
            ->map(fn ($count): int => (int) $count)
            ->all();

        $names = RedmineIssue::withTrashed()
            ->whereIn('project_id', $ids)
            ->orderByDesc('updated_on')
            ->get(['project_id', 'project_identifier', 'project_name'])
            ->unique('project_id')
            ->keyBy('project_id');

        $rows = array_map(fn (int $id): array => [
            'project_id' => $id,
            'identifier' => $names[$id]->project_identifier ?? null,
            'name' => $names[$id]->project_name ?? null,
            'open' => $open[$id] ?? 0,
        ], $ids);

        usort($rows, fn (array $a, array $b): int => [$b['open'], $a['project_id']] <=> [$a['open'], $b['project_id']]);

        return ['rows' => $rows, 'by_project' => $open];
    }

    /**
     * @param  list<array{name: string, status: string, contract_amount_untaxed: ?int}>  $projects
     * @param  ?array{days_until_end: ?int, is_ended: bool, is_expiring_soon: bool}  $maintenance
     * @param  array{overdue_taxed: int}  $receivables
     * @param  array{total_12m: int}  $deposits
     * @param  Collection<int, Receivable>  $allReceivables
     * @return list<string>
     */
    protected function flags(array $projects, ?array $maintenance, array $receivables, array $deposits, Collection $allReceivables, CarbonImmutable $today): array
    {
        $flags = [];

        if ($maintenance !== null && $maintenance['is_ended']) {
            $flags[] = '維護合約已到期 '.abs($maintenance['days_until_end']).' 天';
        } elseif ($maintenance !== null && $maintenance['is_expiring_soon']) {
            $flags[] = $maintenance['days_until_end'] === 0 ? '維護合約今天到期' : "維護合約 {$maintenance['days_until_end']} 天後到期";
        }

        if ($receivables['overdue_taxed'] > 0) {
            $flags[] = '逾期應收 NT$'.number_format($receivables['overdue_taxed']).'（含稅）';
        }

        foreach ($projects as $project) {
            if ($project['contract_amount_untaxed'] === null && $project['status'] !== ProjectStatus::Closed->value) {
                $flags[] = "合約金額未填：{$project['name']}";
            }
        }

        $windowStart = $today->subMonths(self::MONTHS - 1)->startOfMonth();
        $receivedRecently = $allReceivables->contains(fn (Receivable $receivable): bool => $receivable->status === ReceivableStatus::Received
            && CarbonImmutable::instance($receivable->received_on ?? $receivable->expected_on)->gte($windowStart));

        if ($receivedRecently && $deposits['total_12m'] === 0 && $this->hasRevenueLinesSince($windowStart)) {
            $flags[] = '入帳對不到戶名';
        }

        return $flags;
    }

    protected function hasRevenueLinesSince(CarbonImmutable $from): bool
    {
        return BankTransaction::query()
            ->where('category', TransactionCategory::Revenue)
            ->where('deposit', '>', 0)
            ->whereDate('txn_date', '>=', $from->toDateString())
            ->exists();
    }

    /**
     * The customer a deposit belongs to: the linked receivable's, else the best bank-alias match on the counterparty
     * across every customer (the 客戶收款 rule).
     *
     * @param  Collection<int, Customer>  $customers
     */
    protected function customerIdFor(BankTransaction $line, Collection $customers): ?int
    {
        if ($line->receivable?->customer_id !== null) {
            return $line->receivable->customer_id;
        }

        $counterparty = filled($line->counterparty) ? trim($line->counterparty) : null;

        if ($counterparty === null) {
            return null;
        }

        $best = null;
        $bestScore = 0;

        foreach ($customers as $customer) {
            foreach ($customer->bankAliases() as $alias) {
                $score = self::aliasScore($alias, $counterparty);

                if ($score > $bestScore) {
                    [$best, $bestScore] = [$customer->id, $score];
                }
            }
        }

        return $best;
    }

    /**
     * 0 for no match; an exact match outranks any prefix match, and a longer alias outranks a shorter one.
     */
    protected static function aliasScore(string $alias, string $counterparty): int
    {
        if ($alias === $counterparty) {
            return 1_000 + mb_strlen($alias);
        }

        if (mb_strlen($alias) < self::MIN_PREFIX_LENGTH || mb_strlen($counterparty) < self::MIN_PREFIX_LENGTH) {
            return 0;
        }

        return str_starts_with($counterparty, $alias) || str_starts_with($alias, $counterparty) ? min(mb_strlen($alias), mb_strlen($counterparty)) : 0;
    }

    /**
     * Zero-filled `YYYY-MM` labels of the last {@see self::MONTHS} months, oldest first, ending in the current month.
     *
     * @return list<string>
     */
    protected function months(CarbonImmutable $today): array
    {
        $end = $today->startOfMonth();
        $period = CarbonPeriod::create($end->subMonths(self::MONTHS - 1), '1 month', $end)->setDateClass(CarbonImmutable::class);

        return array_map(fn (CarbonImmutable $month): string => $month->format('Y-m'), iterator_to_array($period, false));
    }
}
