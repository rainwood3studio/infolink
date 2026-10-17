<?php

namespace App\Domain\Finance;

use App\Enums\MaintenanceCycle;
use App\Enums\ProjectStatus;
use App\Enums\ReceivableStatus;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Receivable;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Read model for the 請款進度 page: how far each project's contract has been turned into receivables, invoices and
 * money in the bank, which invoices should have gone out by now, and whether every maintenance period has its fee row.
 *
 * Definitions (amounts are whole NTD, UNTAXED unless a key says `taxed`):
 * - A project's receivables are its NON-recurring, non-cancelled ones (maintenance fees are tracked under `recurring`).
 *   `scheduled` sums them all, `received` those with status `received`, `invoiced_outstanding` those invoiced but not
 *   yet paid, `planned` those not yet invoiced. `unscheduled` = contract − scheduled when the project has a contract
 *   amount (negative = more receivables than contract, flagged `is_over_scheduled`); null without one.
 * - `overdue`: outstanding (planned or invoiced) receivables whose `expected_on` is before today.
 *   `due_uninvoiced`: planned receivables with no `invoiced_on` expected within {@see self::DUE_SOON_DAYS} days — the
 *   invoice has to be issued now for the money to arrive on time. A project `has_problems` when either count is
 *   non-zero or it is over-scheduled.
 * - `without_project`: non-recurring receivables not tied to any project, grouped by customer.
 * - `recurring`: for every customer with a maintenance contract ({@see Customer::hasMaintenanceContract()}), the
 *   billing periods from {@see self::PERIODS_BEFORE} before the current one to {@see self::PERIODS_AFTER} after it
 *   (clipped to the contract dates), each with the recurring receivable whose `expected_on` falls inside the period:
 *   `received`, `invoiced`, `planned` (row exists, no invoice yet) or `missing` (no row at all).
 * - Projects are ordered closing first, then those with problems, then by contract amount descending.
 * - `summary` adds the projects up: contract total, scheduled, received, outstanding (invoiced + planned),
 *   unscheduled, overdue, due_uninvoiced and the names of projects with no contract amount (their `unscheduled` is
 *   unknown, so the total understates it).
 *
 * Aggregated in PHP so sqlite and Postgres behave the same (a few hundred receivables at most).
 */
class BillingProgress
{
    /** A planned receivable expected within this many days should already have its invoice issued. */
    public const int DUE_SOON_DAYS = 7;

    /** Maintenance periods shown before the current one. */
    public const int PERIODS_BEFORE = 2;

    /** Maintenance periods shown after the current one. */
    public const int PERIODS_AFTER = 2;

    /**
     * @return array{
     *     today: string,
     *     projects: list<array{
     *         id: int, name: string, customer: ?string, customer_id: ?int, status: string, status_label: string,
     *         contract_amount_untaxed: ?int, scheduled: int, received: int, invoiced_outstanding: int, planned: int,
     *         unscheduled: ?int, is_over_scheduled: bool,
     *         overdue: array{amount: int, count: int}, due_uninvoiced: array{amount: int, count: int},
     *         has_problems: bool, receivables: list<array<string, mixed>>,
     *     }>,
     *     without_project: list<array{customer_id: int, customer: string, scheduled: int, received: int, outstanding: int, overdue: array{amount: int, count: int}, due_uninvoiced: array{amount: int, count: int}, receivables: list<array<string, mixed>>}>,
     *     recurring: list<array{
     *         customer_id: int, customer: string, fee_untaxed: int, cycle: string, cycle_label: string, per_label: string, since: string, until: ?string,
     *         periods: list<array{key: string, label: string, from: string, to: string, is_current: bool, is_future: bool, status: string, status_label: string, receivable: ?array<string, mixed>}>,
     *         received: int, invoiced: int, planned: int, missing: int,
     *     }>,
     *     summary: array{
     *         projects: int, contract_total: int, scheduled: int, received: int, outstanding: int, invoiced_outstanding: int, planned: int, unscheduled: int,
     *         overdue: array{amount: int, count: int}, due_uninvoiced: array{amount: int, count: int},
     *         over_scheduled: list<string>, missing_contract: list<string>, recurring_missing: int, recurring_planned: int,
     *     },
     * }
     */
    public function calculate(?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::now(config('app.timezone')))->startOfDay();

        $receivables = Receivable::query()
            ->with('customer:id,short_name,name')
            ->where('status', '!=', ReceivableStatus::Cancelled)
            ->orderBy('expected_on')
            ->orderBy('id')
            ->get();

        $oneOff = $receivables->where('is_recurring', false);
        $byProject = $oneOff->whereNotNull('project_id')->groupBy('project_id');

        $projects = Project::query()->with('customer:id,short_name,name')->orderBy('name')->get()
            ->map(fn (Project $project): array => $this->project($project, $byProject->get($project->id, collect()), $today))
            ->all();

        usort($projects, fn (array $a, array $b): int => [
            $b['status'] === ProjectStatus::Closing->value,
            $b['has_problems'],
            $b['contract_amount_untaxed'] ?? -1,
            $a['name'],
        ] <=> [
            $a['status'] === ProjectStatus::Closing->value,
            $a['has_problems'],
            $a['contract_amount_untaxed'] ?? -1,
            $b['name'],
        ]);

        $withoutProject = $oneOff->whereNull('project_id')->groupBy('customer_id')
            ->map(fn (Collection $rows): array => $this->withoutProject($rows, $today))
            ->sortByDesc('outstanding')
            ->values()
            ->all();

        $recurring = Customer::query()->orderBy('short_name')->get()
            ->filter(fn (Customer $customer): bool => $customer->hasMaintenanceContract())
            ->map(fn (Customer $customer): array => $this->recurring($customer, $receivables->where('is_recurring', true)->where('customer_id', $customer->id), $today))
            ->values()
            ->all();

        $withContract = array_filter($projects, fn (array $project): bool => $project['contract_amount_untaxed'] !== null);

        return [
            'today' => $today->toDateString(),
            'projects' => $projects,
            'without_project' => $withoutProject,
            'recurring' => $recurring,
            'summary' => [
                'projects' => count($projects),
                'contract_total' => array_sum(array_column($withContract, 'contract_amount_untaxed')),
                'scheduled' => array_sum(array_column($projects, 'scheduled')),
                'received' => array_sum(array_column($projects, 'received')),
                'outstanding' => array_sum(array_column($projects, 'invoiced_outstanding')) + array_sum(array_column($projects, 'planned')),
                'invoiced_outstanding' => array_sum(array_column($projects, 'invoiced_outstanding')),
                'planned' => array_sum(array_column($projects, 'planned')),
                'unscheduled' => array_sum(array_column($withContract, 'unscheduled')),
                'overdue' => self::sumCounts(array_column($projects, 'overdue')),
                'due_uninvoiced' => self::sumCounts(array_column($projects, 'due_uninvoiced')),
                'over_scheduled' => array_values(array_column(array_filter($projects, fn (array $project): bool => $project['is_over_scheduled']), 'name')),
                'missing_contract' => array_values(array_column(array_filter($projects, fn (array $project): bool => $project['contract_amount_untaxed'] === null), 'name')),
                'recurring_missing' => array_sum(array_column($recurring, 'missing')),
                'recurring_planned' => array_sum(array_column($recurring, 'planned')),
            ],
        ];
    }

    /**
     * @param  Collection<int, Receivable>  $rows  The project's non-recurring, non-cancelled receivables.
     * @return array<string, mixed>
     */
    protected function project(Project $project, Collection $rows, CarbonImmutable $today): array
    {
        $sums = $this->sums($rows, $today);
        $contract = $project->contract_amount_untaxed;
        $unscheduled = $contract === null ? null : $contract - $sums['scheduled'];

        return [
            'id' => $project->id,
            'name' => $project->name,
            'customer' => $project->customer?->short_name ?: $project->customer?->name,
            'customer_id' => $project->customer_id,
            'status' => $project->status->value,
            'status_label' => $project->status->getLabel(),
            'contract_amount_untaxed' => $contract,
            ...$sums,
            'unscheduled' => $unscheduled,
            'is_over_scheduled' => $unscheduled !== null && $unscheduled < 0,
            'has_problems' => $sums['overdue']['count'] > 0 || $sums['due_uninvoiced']['count'] > 0 || ($unscheduled !== null && $unscheduled < 0),
            'receivables' => $rows->map(fn (Receivable $receivable): array => $this->row($receivable, $today))->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, Receivable>  $rows  One customer's non-recurring receivables without a project.
     * @return array<string, mixed>
     */
    protected function withoutProject(Collection $rows, CarbonImmutable $today): array
    {
        $sums = $this->sums($rows, $today);
        $customer = $rows->first()->customer;

        return [
            'customer_id' => (int) $rows->first()->customer_id,
            'customer' => $customer?->short_name ?: ($customer?->name ?? '—'),
            'scheduled' => $sums['scheduled'],
            'received' => $sums['received'],
            'outstanding' => $sums['invoiced_outstanding'] + $sums['planned'],
            'overdue' => $sums['overdue'],
            'due_uninvoiced' => $sums['due_uninvoiced'],
            'receivables' => $rows->map(fn (Receivable $receivable): array => $this->row($receivable, $today))->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, Receivable>  $rows
     * @return array{scheduled: int, received: int, invoiced_outstanding: int, planned: int, overdue: array{amount: int, count: int}, due_uninvoiced: array{amount: int, count: int}}
     */
    protected function sums(Collection $rows, CarbonImmutable $today): array
    {
        $overdue = $rows->filter(fn (Receivable $receivable): bool => self::isOverdue($receivable, $today));
        $dueUninvoiced = $rows->filter(fn (Receivable $receivable): bool => self::isDueUninvoiced($receivable, $today));

        return [
            'scheduled' => (int) $rows->sum('amount_untaxed'),
            'received' => (int) $rows->where('status', ReceivableStatus::Received)->sum('amount_untaxed'),
            'invoiced_outstanding' => (int) $rows->where('status', ReceivableStatus::Invoiced)->sum('amount_untaxed'),
            'planned' => (int) $rows->where('status', ReceivableStatus::Planned)->sum('amount_untaxed'),
            'overdue' => ['amount' => (int) $overdue->sum('amount_untaxed'), 'count' => $overdue->count()],
            'due_uninvoiced' => ['amount' => (int) $dueUninvoiced->sum('amount_untaxed'), 'count' => $dueUninvoiced->count()],
        ];
    }

    /**
     * @return array{id: int, item: string, untaxed: int, taxed: int, expected_on: string, status: string, status_label: string, confidence: string, invoiced_on: ?string, invoice_number: ?string, received_on: ?string, is_overdue: bool, is_due_uninvoiced: bool, days_overdue: int}
     */
    protected function row(Receivable $receivable, CarbonImmutable $today): array
    {
        $isOverdue = self::isOverdue($receivable, $today);

        return [
            'id' => $receivable->id,
            'item' => $receivable->item,
            'untaxed' => $receivable->amount_untaxed,
            'taxed' => $receivable->amount_taxed,
            'expected_on' => $receivable->expected_on->toDateString(),
            'status' => $receivable->status->value,
            'status_label' => $receivable->status->getLabel(),
            'confidence' => $receivable->confidence->value,
            'invoiced_on' => $receivable->invoiced_on?->toDateString(),
            'invoice_number' => $receivable->invoice_number,
            'received_on' => $receivable->received_on?->toDateString(),
            'is_overdue' => $isOverdue,
            'is_due_uninvoiced' => self::isDueUninvoiced($receivable, $today),
            'days_overdue' => $isOverdue ? (int) CarbonImmutable::instance($receivable->expected_on)->diffInDays($today) : 0,
        ];
    }

    /**
     * The maintenance periods around today and the recurring receivable row each one has (or lacks).
     *
     * @param  Collection<int, Receivable>  $rows  The customer's recurring, non-cancelled receivables.
     * @return array<string, mixed>
     */
    protected function recurring(Customer $customer, Collection $rows, CarbonImmutable $today): array
    {
        $cycle = $customer->maintenance_cycle;
        $since = CarbonImmutable::instance($customer->maintenance_since)->startOfDay();
        $until = $customer->maintenance_until === null ? null : CarbonImmutable::instance($customer->maintenance_until)->startOfDay();

        $current = $since;
        while ($this->next($current, $cycle)->lte($today)) {
            $current = $this->next($current, $cycle);
        }

        $start = $current;
        for ($i = 0; $i < self::PERIODS_BEFORE && $start->gt($since); $i++) {
            $start = $cycle === MaintenanceCycle::Monthly ? $start->subMonthNoOverflow() : $start->subYearNoOverflow();
        }

        $periods = [];
        $counts = ['received' => 0, 'invoiced' => 0, 'planned' => 0, 'missing' => 0];

        for ($from = $start; ($until === null || $from->lte($until)) && $from->lte($this->next($current, $cycle, self::PERIODS_AFTER)); $from = $this->next($from, $cycle)) {
            $end = $this->next($from, $cycle)->subDay();
            $to = $until !== null && $end->gt($until) ? $until : $end;
            $receivable = $rows->first(fn (Receivable $receivable): bool => CarbonImmutable::instance($receivable->expected_on)->between($from, $to));

            $status = match ($receivable?->status) {
                ReceivableStatus::Received => 'received',
                ReceivableStatus::Invoiced => 'invoiced',
                ReceivableStatus::Planned => 'planned',
                default => 'missing',
            };
            $counts[$status]++;

            $periods[] = [
                'key' => $from->toDateString(),
                'label' => $cycle === MaintenanceCycle::Monthly && $from->day === 1 ? $from->format('Y/m') : $from->format('Y/m/d').' – '.$to->format('Y/m/d'),
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'is_current' => $from->eq($current),
                'is_future' => $from->gt($current),
                'status' => $status,
                'status_label' => self::recurringStatusLabel($status),
                'receivable' => $receivable === null ? null : $this->row($receivable, $today),
            ];
        }

        return [
            'customer_id' => $customer->id,
            'customer' => $customer->short_name ?: $customer->name,
            'fee_untaxed' => (int) $customer->maintenance_fee_untaxed,
            'cycle' => $cycle->value,
            'cycle_label' => $cycle->getLabel(),
            'per_label' => $cycle->perLabel(),
            'since' => $since->toDateString(),
            'until' => $until?->toDateString(),
            'periods' => $periods,
            ...$counts,
        ];
    }

    public static function recurringStatusLabel(string $status): string
    {
        return match ($status) {
            'received' => '已收',
            'invoiced' => '已開票',
            'planned' => '未開票',
            default => '沒有應收列',
        };
    }

    public static function isOverdue(Receivable $receivable, CarbonImmutable $today): bool
    {
        return in_array($receivable->status, [ReceivableStatus::Planned, ReceivableStatus::Invoiced], true)
            && CarbonImmutable::instance($receivable->expected_on)->lt($today);
    }

    public static function isDueUninvoiced(Receivable $receivable, CarbonImmutable $today): bool
    {
        return $receivable->status === ReceivableStatus::Planned
            && $receivable->invoiced_on === null
            && CarbonImmutable::instance($receivable->expected_on)->lte($today->addDays(self::DUE_SOON_DAYS));
    }

    protected function next(CarbonImmutable $start, MaintenanceCycle $cycle, int $periods = 1): CarbonImmutable
    {
        return $cycle === MaintenanceCycle::Monthly ? $start->addMonthsNoOverflow($periods) : $start->addYearsNoOverflow($periods);
    }

    /**
     * @param  list<array{amount: int, count: int}>  $items
     * @return array{amount: int, count: int}
     */
    protected static function sumCounts(array $items): array
    {
        return ['amount' => array_sum(array_column($items, 'amount')), 'count' => array_sum(array_column($items, 'count'))];
    }
}
