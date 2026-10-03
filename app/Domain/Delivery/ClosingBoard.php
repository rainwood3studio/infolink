<?php

namespace App\Domain\Delivery;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\RedmineIssue;
use App\Models\RedmineStatusSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Read model for the 結案作戰 page and the `closing_projects` MCP tool: for every `closing` project, what is still open
 * in Redmine, who holds it, how fast the open stock is shrinking and how much uncollected money hangs on it.
 *
 * Definitions:
 * - A project's issues are the mirror issues whose `project_id` equals `projects.redmine_project_id` (subprojects are
 *   not included, same as the 結案專案進度 widget and the 結案專案風險 rule).
 * - Stage of an open issue ("stuck on whom"): `unassigned` (no assignee, not 驗證中), `in_progress` (has an assignee,
 *   not 驗證中), `awaiting_acceptance` (驗證中, assigned to the acceptor), `off_flow` (驗證中 but not assigned to the
 *   acceptor, unassigned included: nobody will accept it until it is reassigned).
 * - Trend: daily open counts from `redmine_status_snapshots` (reconstructed days count too: they know the open total)
 *   for the last TREND_DAYS days, ending with today's live count. A day on which a snapshot was taken but the project
 *   has no rows means 0 open; a day without any snapshot is omitted.
 * - Burn: (open at the baseline − open now) / days since the baseline, where the baseline is the latest snapshot day
 *   at or before BURN_WINDOW_DAYS ago inside the trend window. It is NET burn: new issues offset closed ones.
 * - Projection: today + ceil(open / burn) — a straight line from the last week, not a commitment.
 */
class ClosingBoard
{
    public const string STAGE_UNASSIGNED = 'unassigned';

    public const string STAGE_IN_PROGRESS = 'in_progress';

    public const string STAGE_OFF_FLOW = 'off_flow';

    public const string STAGE_AWAITING_ACCEPTANCE = 'awaiting_acceptance';

    /** Stages in the order the issue list shows them: what still needs work first, the acceptance queue last. */
    public const array STAGES = [
        self::STAGE_UNASSIGNED,
        self::STAGE_IN_PROGRESS,
        self::STAGE_OFF_FLOW,
        self::STAGE_AWAITING_ACCEPTANCE,
    ];

    /** The open stock shrank over the burn window; `projected_close_date` is set. */
    public const string PROJECTION_PROJECTED = 'projected';

    /** The open stock did not shrink over the burn window. */
    public const string PROJECTION_NOT_CONVERGING = 'not_converging';

    /** No snapshot old enough to measure a burn rate. */
    public const string PROJECTION_NO_HISTORY = 'no_history';

    /** Nothing is open in Redmine any more. */
    public const string PROJECTION_CLEARED = 'cleared';

    /** The project has no Redmine project linked. */
    public const string PROJECTION_NOT_LINKED = 'not_linked';

    public const int TREND_DAYS = 14;

    public const int BURN_WINDOW_DAYS = 7;

    public const int STALLED_DAYS = 30;

    public const string UNASSIGNED_LABEL = '(未指派)';

    /**
     * Every closing project, most urgent first (target date ascending, projects without a target last, then name).
     *
     * @return Collection<int, array{
     *     id: int,
     *     name: string,
     *     customer: ?string,
     *     target_close_date: ?string,
     *     days_left: ?int,
     *     is_overdue: bool,
     *     redmine_linked: bool,
     *     redmine_project_id: ?int,
     *     redmine_identifiers: list<string>,
     *     open: int,
     *     by_stage: array{unassigned: int, in_progress: int, off_flow: int, awaiting_acceptance: int},
     *     by_status: array<string, int>,
     *     by_assignee: list<array{name: string, count: int, is_acceptor: bool}>,
     *     stalled_30d: int,
     *     outstanding_taxed: int,
     *     outstanding_overdue_taxed: int,
     *     next_expected_on: ?string,
     *     receivables: list<array{id: int, item: string, amount_taxed: int, expected_on: string, is_overdue: bool}>,
     *     trend: list<array{date: string, open: int}>,
     *     burn_from: ?array{date: string, open: int, days_ago: int},
     *     burn_per_day: ?float,
     *     projection: string,
     *     projected_close_date: ?string,
     *     days_late: ?int,
     * }>
     */
    public function projects(): Collection
    {
        $now = CarbonImmutable::now();
        $today = $now->startOfDay();

        $projects = Project::query()
            ->with([
                'customer',
                'receivables' => fn (HasMany $query): HasMany => $query->outstanding()->orderBy('expected_on')->orderBy('id'),
            ])
            ->where('status', ProjectStatus::Closing)
            ->orderByRaw('target_close_date is null')
            ->orderBy('target_close_date')
            ->orderBy('name')
            ->get();

        $redmineProjectIds = $projects->pluck('redmine_project_id')->filter()->unique()->values();

        $issuesByProject = RedmineIssue::query()
            ->open()
            ->whereIn('project_id', $redmineProjectIds)
            ->get(['id', 'project_id', 'status', 'assignee_name', 'updated_on'])
            ->groupBy('project_id');

        $identifiersByProject = RedmineIssue::query()
            ->whereIn('project_id', $redmineProjectIds)
            ->distinct()
            ->toBase()
            ->get(['project_id', 'project_identifier'])
            ->groupBy('project_id')
            ->map(fn (Collection $rows): array => $rows->pluck('project_identifier')->sort()->values()->all());

        $history = $this->snapshotHistory($today, $identifiersByProject->flatten()->unique()->values()->all());

        return $projects->map(function (Project $project) use ($now, $today, $issuesByProject, $identifiersByProject, $history): array {
            /** @var Collection<int, RedmineIssue> $issues */
            $issues = $issuesByProject->get($project->redmine_project_id, collect());
            $linked = $project->redmine_project_id !== null;
            $identifiers = $identifiersByProject->get($project->redmine_project_id, []);
            $open = $issues->count();
            $daysLeft = $project->target_close_date === null ? null : (int) $today->diffInDays($project->target_close_date, false);

            $trend = $this->trend($history, $identifiers, $today, $open);
            $burn = $this->burn($trend, $today, $open);
            $projection = $this->projection($linked, $open, $burn, $today, $project->target_close_date === null ? null : CarbonImmutable::instance($project->target_close_date));

            $receivables = $project->receivables;

            return [
                'id' => $project->id,
                'name' => $project->name,
                'customer' => $project->customer?->short_name ?: $project->customer?->name,
                'target_close_date' => $project->target_close_date?->toDateString(),
                'days_left' => $daysLeft,
                'is_overdue' => $daysLeft !== null && $daysLeft < 0,
                'redmine_linked' => $linked,
                'redmine_project_id' => $project->redmine_project_id,
                'redmine_identifiers' => $identifiers,
                'open' => $open,
                'by_stage' => $this->countByStage($issues),
                'by_status' => $issues->countBy('status')->sortDesc()->all(),
                'by_assignee' => $issues
                    ->countBy(fn (RedmineIssue $issue): string => filled($issue->assignee_name) ? $issue->assignee_name : self::UNASSIGNED_LABEL)
                    ->map(fn (int $count, string $name): array => ['name' => $name, 'count' => $count, 'is_acceptor' => RedmineIssue::isAcceptor($name)])
                    ->sortBy([['count', 'desc'], ['name', 'asc']])
                    ->values()
                    ->all(),
                'stalled_30d' => $issues->filter(fn (RedmineIssue $issue): bool => RedmineSnapshotter::isStalled($issue, $now, self::STALLED_DAYS))->count(),
                'outstanding_taxed' => (int) $receivables->sum('amount_taxed'),
                'outstanding_overdue_taxed' => (int) $receivables->filter(fn (Receivable $receivable): bool => $receivable->is_overdue)->sum('amount_taxed'),
                'next_expected_on' => $receivables->first()?->expected_on->toDateString(),
                'receivables' => $receivables->map(fn (Receivable $receivable): array => [
                    'id' => $receivable->id,
                    'item' => $receivable->item,
                    'amount_taxed' => $receivable->amount_taxed,
                    'expected_on' => $receivable->expected_on->toDateString(),
                    'is_overdue' => $receivable->is_overdue,
                ])->values()->all(),
                'trend' => $trend,
                'burn_from' => $burn['from'],
                'burn_per_day' => $burn['per_day'] === null ? null : round($burn['per_day'], 2),
                ...$projection,
            ];
        })->values();
    }

    /**
     * Totals over the closing projects (pass the result of projects() to avoid loading it twice).
     *
     * `overdue` = target date already passed; `projected_late` = not yet overdue but the projection lands after the
     * target; `not_converging` = not yet overdue and the open stock did not shrink over the burn window.
     *
     * @param  Collection<int, array<string, mixed>>|null  $projects
     * @return array{projects: int, open: int, outstanding_taxed: int, outstanding_overdue_taxed: int, overdue: int, projected_late: int, not_converging: int, at_risk: int}
     */
    public function summary(?Collection $projects = null): array
    {
        $projects ??= $this->projects();

        $overdue = $projects->where('is_overdue', true)->count();
        $projectedLate = $projects->filter(fn (array $project): bool => ! $project['is_overdue'] && ($project['days_late'] ?? 0) > 0)->count();
        $notConverging = $projects->filter(fn (array $project): bool => ! $project['is_overdue'] && $project['projection'] === self::PROJECTION_NOT_CONVERGING)->count();

        return [
            'projects' => $projects->count(),
            'open' => (int) $projects->sum('open'),
            'outstanding_taxed' => (int) $projects->sum('outstanding_taxed'),
            'outstanding_overdue_taxed' => (int) $projects->sum('outstanding_overdue_taxed'),
            'overdue' => $overdue,
            'projected_late' => $projectedLate,
            'not_converging' => $notConverging,
            'at_risk' => $overdue + $projectedLate + $notConverging,
        ];
    }

    /**
     * The project's open issues, sorted by stage (STAGES order) and, inside a stage, longest without an update first.
     *
     * @return Collection<int, array{id: int, subject: string, status: string, stage: string, assignee: ?string, priority: ?string, tracker: ?string, days_since_update: int, days_since_created: int, due_date: ?string, is_overdue: bool, url: string}>
     */
    public function issues(Project $project): Collection
    {
        if ($project->redmine_project_id === null) {
            return collect();
        }

        $now = CarbonImmutable::now();
        $stageOrder = array_flip(self::STAGES);

        return RedmineIssue::query()
            ->open()
            ->where('project_id', $project->redmine_project_id)
            ->get(['id', 'subject', 'status', 'assignee_name', 'priority', 'tracker', 'created_on', 'updated_on', 'due_date'])
            ->map(fn (RedmineIssue $issue): array => [
                'id' => $issue->id,
                'subject' => $issue->subject,
                'status' => $issue->status,
                'stage' => self::stage($issue->status, $issue->assignee_name),
                'assignee' => filled($issue->assignee_name) ? $issue->assignee_name : null,
                'priority' => $issue->priority,
                'tracker' => $issue->tracker,
                'days_since_update' => max(0, (int) floor($issue->updated_on->diffInDays($now))),
                'days_since_created' => max(0, (int) floor($issue->created_on->diffInDays($now))),
                'due_date' => $issue->due_date?->toDateString(),
                'is_overdue' => RedmineSnapshotter::isOverdue($issue, $now),
                'url' => $issue->url(),
                'updated_at' => $issue->updated_on->getTimestamp(),
            ])
            ->sortBy(fn (array $row): array => [$stageOrder[$row['stage']], $row['updated_at'], $row['id']])
            ->map(fn (array $row): array => collect($row)->except('updated_at')->all())
            ->values();
    }

    /**
     * Which stage an open issue is in, i.e. who it is stuck on.
     *
     * @return self::STAGE_*
     */
    public static function stage(?string $status, ?string $assigneeName): string
    {
        if ($status === RedmineIssue::STATUS_VERIFYING) {
            return RedmineIssue::isAcceptor($assigneeName) ? self::STAGE_AWAITING_ACCEPTANCE : self::STAGE_OFF_FLOW;
        }

        return filled($assigneeName) ? self::STAGE_IN_PROGRESS : self::STAGE_UNASSIGNED;
    }

    /**
     * @param  Collection<int, RedmineIssue>  $issues
     * @return array{unassigned: int, in_progress: int, off_flow: int, awaiting_acceptance: int}
     */
    protected function countByStage(Collection $issues): array
    {
        $counts = $issues->countBy(fn (RedmineIssue $issue): string => self::stage($issue->status, $issue->assignee_name));

        return [
            self::STAGE_UNASSIGNED => (int) $counts->get(self::STAGE_UNASSIGNED, 0),
            self::STAGE_IN_PROGRESS => (int) $counts->get(self::STAGE_IN_PROGRESS, 0),
            self::STAGE_OFF_FLOW => (int) $counts->get(self::STAGE_OFF_FLOW, 0),
            self::STAGE_AWAITING_ACCEPTANCE => (int) $counts->get(self::STAGE_AWAITING_ACCEPTANCE, 0),
        ];
    }

    /**
     * Snapshot totals of the trend window (before today): the days on which any snapshot exists, and the open count
     * per day for the given identifiers.
     *
     * @param  list<string>  $identifiers
     * @return array{dates: list<string>, open: array<string, array<string, int>>} `open` is keyed by identifier, then date
     */
    protected function snapshotHistory(CarbonImmutable $today, array $identifiers): array
    {
        $since = $today->subDays(self::TREND_DAYS - 1)->toDateString();
        $window = fn () => RedmineStatusSnapshot::query()
            ->whereDate('snapshot_date', '>=', $since)
            ->whereDate('snapshot_date', '<', $today->toDateString())
            ->toBase();

        $dates = $window()
            ->distinct()
            ->pluck('snapshot_date')
            ->map(fn (string $date): string => substr($date, 0, 10))
            ->unique()
            ->sort()
            ->values()
            ->all();

        $open = [];

        if ($identifiers !== []) {
            foreach ($window()->whereIn('project_identifier', $identifiers)->get(['snapshot_date', 'project_identifier', 'count']) as $row) {
                $date = substr((string) $row->snapshot_date, 0, 10);
                $open[$row->project_identifier][$date] = ($open[$row->project_identifier][$date] ?? 0) + (int) $row->count;
            }
        }

        return ['dates' => $dates, 'open' => $open];
    }

    /**
     * Daily open counts, oldest first, ending with today's live count. Without a known identifier the project has no
     * history, so only today is returned.
     *
     * @param  array{dates: list<string>, open: array<string, array<string, int>>}  $history
     * @param  list<string>  $identifiers
     * @return list<array{date: string, open: int}>
     */
    protected function trend(array $history, array $identifiers, CarbonImmutable $today, int $openNow): array
    {
        $trend = [];

        if ($identifiers !== []) {
            foreach ($history['dates'] as $date) {
                $trend[] = [
                    'date' => $date,
                    'open' => array_sum(array_map(fn (string $identifier): int => $history['open'][$identifier][$date] ?? 0, $identifiers)),
                ];
            }
        }

        $trend[] = ['date' => $today->toDateString(), 'open' => $openNow];

        return $trend;
    }

    /**
     * Net issues closed per day since the baseline (the latest trend day at or before BURN_WINDOW_DAYS ago).
     *
     * @param  list<array{date: string, open: int}>  $trend
     * @return array{from: ?array{date: string, open: int, days_ago: int}, per_day: ?float}
     */
    protected function burn(array $trend, CarbonImmutable $today, int $openNow): array
    {
        $cutoff = $today->subDays(self::BURN_WINDOW_DAYS)->toDateString();
        $baseline = collect($trend)->last(fn (array $day): bool => $day['date'] <= $cutoff);

        if ($baseline === null) {
            return ['from' => null, 'per_day' => null];
        }

        $days = (int) CarbonImmutable::parse($baseline['date'])->diffInDays($today);

        return ['from' => [...$baseline, 'days_ago' => $days], 'per_day' => ($baseline['open'] - $openNow) / $days];
    }

    /**
     * @param  array{from: ?array{date: string, open: int, days_ago: int}, per_day: ?float}  $burn
     * @return array{projection: string, projected_close_date: ?string, days_late: ?int}
     */
    protected function projection(bool $linked, int $open, array $burn, CarbonImmutable $today, ?CarbonImmutable $target): array
    {
        $status = match (true) {
            ! $linked => self::PROJECTION_NOT_LINKED,
            $open === 0 => self::PROJECTION_CLEARED,
            $burn['per_day'] === null => self::PROJECTION_NO_HISTORY,
            $burn['per_day'] <= 0 => self::PROJECTION_NOT_CONVERGING,
            default => self::PROJECTION_PROJECTED,
        };

        if ($status !== self::PROJECTION_PROJECTED) {
            return ['projection' => $status, 'projected_close_date' => null, 'days_late' => null];
        }

        $projected = $today->addDays((int) ceil($open / $burn['per_day']));

        return [
            'projection' => $status,
            'projected_close_date' => $projected->toDateString(),
            'days_late' => $target === null ? null : (int) $target->startOfDay()->diffInDays($projected, false),
        ];
    }
}
