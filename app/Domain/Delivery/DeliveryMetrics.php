<?php

namespace App\Domain\Delivery;

use App\Domain\Metrics\MetricRecorder;
use App\Enums\Source;
use App\Enums\SyncJob;
use App\Enums\SyncStatus;
use App\Models\MetricValue;
use App\Models\RedmineIssue;
use App\Models\RedmineStatusChange;
use App\Models\RedmineTimeEntry;
use App\Models\SyncRun;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Computes the delivery.* metrics from the Redmine mirror and writes them through MetricRecorder (source: system).
 *
 * Final acceptance is done by one person (services.redmine.acceptor_name), so `closed` measures that person's
 * acceptance, not team throughput; `advanced_to_verify` is the throughput signal.
 *
 * 驗證中 split: `verifying.wenhao` = assignee is the acceptor; `verifying.others` = assigned to anyone else (named);
 * unassigned 驗證中 belongs to neither bucket (exposed separately by DeliverySummary). W36: 214 = 153 acceptor +
 * 61 others (裕樺 30 + 妤欣 28 + 鈺文 2 + 永彬 1) + 0 unassigned; the report's "58" was the 裕樺 + 妤欣 subset only.
 */
class DeliveryMetrics
{
    public function __construct(
        protected MetricRecorder $recorder,
        protected RedmineSnapshotter $snapshotter,
    ) {}

    /**
     * Record the daily stock metrics for a date (default today) from the mirror's current state.
     */
    public function recordDaily(?CarbonInterface $date = null): void
    {
        $date = CarbonImmutable::instance($date ?? today())->startOfDay();
        $groups = $this->snapshotter->groups(RedmineSnapshotter::asOf($date));

        $verifying = $groups->where('status', RedmineIssue::STATUS_VERIFYING);
        $verifyingOthers = $verifying->filter(fn (array $group): bool => $group['assignee_name'] !== '' && ! RedmineIssue::isAcceptor($group['assignee_name']));

        DB::transaction(function () use ($date, $groups, $verifying, $verifyingOthers): void {
            $this->recordWithDimensions('delivery.open', $groups->sum('count'), $this->byProject($groups, 'count'), $date);
            $this->recordWithDimensions('delivery.verifying.others', $verifyingOthers->sum('count'), $this->byProject($verifyingOthers, 'count'), $date);
            $this->recordWithDimensions('delivery.stalled_30d', $groups->sum('stalled_30d'), $this->byProject($groups, 'stalled_30d'), $date);
            $this->recordWithDimensions('delivery.stalled_90d', $groups->sum('stalled_90d'), $this->byProject($groups, 'stalled_90d'), $date);

            $this->recorder->record('delivery.verifying.wenhao', $verifying->filter(fn (array $group): bool => RedmineIssue::isAcceptor($group['assignee_name']))->sum('count'), $date);
            $this->recorder->record('delivery.overdue', $groups->sum('overdue'), $date);
            $this->recorder->record('delivery.unassigned', $groups->where('assignee_name', '')->sum('count'), $date);
        });
    }

    /**
     * Record the weekly flow metrics for the ISO week containing $weekStart (period_start = Monday).
     */
    public function recordWeekly(CarbonInterface $weekStart): void
    {
        $stats = $this->weekStats($weekStart);
        $monday = CarbonImmutable::parse($stats['week_start']);

        DB::transaction(function () use ($stats, $monday): void {
            $this->recorder->record('delivery.created', $stats['created'], $monday);
            $this->recorder->record('delivery.closed', $stats['closed'], $monday);
            $this->recorder->record('delivery.net_flow', $stats['net_flow'], $monday);
            $this->recorder->record('delivery.wenhao_throughput', $stats['wenhao_throughput'], $monday);

            if ($stats['advanced_to_verify'] !== null) {
                $this->recordWithDimensions('delivery.advanced_to_verify', $stats['advanced_to_verify'], $this->prefixed('assignee', $stats['advanced_to_verify_by_assignee']), $monday);
            }
            $this->recordWithDimensions('delivery.hours_logged', $stats['hours_logged'], $this->prefixed('user', $stats['hours_by_user']), $monday);

            if ($stats['inflow_to_wenhao_ratio'] !== null) {
                $this->recorder->record('delivery.inflow_to_wenhao_ratio', $stats['inflow_to_wenhao_ratio'], $monday);
            }
        });
    }

    /**
     * Raw weekly numbers (Monday 00:00 – Sunday 23:59:59, app timezone). Soft-deleted issues are excluded.
     *
     * - created / closed: by created_on / closed_on in the week (closed counts closures that happened in the week, even if the issue was reopened later — matches the weekly report).
     * - wenhao_throughput: closed in the week and currently assigned to the acceptor.
     * - advanced_to_verify: distinct issues with a status change to 驗證中 in the week, attributed to the person who
     *   handed it over (previous_assignee_name, else assignee_name, else '(未指派)').
     * - hours_logged: time entries by spent_on.
     * - inflow_to_wenhao_ratio: share of the week's new issues currently assigned to the acceptor (null if none created).
     *
     * @return array{week_start: string, week_end: string, created: int, closed: int, net_flow: int, created_by_project: array<string, int>, closed_by_project: array<string, int>, wenhao_throughput: int, advanced_to_verify: ?int, advanced_to_verify_by_assignee: array<string, int>, hours_logged: float, hours_by_user: array<string, float>, created_assigned_to_acceptor: int, inflow_to_wenhao_ratio: ?float}
     */
    public function weekStats(CarbonInterface $weekStart): array
    {
        $start = CarbonImmutable::instance($weekStart)->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
        $end = $start->addWeek();

        $created = RedmineIssue::query()
            ->where('created_on', '>=', $start)
            ->where('created_on', '<', $end)
            ->get(['id', 'project_identifier', 'assignee_name']);

        $closed = RedmineIssue::query()
            ->where('closed_on', '>=', $start)
            ->where('closed_on', '<', $end)
            ->get(['id', 'project_identifier', 'assignee_name']);

        $advanced = RedmineStatusChange::query()
            ->where('to_status', RedmineIssue::STATUS_VERIFYING)
            ->where('changed_at', '>=', $start)
            ->where('changed_at', '<', $end)
            ->orderBy('changed_at')
            ->orderBy('id')
            ->get()
            ->unique('issue_id');

        $timeEntries = RedmineTimeEntry::query()
            ->whereDate('spent_on', '>=', $start->toDateString())
            ->whereDate('spent_on', '<', $end->toDateString())
            ->get(['id', 'user_name', 'hours']);

        $createdToAcceptor = $created->filter(fn (RedmineIssue $issue): bool => RedmineIssue::isAcceptor($issue->assignee_name))->count();

        return [
            'week_start' => $start->toDateString(),
            'week_end' => $end->subDay()->toDateString(),
            'created' => $created->count(),
            'closed' => $closed->count(),
            'net_flow' => $created->count() - $closed->count(),
            'created_by_project' => $this->countsDesc($created->countBy('project_identifier')),
            'closed_by_project' => $this->countsDesc($closed->countBy('project_identifier')),
            'wenhao_throughput' => $closed->filter(fn (RedmineIssue $issue): bool => RedmineIssue::isAcceptor($issue->assignee_name))->count(),
            'advanced_to_verify' => $this->tracksStatusChangesDuring($end) ? $advanced->count() : null,
            'advanced_to_verify_by_assignee' => $this->countsDesc($advanced->countBy(
                fn (RedmineStatusChange $change): string => ($change->previous_assignee_name ?: $change->assignee_name) ?: '(未指派)'
            )),
            'hours_logged' => round((float) $timeEntries->sum('hours'), 2),
            'hours_by_user' => $timeEntries
                ->groupBy('user_name')
                ->map(fn (Collection $entries): float => round((float) $entries->sum('hours'), 2))
                ->sortDesc()
                ->all(),
            'created_assigned_to_acceptor' => $createdToAcceptor,
            'inflow_to_wenhao_ratio' => $created->isEmpty() ? null : round($createdToAcceptor / $created->count(), 4),
        ];
    }

    /**
     * Record a total plus its dimensions; dimensions recorded earlier for the same period but now absent drop to 0.
     *
     * @param  array<string, int|float>  $dimensions
     */
    protected function recordWithDimensions(string $key, int|float $total, array $dimensions, CarbonInterface $period): void
    {
        $this->recorder->record($key, $total, $period);

        $stale = MetricValue::query()
            ->where('metric_key', $key)
            ->whereDate('period_start', $period->toDateString())
            ->where('dimension', '!=', '')
            ->pluck('dimension')
            ->diff(array_keys($dimensions));

        foreach ([...$dimensions, ...$stale->mapWithKeys(fn (string $dimension): array => [$dimension => 0])->all()] as $dimension => $value) {
            $this->recorder->record($key, $value, $period, (string) $dimension);
        }
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $groups
     * @return array<string, int>
     */
    protected function byProject(Collection $groups, string $field): array
    {
        return $groups
            ->groupBy('project_identifier')
            ->mapWithKeys(fn (Collection $projectGroups, string $project): array => ['project:'.$project => (int) $projectGroups->sum($field)])
            ->all();
    }

    /**
     * @param  array<string, int|float>  $values
     * @return array<string, int|float>
     */
    protected function prefixed(string $prefix, array $values): array
    {
        return collect($values)->mapWithKeys(fn (int|float $value, string $name): array => [$prefix.':'.$name => $value])->all();
    }

    /**
     * @param  Collection<array-key, int>  $counts
     * @return array<string, int>
     */
    protected function countsDesc(Collection $counts): array
    {
        return $counts->sortDesc()->all();
    }

    /**
     * Status changes are only observed from the first successful issue sync onwards (Redmine's API has no cheap
     * history), so weeks that ended before then have no data — reported as null, never as 0.
     */
    public function tracksStatusChangesDuring(CarbonInterface $weekEnd): bool
    {
        $firstSync = SyncRun::query()
            ->where('job', SyncJob::RedmineIssues)
            ->where('status', SyncStatus::Ok)
            ->min('started_at');

        return $firstSync !== null && CarbonImmutable::parse($firstSync)->lt($weekEnd);
    }
}
