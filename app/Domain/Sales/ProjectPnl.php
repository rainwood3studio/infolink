<?php

namespace App\Domain\Sales;

use App\Domain\Delivery\ClosingBoard;
use App\Domain\Engineering\DevActivityReport;
use App\Enums\ReceivableStatus;
use App\Models\CostBaseline;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\GithubCommit;
use App\Models\GithubRepo;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\RedmineIssue;
use App\Models\RedmineTimeEntry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Read model for the 專案損益 page and the `project_pnl` MCP tool: where the team's time went in a period, per
 * project and customer, next to the money each one brings in.
 *
 * Definitions:
 * - Effort is counted in PERSON-DAYS, never in commits or lines. A person is a Developer (all their GitHub identities
 *   together) or an identity not mapped to one yet. Every calendar day (app timezone) on which a person authored at
 *   least one non-merge commit is one person-day, split EQUALLY among the distinct targets they committed to that day.
 * - A commit's target is the project of its repo ({@see GithubRepo::projectIdForBranch()}: a branch
 *   override first, then the repo's project), else the repo's deal (work for a prospect without a contract: 「尚未簽約
 *   的投入」), else the repo itself (unmapped — every unmapped repo is its own target, so mapping it later does not
 *   shift the other targets' numbers).
 * - `estimated_cost` is an ESTIMATE: the latest monthly cost baseline × (period days / 30), spread over the targets by
 *   their share of the person-days. It spreads the WHOLE monthly cost over the days with commits — there is no
 *   per-person cost, and work that leaves no commit (acceptance, meetings, sales) is not seen.
 * - Money is untaxed NTD unless a key says `taxed`. `revenue_in_period` = the project's receivables whose
 *   `expected_on` falls in the period (any status except cancelled, recurring fees included) — what the period was
 *   billed for, whether or not it has been collected. `received_*` counts receivables in status `received` (by
 *   `received_on` for the period). `recurring_monthly` = the project's recurring receivables expected in the latest
 *   month that has any. `margin_estimate` = revenue_in_period − estimated_cost; null when nothing is expected in the
 *   period or the cost is unknown.
 * - `redmine_hours` = Redmine hours logged in the period on the project's linked Redmine project; null when the
 *   project has no `redmine_project_id`. Hours are logged by few people: reference only.
 *
 * Aggregated in PHP so sqlite and Postgres behave the same (volumes are small: GitHub keeps one month of commits).
 */
class ProjectPnl
{
    /** @var array<string, string> Period keys of the page filter, in display order. */
    public const array PERIODS = [
        'last_30_days' => '近 30 天',
        'this_month' => '本月',
        'last_7_days' => '近 7 天',
    ];

    public const string DEFAULT_PERIOD = 'last_30_days';

    /** The monthly cost baseline is prorated as if a month had this many days. */
    public const int COST_MONTH_DAYS = 30;

    protected DevActivityReport $activity;

    public function __construct(?DevActivityReport $activity = null)
    {
        $this->activity = $activity ?? new DevActivityReport;
    }

    /**
     * Inclusive start/end of a period key (unknown keys fall back to 近 30 天), in the app timezone.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function periodRange(string $period, ?CarbonImmutable $now = null): array
    {
        $today = ($now ?? CarbonImmutable::now(config('app.timezone')))->startOfDay();

        return match ($period) {
            'this_month' => [$today->startOfMonth(), $today->endOfDay()],
            'last_7_days' => [$today->subDays(6), $today->endOfDay()],
            default => [$today->subDays(29), $today->endOfDay()],
        };
    }

    /**
     * Effort and money for the period. Shares are fractions of `total_person_days` (0–1, null when there are no
     * person-days); the `estimated_cost` of every project, presales and unmapped row adds up to `period_cost`.
     *
     * @return array{
     *     period: array{from: string, to: string, days: int},
     *     monthly_cost: ?int,
     *     period_cost: ?int,
     *     total_person_days: float,
     *     total_commits: int,
     *     projects: list<array{
     *         id: int, name: string, customer: ?string, customer_id: int, status: string, status_label: string,
     *         person_days: float, share: ?float, commits: int,
     *         by_person: list<array{name: string, person_days: float, commits: int}>,
     *         repos: list<array{full_name: string, commits: int}>,
     *         estimated_cost: ?int, contract_amount_untaxed: ?int, revenue_in_period: int, recurring_monthly: ?int,
     *         margin_estimate: ?int, received_in_period: int, received_total: int, outstanding_untaxed: int,
     *         outstanding_taxed: int, redmine_linked: bool, redmine_hours: ?float,
     *     }>,
     *     omitted_projects: int,
     *     customers: list<array{
     *         id: int, name: string, person_days: float, effort_share: ?float, commits: int, estimated_cost: ?int,
     *         received_total: int, received_share: ?float, outstanding_taxed: int,
     *     }>,
     *     received_total: int,
     *     presales: list<array{
     *         deal_id: int, party: string, title: string, stage: string, stage_label: string,
     *         person_days: float, share: ?float, commits: int, estimated_cost: ?int,
     *         by_person: list<array{name: string, person_days: float, commits: int}>,
     *         repos: list<array{full_name: string, commits: int}>,
     *     }>,
     *     presales_person_days: float,
     *     presales_share: ?float,
     *     presales_estimated_cost: ?int,
     *     unmapped: list<array{
     *         repo_id: int, full_name: string, url: string, person_days: float, share: ?float, commits: int,
     *         estimated_cost: ?int, branches: array<string, int>,
     *         by_person: list<array{name: string, person_days: float, commits: int}>,
     *     }>,
     *     unmapped_person_days: float,
     *     unmapped_share: ?float,
     *     unmapped_commits: int,
     *     unmapped_commit_share: ?float,
     *     missing: array{
     *         cost_baseline: bool,
     *         contract_amount: list<array{id: int, name: string}>,
     *         redmine_link: list<array{id: int, name: string}>,
     *     },
     * }
     */
    public function calculate(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $projects = Project::query()->with('customer')->get()->keyBy('id');
        $deals = Deal::query()->with('customer')->get()->keyBy('id');

        $effort = $this->effort($from, $to, $projects, $deals);
        $targets = $effort['targets'];
        $totalDays = $effort['person_days'];

        $periodDays = (int) $from->startOfDay()->diffInDays($to->startOfDay()) + 1;
        $monthlyCost = CostBaseline::query()
            ->whereDate('effective_from', '<=', $to->toDateString())
            ->latest('effective_from')
            ->value('monthly_cost');
        $periodCost = $monthlyCost === null ? null : (int) round($monthlyCost * $periodDays / self::COST_MONTH_DAYS);
        $costs = $this->spreadCost($periodCost, $targets, $totalDays);

        $share = fn (float $days): ?float => $totalDays > 0 ? round($days / $totalDays, 4) : null;
        $money = $this->money($from, $to);
        $redmineHours = $this->redmineHours($projects, $from, $to);

        $projectRows = $projects
            ->map(function (Project $project) use ($targets, $costs, $share, $money, $redmineHours): array {
                $key = 'project:'.$project->id;
                $target = $targets[$key] ?? null;
                $cash = $money['projects'][$project->id] ?? self::emptyMoney();
                $cost = $costs === null ? null : ($costs[$key] ?? 0);

                return [
                    'id' => $project->id,
                    'name' => $project->name,
                    'customer' => $project->customer?->short_name ?: $project->customer?->name,
                    'customer_id' => $project->customer_id,
                    'status' => $project->status->value,
                    'status_label' => $project->status->getLabel(),
                    'person_days' => round($target['days'] ?? 0.0, 1),
                    'share' => $share($target['days'] ?? 0.0),
                    'commits' => $target['commits'] ?? 0,
                    'by_person' => self::people($target),
                    'repos' => self::repos($target),
                    'estimated_cost' => $cost,
                    'contract_amount_untaxed' => $project->contract_amount_untaxed,
                    'revenue_in_period' => $cash['revenue_in_period'],
                    'recurring_monthly' => $cash['recurring_monthly'],
                    'margin_estimate' => $cost === null || $cash['expected_in_period'] === 0 ? null : $cash['revenue_in_period'] - $cost,
                    'received_in_period' => $cash['received_in_period'],
                    'received_total' => $cash['received_total'],
                    'outstanding_untaxed' => $cash['outstanding_untaxed'],
                    'outstanding_taxed' => $cash['outstanding_taxed'],
                    'redmine_linked' => $project->redmine_project_id !== null,
                    'redmine_hours' => $redmineHours[$project->id] ?? null,
                    'raw_days' => $target['days'] ?? 0.0,
                ];
            })
            ->values();

        $shown = $projectRows
            ->filter(fn (array $row): bool => $row['commits'] > 0
                || $row['revenue_in_period'] > 0
                || $row['received_in_period'] > 0
                || ($row['redmine_hours'] ?? 0) > 0)
            ->sort(fn (array $a, array $b): int => [$b['raw_days'], $b['revenue_in_period'], $a['name']] <=> [$a['raw_days'], $a['revenue_in_period'], $b['name']])
            ->values();

        $withEffort = $shown->filter(fn (array $row): bool => $row['commits'] > 0);
        $presales = $this->presales($targets, $deals, $costs, $share);
        $unmapped = $this->unmapped($targets, $costs, $share);
        $presalesDays = self::sumDays($targets, 'deal:');
        $unmappedDays = self::sumDays($targets, 'repo:');
        $unmappedCommits = array_sum(array_column($unmapped, 'commits'));

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $periodDays],
            'monthly_cost' => $monthlyCost,
            'period_cost' => $periodCost,
            'total_person_days' => round($totalDays, 1),
            'total_commits' => $effort['commits'],
            'projects' => $shown->map(fn (array $row): array => collect($row)->except('raw_days')->all())->all(),
            'omitted_projects' => $projectRows->count() - $shown->count(),
            'customers' => $this->customers($projectRows, $money, $share, $costs !== null),
            'received_total' => $money['received_total'],
            'presales' => $presales,
            'presales_person_days' => round($presalesDays, 1),
            'presales_share' => $share($presalesDays),
            'presales_estimated_cost' => $costs === null ? null : array_sum(array_column($presales, 'estimated_cost')),
            'unmapped' => $unmapped,
            'unmapped_person_days' => round($unmappedDays, 1),
            'unmapped_share' => $share($unmappedDays),
            'unmapped_commits' => $unmappedCommits,
            'unmapped_commit_share' => $effort['commits'] > 0 ? round($unmappedCommits / $effort['commits'], 4) : null,
            'missing' => [
                'cost_baseline' => $monthlyCost === null,
                'contract_amount' => $withEffort
                    ->filter(fn (array $row): bool => $row['contract_amount_untaxed'] === null)
                    ->map(fn (array $row): array => ['id' => $row['id'], 'name' => $row['name']])
                    ->values()
                    ->all(),
                'redmine_link' => $withEffort
                    ->filter(fn (array $row): bool => ! $row['redmine_linked'])
                    ->map(fn (array $row): array => ['id' => $row['id'], 'name' => $row['name']])
                    ->values()
                    ->all(),
            ],
        ];
    }

    /**
     * Non-merge commits of the period turned into person-days per target. A target key is `project:<id>`, `deal:<id>`
     * or `repo:<id>` (unmapped).
     *
     * @param  Collection<int, Project>  $projects
     * @param  Collection<int, Deal>  $deals
     * @return array{
     *     person_days: float,
     *     commits: int,
     *     targets: array<string, array{days: float, commits: int, people: array<string, array{name: string, days: float, commits: int}>, repos: array<string, int>, branches: array<string, int>}>,
     * }
     */
    protected function effort(CarbonImmutable $from, CarbonImmutable $to, Collection $projects, Collection $deals): array
    {
        $commits = GithubCommit::query()
            ->authored()
            ->with(['identity.developer', 'repo'])
            ->whereBetween('authored_at', [$from, $to])
            ->get();

        $targets = [];
        $names = [];
        $touched = [];

        foreach ($commits as $commit) {
            $key = $this->targetKey($commit, $projects, $deals);
            $person = $this->activity->person($commit->identity);
            $names[$person['key']] = $person['name'];
            $branch = $commit->branch ?? '';

            $targets[$key] ??= ['days' => 0.0, 'commits' => 0, 'people' => [], 'repos' => [], 'branches' => []];
            $targets[$key]['commits']++;
            $targets[$key]['people'][$person['key']] ??= ['name' => $person['name'], 'days' => 0.0, 'commits' => 0];
            $targets[$key]['people'][$person['key']]['commits']++;
            $targets[$key]['repos'][$commit->repo->full_name] = ($targets[$key]['repos'][$commit->repo->full_name] ?? 0) + 1;
            $targets[$key]['branches'][$branch] = ($targets[$key]['branches'][$branch] ?? 0) + 1;

            $touched[$person['key']][$this->day($commit->authored_at)][$key] = true;
        }

        $personDays = 0;

        foreach ($touched as $personKey => $days) {
            foreach ($days as $targetKeys) {
                $personDays++;
                $fraction = 1 / count($targetKeys);

                foreach (array_keys($targetKeys) as $key) {
                    $targets[$key]['days'] += $fraction;
                    $targets[$key]['people'][$personKey]['days'] += $fraction;
                }
            }
        }

        return ['person_days' => (float) $personDays, 'commits' => $commits->count(), 'targets' => $targets];
    }

    /**
     * Where a commit's effort goes. A branch override or repo project that points at a deleted project is skipped.
     *
     * @param  Collection<int, Project>  $projects
     * @param  Collection<int, Deal>  $deals
     */
    protected function targetKey(GithubCommit $commit, Collection $projects, Collection $deals): string
    {
        $repo = $commit->repo;

        foreach ([$repo->projectIdForBranch($commit->branch), $repo->project_id] as $projectId) {
            if ($projectId !== null && $projects->has($projectId)) {
                return 'project:'.$projectId;
            }
        }

        if ($repo->deal_id !== null && $deals->has($repo->deal_id)) {
            return 'deal:'.$repo->deal_id;
        }

        return 'repo:'.$repo->id;
    }

    /**
     * The period cost spread over the targets by person-days, in whole NTD. Rounding leftovers go to the largest
     * target so the parts add up to `$periodCost`. Null when the cost is unknown or there is nothing to spread it over.
     *
     * @param  array<string, array{days: float}>  $targets
     * @return array<string, int>|null
     */
    protected function spreadCost(?int $periodCost, array $targets, float $totalDays): ?array
    {
        if ($periodCost === null || $totalDays <= 0) {
            return null;
        }

        $costs = array_map(fn (array $target): int => (int) round($periodCost * $target['days'] / $totalDays), $targets);
        $largest = collect($targets)->sortByDesc('days')->keys()->first();
        $costs[$largest] += $periodCost - array_sum($costs);

        return $costs;
    }

    /**
     * Receivable totals per project and per customer (cancelled receivables are ignored).
     *
     * @return array{
     *     projects: array<int, array{revenue_in_period: int, expected_in_period: int, recurring_monthly: ?int, received_in_period: int, received_total: int, outstanding_untaxed: int, outstanding_taxed: int}>,
     *     customers: array<int, array{received_total: int, outstanding_taxed: int}>,
     *     received_total: int,
     * }
     */
    protected function money(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $start = $from->toDateString();
        $end = $to->toDateString();
        $inPeriod = fn (?CarbonInterface $date): bool => $date !== null && $date->toDateString() >= $start && $date->toDateString() <= $end;

        $projects = [];
        $customers = [];
        $recurring = [];
        $receivedTotal = 0;

        $receivables = Receivable::query()
            ->where('status', '!=', ReceivableStatus::Cancelled)
            ->get(['id', 'customer_id', 'project_id', 'amount_untaxed', 'amount_taxed', 'expected_on', 'status', 'received_on', 'is_recurring']);

        foreach ($receivables as $receivable) {
            $received = $receivable->status === ReceivableStatus::Received;
            $customers[$receivable->customer_id] ??= ['received_total' => 0, 'outstanding_taxed' => 0];

            if ($received) {
                $customers[$receivable->customer_id]['received_total'] += $receivable->amount_untaxed;
                $receivedTotal += $receivable->amount_untaxed;
            } else {
                $customers[$receivable->customer_id]['outstanding_taxed'] += $receivable->amount_taxed;
            }

            if ($receivable->project_id === null) {
                continue;
            }

            $projects[$receivable->project_id] ??= self::emptyMoney();
            $row = &$projects[$receivable->project_id];

            if ($received) {
                $row['received_total'] += $receivable->amount_untaxed;
                $row['received_in_period'] += $inPeriod($receivable->received_on) ? $receivable->amount_untaxed : 0;
            } else {
                $row['outstanding_untaxed'] += $receivable->amount_untaxed;
                $row['outstanding_taxed'] += $receivable->amount_taxed;
            }

            if ($inPeriod($receivable->expected_on)) {
                $row['revenue_in_period'] += $receivable->amount_untaxed;
                $row['expected_in_period']++;
            }

            if ($receivable->is_recurring) {
                $month = $receivable->expected_on->format('Y-m');
                $recurring[$receivable->project_id][$month] = ($recurring[$receivable->project_id][$month] ?? 0) + $receivable->amount_untaxed;
            }

            unset($row);
        }

        foreach ($recurring as $projectId => $months) {
            ksort($months);
            $projects[$projectId]['recurring_monthly'] = end($months);
        }

        return ['projects' => $projects, 'customers' => $customers, 'received_total' => $receivedTotal];
    }

    /**
     * Redmine hours logged in the period per company project that has a Redmine project linked (0.0 when linked but
     * nothing was logged). A project's time entries are those of the identifiers its mirrored issues carry, same as
     * {@see ClosingBoard}.
     *
     * @param  Collection<int, Project>  $projects
     * @return array<int, float>
     */
    protected function redmineHours(Collection $projects, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $linked = $projects->filter(fn (Project $project): bool => $project->redmine_project_id !== null);

        if ($linked->isEmpty()) {
            return [];
        }

        $identifiers = RedmineIssue::withTrashed()
            ->whereIn('project_id', $linked->pluck('redmine_project_id')->unique()->values())
            ->distinct()
            ->toBase()
            ->get(['project_id', 'project_identifier'])
            ->groupBy('project_id')
            ->map(fn (Collection $rows): array => $rows->pluck('project_identifier')->all());

        $hours = RedmineTimeEntry::query()
            ->whereDate('spent_on', '>=', $from->toDateString())
            ->whereDate('spent_on', '<=', $to->toDateString())
            ->toBase()
            ->get(['project_identifier', 'hours'])
            ->groupBy('project_identifier')
            ->map(fn (Collection $rows): float => (float) $rows->sum(fn (object $row): float => (float) $row->hours));

        return $linked
            ->map(fn (Project $project): float => round(array_sum(array_map(
                fn (string $identifier): float => $hours->get($identifier, 0.0),
                $identifiers->get($project->redmine_project_id, []),
            )), 2))
            ->all();
    }

    /**
     * Per customer: the effort of its projects and the money received from it, most effort first. Customers with
     * neither effort, received money nor anything outstanding are left out. Effort on deals is not attributed to a
     * customer (see `presales`).
     *
     * @param  Collection<int, array<string, mixed>>  $projectRows
     * @param  array{customers: array<int, array{received_total: int, outstanding_taxed: int}>, received_total: int}  $money
     * @return list<array{id: int, name: string, person_days: float, effort_share: ?float, commits: int, estimated_cost: ?int, received_total: int, received_share: ?float, outstanding_taxed: int}>
     */
    protected function customers(Collection $projectRows, array $money, callable $share, bool $hasCosts): array
    {
        $effort = $projectRows->groupBy('customer_id');

        return Customer::query()
            ->get()
            ->map(function (Customer $customer) use ($effort, $money, $share, $hasCosts): array {
                /** @var Collection<int, array<string, mixed>> $rows */
                $rows = $effort->get($customer->id, collect());
                $days = (float) $rows->sum('raw_days');
                $cash = $money['customers'][$customer->id] ?? ['received_total' => 0, 'outstanding_taxed' => 0];

                return [
                    'id' => $customer->id,
                    'name' => $customer->short_name ?: $customer->name,
                    'person_days' => round($days, 1),
                    'effort_share' => $share($days),
                    'commits' => (int) $rows->sum('commits'),
                    'estimated_cost' => $hasCosts ? (int) $rows->sum('estimated_cost') : null,
                    'received_total' => $cash['received_total'],
                    'received_share' => $money['received_total'] > 0 ? round($cash['received_total'] / $money['received_total'], 4) : null,
                    'outstanding_taxed' => $cash['outstanding_taxed'],
                    'raw_days' => $days,
                ];
            })
            ->filter(fn (array $row): bool => $row['commits'] > 0 || $row['received_total'] > 0 || $row['outstanding_taxed'] > 0)
            ->sort(fn (array $a, array $b): int => [$b['raw_days'], $b['received_total'], $a['name']] <=> [$a['raw_days'], $a['received_total'], $b['name']])
            ->map(fn (array $row): array => collect($row)->except('raw_days')->all())
            ->values()
            ->all();
    }

    /**
     * Effort on repos tied to a deal instead of a project, most effort first.
     *
     * @param  array<string, array<string, mixed>>  $targets
     * @param  Collection<int, Deal>  $deals
     * @param  array<string, int>|null  $costs
     * @return list<array{deal_id: int, party: string, title: string, stage: string, stage_label: string, person_days: float, share: ?float, commits: int, estimated_cost: ?int, by_person: list<array{name: string, person_days: float, commits: int}>, repos: list<array{full_name: string, commits: int}>}>
     */
    protected function presales(array $targets, Collection $deals, ?array $costs, callable $share): array
    {
        return collect($targets)
            ->filter(fn (array $target, string $key): bool => str_starts_with($key, 'deal:'))
            ->sortByDesc('days')
            ->map(function (array $target, string $key) use ($deals, $costs, $share): array {
                $deal = $deals[(int) substr($key, 5)];

                return [
                    'deal_id' => $deal->id,
                    'party' => $deal->party_name,
                    'title' => $deal->title,
                    'stage' => $deal->stage->value,
                    'stage_label' => $deal->stage->getLabel(),
                    'person_days' => round($target['days'], 1),
                    'share' => $share($target['days']),
                    'commits' => $target['commits'],
                    'estimated_cost' => $costs[$key] ?? null,
                    'by_person' => self::people($target),
                    'repos' => self::repos($target),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Repos with commits in the period that are tied to neither a project nor a deal, most effort first. `branches`
     * counts the commits per branch they were first seen on, to tell whether a branch override is what is missing.
     *
     * @param  array<string, array<string, mixed>>  $targets
     * @param  array<string, int>|null  $costs
     * @return list<array{repo_id: int, full_name: string, url: string, person_days: float, share: ?float, commits: int, estimated_cost: ?int, branches: array<string, int>, by_person: list<array{name: string, person_days: float, commits: int}>}>
     */
    protected function unmapped(array $targets, ?array $costs, callable $share): array
    {
        return collect($targets)
            ->filter(fn (array $target, string $key): bool => str_starts_with($key, 'repo:'))
            ->sortByDesc('days')
            ->map(function (array $target, string $key) use ($costs, $share): array {
                $fullName = (string) array_key_first($target['repos']);
                arsort($target['branches']);

                return [
                    'repo_id' => (int) substr($key, 5),
                    'full_name' => $fullName,
                    'url' => 'https://github.com/'.$fullName,
                    'person_days' => round($target['days'], 1),
                    'share' => $share($target['days']),
                    'commits' => $target['commits'],
                    'estimated_cost' => $costs[$key] ?? null,
                    'branches' => $target['branches'],
                    'by_person' => self::people($target),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array{people: array<string, array{name: string, days: float, commits: int}>}|null  $target
     * @return list<array{name: string, person_days: float, commits: int}>
     */
    protected static function people(?array $target): array
    {
        return collect($target['people'] ?? [])
            ->sort(fn (array $a, array $b): int => [$b['days'], $a['name']] <=> [$a['days'], $b['name']])
            ->map(fn (array $person): array => ['name' => $person['name'], 'person_days' => round($person['days'], 1), 'commits' => $person['commits']])
            ->values()
            ->all();
    }

    /**
     * @param  array{repos: array<string, int>}|null  $target
     * @return list<array{full_name: string, commits: int}>
     */
    protected static function repos(?array $target): array
    {
        return collect($target['repos'] ?? [])
            ->sortDesc()
            ->map(fn (int $commits, string $fullName): array => ['full_name' => $fullName, 'commits' => $commits])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, array{days: float}>  $targets
     */
    protected static function sumDays(array $targets, string $prefix): float
    {
        return (float) collect($targets)
            ->filter(fn (array $target, string $key): bool => str_starts_with($key, $prefix))
            ->sum('days');
    }

    /**
     * @return array{revenue_in_period: int, expected_in_period: int, recurring_monthly: ?int, received_in_period: int, received_total: int, outstanding_untaxed: int, outstanding_taxed: int}
     */
    protected static function emptyMoney(): array
    {
        return [
            'revenue_in_period' => 0,
            'expected_in_period' => 0,
            'recurring_monthly' => null,
            'received_in_period' => 0,
            'received_total' => 0,
            'outstanding_untaxed' => 0,
            'outstanding_taxed' => 0,
        ];
    }

    protected function day(CarbonInterface $moment): string
    {
        return $moment->copy()->setTimezone(config('app.timezone'))->toDateString();
    }
}
