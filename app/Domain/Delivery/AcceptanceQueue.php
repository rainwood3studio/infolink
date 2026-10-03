<?php

namespace App\Domain\Delivery;

use App\Domain\Engineering\GithubSync;
use App\Domain\Engineering\RedmineActivity;
use App\Enums\ProjectStatus;
use App\Models\Developer;
use App\Models\GithubCommit;
use App\Models\Project;
use App\Models\RedmineIssue;
use App\Models\RedmineStatusChange;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Read model behind 驗收隊列: every final acceptance is done by one person (services.redmine.acceptor_name), so the
 * open issues in 驗證中 assigned to that person are the acceptance queue, and 驗證中 issues assigned to anyone else
 * (or to nobody) are "off-flow" — nobody is going to accept them.
 *
 * Definitions:
 * - waiting since: the latest observed status change to 驗證中 of the issue; when none was observed (changes are
 *   only recorded from the first successful issue sync on) it falls back to `updated_on`, which is the last update
 *   of any kind and therefore a lower bound of the real wait. Waiting days are calendar days up to today.
 * - blocking a closing project: the issue's Redmine project is linked (`projects.redmine_project_id`) to a company
 *   project in `closing` status; its final payment waits on these issues.
 * - accepted per week: issues closed in the week and assigned to the acceptor (DeliveryMetrics `wenhao_throughput`,
 *   based on `closed_on`, so it also covers weeks before status tracking began).
 * - handed over per week: issues advanced to 驗證中 (DeliveryMetrics `advanced_to_verify`, whoever they ended up
 *   assigned to); null — missing, not zero — for weeks before status tracking began.
 *
 * The 驗證中 issues are loaded once per instance; everything is aggregated in PHP so sqlite and Postgres agree.
 *
 * @phpstan-type IssueRow array{id:int, subject:string, project_id:int, project_identifier:string, project_name:string, is_closing:bool, target_close_date:?string, priority:?string, assignee_name:?string, waiting_since:string, waiting_days:int, waiting_observed:bool, handed_over_by:?string, days_since_update:int, url:string}
 */
class AcceptanceQueue
{
    public const string UNASSIGNED = '(未指派)';

    /** The acceptance pace is averaged over this many complete weeks before the current one. */
    public const int AVERAGE_WEEKS = 4;

    /** Waiting-day buckets: key => [min, max] in days (max null = open-ended). */
    public const array AGE_BUCKETS = [
        'le_7' => [0, 7],
        'd8_30' => [8, 30],
        'd31_90' => [31, 90],
        'gt_90' => [91, null],
    ];

    /** @var Collection<int, IssueRow>|null */
    protected ?Collection $rows = null;

    /** @var Collection<int, array{name:string, target_close_date:?string, outstanding_taxed:int}>|null */
    protected ?Collection $closingProjects = null;

    public function __construct(
        protected DeliveryMetrics $metrics,
        protected RedmineActivity $activity,
    ) {}

    /**
     * The queue at a glance. `by_project` lists closing projects first (earliest target date first), then the
     * largest queues; `days_left` is negative once the target date has passed. `closing.outstanding_taxed` is the
     * tax-included receivables still to collect on the closing projects that have issues in the queue.
     * `off_flow.total` includes the unassigned 驗證中 issues (`off_flow.unassigned`); without them it equals the
     * `delivery.verifying.others` metric.
     *
     * @return array{acceptor:string, queue:int, oldest_waiting_days:?int, median_waiting_days:?int, waiting_observed:int, closing:array{issues:int, projects:int, outstanding_taxed:int}, age_buckets:array{le_7:int, d8_30:int, d31_90:int, gt_90:int}, by_project:list<array{identifier:string, name:string, count:int, is_closing:bool, closing_project:?string, target_close_date:?string, days_left:?int, outstanding_taxed:?int, oldest_waiting_days:int}>, off_flow:array{total:int, unassigned:int, by_assignee:list<array{assignee:string, count:int, oldest_days_since_update:int}>}}
     */
    public function summary(): array
    {
        $queue = $this->queue();
        $closingProjects = $this->closingProjects();
        $offFlow = $this->offFlow();

        $byProject = $queue
            ->groupBy('project_identifier')
            ->map(function (Collection $rows, string $identifier) use ($closingProjects): array {
                $first = $rows->first();
                $closing = $first['is_closing'] ? $closingProjects->get($first['project_id']) : null;

                return [
                    'identifier' => $identifier,
                    'name' => $first['project_name'],
                    'count' => $rows->count(),
                    'is_closing' => $first['is_closing'],
                    'closing_project' => $closing['name'] ?? null,
                    'target_close_date' => $first['target_close_date'],
                    'days_left' => $first['target_close_date'] === null
                        ? null
                        : (int) CarbonImmutable::today()->diffInDays(CarbonImmutable::parse($first['target_close_date']), false),
                    'outstanding_taxed' => $closing['outstanding_taxed'] ?? null,
                    'oldest_waiting_days' => (int) $rows->max('waiting_days'),
                ];
            })
            ->sort(fn (array $a, array $b): int => [! $a['is_closing'], $a['target_close_date'] ?? '9999-12-31', -$a['count'], $a['identifier']]
                <=> [! $b['is_closing'], $b['target_close_date'] ?? '9999-12-31', -$b['count'], $b['identifier']])
            ->values();

        $median = $queue->median('waiting_days');

        return [
            'acceptor' => (string) config('services.redmine.acceptor_name'),
            'queue' => $queue->count(),
            'oldest_waiting_days' => $queue->isEmpty() ? null : (int) $queue->max('waiting_days'),
            'median_waiting_days' => $median === null ? null : (int) round($median),
            'waiting_observed' => $queue->where('waiting_observed', true)->count(),
            'closing' => [
                'issues' => $queue->where('is_closing', true)->count(),
                'projects' => $byProject->where('is_closing', true)->count(),
                'outstanding_taxed' => (int) $byProject->sum('outstanding_taxed'),
            ],
            'age_buckets' => collect(self::AGE_BUCKETS)
                ->map(fn (array $range): int => $queue
                    ->filter(fn (array $row): bool => $row['waiting_days'] >= $range[0] && ($range[1] === null || $row['waiting_days'] <= $range[1]))
                    ->count())
                ->all(),
            'by_project' => $byProject->all(),
            'off_flow' => [
                'total' => (int) $offFlow->sum('count'),
                'unassigned' => (int) ($offFlow->firstWhere('assignee', self::UNASSIGNED)['count'] ?? 0),
                'by_assignee' => $offFlow
                    ->map(fn (array $group): array => [
                        'assignee' => $group['assignee'],
                        'count' => $group['count'],
                        'oldest_days_since_update' => $group['oldest_days_since_update'],
                    ])
                    ->all(),
            ],
        ];
    }

    /**
     * Weekly flow for the last $weeks ISO weeks (Monday start, oldest first; the last one is the current, partial
     * week) and how long the queue would take to clear at the recent pace.
     *
     * - `weeks[].handed_over` is null before status tracking began and `handed_over_partial` marks the week tracking
     *   started in. `acceptor_commits` (non-merge commits of the developer whose `redmine_name` is the acceptor) is
     *   null when no developer with a GitHub identity matches or the week starts before the GitHub retention window.
     * - `avg_accepted_per_week`: mean of the AVERAGE_WEEKS complete weeks before the current one.
     * - `weeks_to_clear` = queue ÷ that mean, assuming nothing new is handed over; null when nothing was accepted.
     * - `recent`: handovers vs acceptances over one and the same window — from the start of those complete weeks,
     *   or from when status tracking began if that is later, until now. `is_growing` is true when at least as many
     *   issues were handed over as accepted in it (null while handovers are not tracked).
     *
     * @return array{weeks:list<array{week_start:string, week_end:string, is_current:bool, accepted:int, handed_over:?int, handed_over_partial:bool, acceptor_commits:?int}>, queue:int, avg_accepted_per_week:float, weeks_to_clear:?float, projected_clear_date:?string, recent:?array{since:string, handed_over:int, accepted:int}, is_growing:?bool, status_tracked_since:?string, acceptor_developer:?string, commits_retention_start:string}
     */
    public function flow(int $weeks = 6): array
    {
        $weeks = max(1, $weeks);
        $currentWeek = CarbonImmutable::today()->startOfWeek(CarbonInterface::MONDAY);
        $trackedSince = $this->activity->statusTrackedSince();
        $retentionStart = CarbonImmutable::instance(GithubSync::windowStart());
        $developer = $this->acceptorDeveloper();

        $loaded = max($weeks, self::AVERAGE_WEEKS + 1);
        $commits = $developer === null ? null : $this->commitsByWeek($developer, $currentWeek->subWeeks($loaded - 1));

        $rows = collect(range($loaded - 1, 0))->map(function (int $weeksAgo) use ($currentWeek, $trackedSince, $retentionStart, $commits): array {
            $weekStart = $currentWeek->subWeeks($weeksAgo);
            $stats = $this->metrics->weekStats($weekStart);

            return [
                'week_start' => $stats['week_start'],
                'week_end' => $stats['week_end'],
                'is_current' => $weeksAgo === 0,
                'accepted' => $stats['wenhao_throughput'],
                'handed_over' => $stats['advanced_to_verify'],
                'handed_over_partial' => $stats['advanced_to_verify'] !== null && $trackedSince !== null && $trackedSince->gt($weekStart),
                'acceptor_commits' => $commits === null || $weekStart->lt($retentionStart) ? null : (int) $commits->get($stats['week_start'], 0),
            ];
        });

        $queue = $this->queue()->count();
        $average = $rows->where('is_current', false)->take(-self::AVERAGE_WEEKS)->sum('accepted') / self::AVERAGE_WEEKS;
        $weeksToClear = $average > 0 ? $queue / $average : null;
        $recent = $this->recentFlow($trackedSince, $currentWeek->subWeeks(self::AVERAGE_WEEKS));

        return [
            'weeks' => $rows->take(-$weeks)->values()->all(),
            'queue' => $queue,
            'avg_accepted_per_week' => round($average, 1),
            'weeks_to_clear' => $weeksToClear === null ? null : round($weeksToClear, 1),
            'projected_clear_date' => $weeksToClear === null ? null : CarbonImmutable::today()->addDays((int) ceil($weeksToClear * 7))->toDateString(),
            'recent' => $recent,
            'is_growing' => $recent === null ? null : $recent['handed_over'] > 0 && $recent['handed_over'] >= $recent['accepted'],
            'status_tracked_since' => $trackedSince?->toDateString(),
            'acceptor_developer' => $developer?->name,
            'commits_retention_start' => $retentionStart->toDateString(),
        ];
    }

    /**
     * The acceptance queue in suggested order: issues blocking a closing project first (earliest target date
     * first, projects without a target date last), then the longest waiting.
     *
     * @return Collection<int, IssueRow>
     */
    public function queue(?string $projectIdentifier = null): Collection
    {
        return $this->rows()
            ->filter(fn (array $row): bool => RedmineIssue::isAcceptor($row['assignee_name'])
                && ($projectIdentifier === null || $row['project_identifier'] === $projectIdentifier))
            ->sort(fn (array $a, array $b): int => [! $a['is_closing'], $a['target_close_date'] ?? '9999-12-31', -$a['waiting_days'], $a['id']]
                <=> [! $b['is_closing'], $b['target_close_date'] ?? '9999-12-31', -$b['waiting_days'], $b['id']])
            ->values();
    }

    /**
     * 驗證中 issues that are not assigned to the acceptor, grouped by assignee ('(未指派)' for none): largest group
     * first, and within a group the issues untouched the longest first.
     *
     * @return Collection<int, array{assignee:string, count:int, oldest_days_since_update:int, issues:list<IssueRow>}>
     */
    public function offFlow(): Collection
    {
        return $this->rows()
            ->reject(fn (array $row): bool => RedmineIssue::isAcceptor($row['assignee_name']))
            ->groupBy(fn (array $row): string => filled($row['assignee_name']) ? $row['assignee_name'] : self::UNASSIGNED)
            ->map(fn (Collection $rows, string $assignee): array => [
                'assignee' => $assignee,
                'count' => $rows->count(),
                'oldest_days_since_update' => (int) $rows->max('days_since_update'),
                'issues' => $rows
                    ->sort(fn (array $a, array $b): int => [-$a['days_since_update'], $a['id']] <=> [-$b['days_since_update'], $b['id']])
                    ->values()
                    ->all(),
            ])
            ->sort(fn (array $a, array $b): int => [-$a['count'], $a['assignee']] <=> [-$b['count'], $b['assignee']])
            ->values();
    }

    /**
     * Every open 驗證中 issue (queue and off-flow) with its waiting time and closing-project link.
     *
     * @return Collection<int, IssueRow>
     */
    protected function rows(): Collection
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        $today = CarbonImmutable::today();
        $closingProjects = $this->closingProjects();

        $issues = RedmineIssue::query()
            ->open()
            ->where('status', RedmineIssue::STATUS_VERIFYING)
            ->get(['id', 'project_id', 'project_identifier', 'project_name', 'priority', 'assignee_name', 'subject', 'created_on', 'updated_on']);

        /** @var Collection<int, RedmineStatusChange> $handovers latest observed change to 驗證中 per issue */
        $handovers = RedmineStatusChange::query()
            ->where('to_status', RedmineIssue::STATUS_VERIFYING)
            ->whereIn('issue_id', $issues->modelKeys())
            ->orderBy('changed_at')
            ->orderBy('id')
            ->get(['id', 'issue_id', 'previous_assignee_name', 'changed_at'])
            ->keyBy('issue_id');

        $daysSince = fn (CarbonInterface $moment): int => max(0, (int) CarbonImmutable::instance($moment)->startOfDay()->diffInDays($today, false));

        return $this->rows = $issues
            ->map(function (RedmineIssue $issue) use ($closingProjects, $handovers, $daysSince): array {
                $handover = $handovers->get($issue->id);
                $lastUpdate = $issue->updated_on ?? $issue->created_on;
                $waitingSince = $handover?->changed_at ?? $lastUpdate;
                $closing = $closingProjects->get((int) $issue->project_id);

                return [
                    'id' => (int) $issue->id,
                    'subject' => (string) $issue->subject,
                    'project_id' => (int) $issue->project_id,
                    'project_identifier' => (string) $issue->project_identifier,
                    'project_name' => (string) $issue->project_name,
                    'is_closing' => $closing !== null,
                    'target_close_date' => $closing['target_close_date'] ?? null,
                    'priority' => $issue->priority,
                    'assignee_name' => $issue->assignee_name,
                    'waiting_since' => $waitingSince->toDateString(),
                    'waiting_days' => $daysSince($waitingSince),
                    'waiting_observed' => $handover !== null,
                    'handed_over_by' => filled($handover?->previous_assignee_name) ? $handover->previous_assignee_name : null,
                    'days_since_update' => $daysSince($lastUpdate),
                    'url' => $issue->url(),
                ];
            })
            ->toBase();
    }

    /**
     * Closing company projects keyed by their Redmine project id. Several company projects on one Redmine project
     * are merged: earliest target date, summed outstanding receivables (tax-included).
     *
     * @return Collection<int, array{name:string, target_close_date:?string, outstanding_taxed:int}>
     */
    protected function closingProjects(): Collection
    {
        return $this->closingProjects ??= Project::query()
            ->where('status', ProjectStatus::Closing)
            ->whereNotNull('redmine_project_id')
            ->withSum(['receivables as outstanding_taxed' => fn (Builder $query): Builder => $query->outstanding()], 'amount_taxed')
            ->orderBy('id')
            ->get()
            ->groupBy('redmine_project_id')
            ->map(fn (Collection $projects): array => [
                'name' => $projects->pluck('name')->implode('、'),
                'target_close_date' => $projects->pluck('target_close_date')->filter()->min()?->toDateString(),
                'outstanding_taxed' => (int) $projects->sum('outstanding_taxed'),
            ])
            ->toBase();
    }

    /**
     * Handovers to 驗證中 and acceptances since the later of $from and the start of status tracking, so both are
     * counted over the same window. Null until status changes are tracked.
     *
     * @return array{since:string, handed_over:int, accepted:int}|null
     */
    protected function recentFlow(?CarbonImmutable $trackedSince, CarbonImmutable $from): ?array
    {
        if ($trackedSince === null) {
            return null;
        }

        $since = $trackedSince->gt($from) ? $trackedSince : $from;

        return [
            'since' => $since->toDateString(),
            'handed_over' => RedmineStatusChange::query()
                ->where('to_status', RedmineIssue::STATUS_VERIFYING)
                ->where('changed_at', '>=', $since)
                ->distinct()
                ->count('issue_id'),
            'accepted' => RedmineIssue::query()
                ->where('closed_on', '>=', $since)
                ->get(['id', 'assignee_name'])
                ->filter(fn (RedmineIssue $issue): bool => RedmineIssue::isAcceptor($issue->assignee_name))
                ->count(),
        ];
    }

    /**
     * The developer whose Redmine name is the acceptor; null when nobody matches or they have no GitHub identity
     * (their commits are then unknown, not zero).
     */
    protected function acceptorDeveloper(): ?Developer
    {
        return Developer::query()
            ->whereNotNull('redmine_name')
            ->whereHas('identities')
            ->orderBy('id')
            ->get()
            ->first(fn (Developer $developer): bool => RedmineIssue::isAcceptor($developer->redmine_name));
    }

    /**
     * Non-merge commits of a developer since $from, counted per ISO week (key: the Monday, app timezone).
     *
     * @return Collection<string, int>
     */
    protected function commitsByWeek(Developer $developer, CarbonImmutable $from): Collection
    {
        return GithubCommit::query()
            ->authored()
            ->whereIn('github_identity_id', $developer->identities()->pluck('id'))
            ->where('authored_at', '>=', $from)
            ->get(['id', 'authored_at'])
            ->toBase()
            ->countBy(fn (GithubCommit $commit): string => CarbonImmutable::instance($commit->authored_at)
                ->setTimezone(config('app.timezone'))
                ->startOfWeek(CarbonInterface::MONDAY)
                ->toDateString());
    }
}
