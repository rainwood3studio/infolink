<?php

namespace App\Domain\Delivery;

use App\Models\RedmineIssue;
use App\Models\RedmineStatusSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Read model of delivery health for the dashboard page and the MCP tool. Definitions follow RedmineSnapshotter
 * (open / stalled / overdue) and DeliveryMetrics (驗證中 split: acceptor / other named assignee / unassigned).
 */
class DeliverySummary
{
    public function __construct(protected RedmineSnapshotter $snapshotter) {}

    /**
     * Current open-issue stock from the mirror. Projects are sorted by open count (desc); median age is in days since
     * created_on.
     *
     * @return array{open: int, by_status: array<string, int>, verifying: array{acceptor: int, others: int, unassigned: int}, stalled_30d: int, stalled_90d: int, overdue: int, unassigned: int, projects: list<array{identifier: string, name: string, open: int, verifying_acceptor: int, verifying_others: int, stalled_90d: int, median_age_days: ?int}>}
     */
    public function current(): array
    {
        $asOf = CarbonImmutable::now();
        $issues = $this->snapshotter->openIssues();
        $groups = $this->snapshotter->groups($asOf, $issues);

        $verifying = $groups->where('status', RedmineIssue::STATUS_VERIFYING);

        $projects = $issues
            ->groupBy('project_identifier')
            ->map(function (Collection $projectIssues, string $identifier) use ($asOf, $groups): array {
                $projectVerifying = $groups
                    ->where('project_identifier', $identifier)
                    ->where('status', RedmineIssue::STATUS_VERIFYING);

                return [
                    'identifier' => $identifier,
                    'name' => (string) $projectIssues->first()->project_name,
                    'open' => $projectIssues->count(),
                    'verifying_acceptor' => (int) $projectVerifying->filter(fn (array $group): bool => $this->bucket($group['assignee_name']) === 'acceptor')->sum('count'),
                    'verifying_others' => (int) $projectVerifying->filter(fn (array $group): bool => $this->bucket($group['assignee_name']) === 'others')->sum('count'),
                    'stalled_90d' => (int) $groups->where('project_identifier', $identifier)->sum('stalled_90d'),
                    'median_age_days' => $this->medianAgeDays($projectIssues, $asOf),
                ];
            })
            ->sortBy([['open', 'desc'], ['identifier', 'asc']])
            ->values()
            ->all();

        return [
            'open' => $issues->count(),
            'by_status' => $groups
                ->groupBy('status')
                ->map(fn (Collection $statusGroups): int => (int) $statusGroups->sum('count'))
                ->sortDesc()
                ->all(),
            'verifying' => [
                'acceptor' => (int) $verifying->filter(fn (array $group): bool => $this->bucket($group['assignee_name']) === 'acceptor')->sum('count'),
                'others' => (int) $verifying->filter(fn (array $group): bool => $this->bucket($group['assignee_name']) === 'others')->sum('count'),
                'unassigned' => (int) $verifying->where('assignee_name', '')->sum('count'),
            ],
            'stalled_30d' => (int) $groups->sum('stalled_30d'),
            'stalled_90d' => (int) $groups->sum('stalled_90d'),
            'overdue' => (int) $groups->sum('overdue'),
            'unassigned' => (int) $groups->where('assignee_name', '')->sum('count'),
            'projects' => $projects,
        ];
    }

    /**
     * Daily totals from the snapshots of the last $days days (oldest first; days without a snapshot are omitted).
     * Reconstructed days only know the open stock: their verifying / stalled values are 0.
     *
     * @return list<array{date: string, open: int, verifying_acceptor: int, verifying_others: int, stalled_90d: int, reconstructed: bool}>
     */
    public function trend(int $days = 90): array
    {
        $since = CarbonImmutable::today()->subDays(max($days, 1) - 1);

        return RedmineStatusSnapshot::query()
            ->whereDate('snapshot_date', '>=', $since->toDateString())
            ->orderBy('snapshot_date')
            ->get()
            ->groupBy(fn (RedmineStatusSnapshot $snapshot): string => $snapshot->snapshot_date->toDateString())
            ->map(function (Collection $rows, string $date): array {
                $verifying = $rows->where('status', RedmineIssue::STATUS_VERIFYING);

                return [
                    'date' => $date,
                    'open' => (int) $rows->sum('count'),
                    'verifying_acceptor' => (int) $verifying->filter(fn (RedmineStatusSnapshot $row): bool => $this->bucket($row->assignee_name) === 'acceptor')->sum('count'),
                    'verifying_others' => (int) $verifying->filter(fn (RedmineStatusSnapshot $row): bool => $this->bucket($row->assignee_name) === 'others')->sum('count'),
                    'stalled_90d' => (int) $rows->sum('stalled_90d'),
                    'reconstructed' => $rows->every(fn (RedmineStatusSnapshot $row): bool => $row->is_reconstructed),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return 'acceptor'|'others'|'unassigned'
     */
    protected function bucket(?string $assigneeName): string
    {
        return match (true) {
            $assigneeName === null || $assigneeName === '' => 'unassigned',
            RedmineIssue::isAcceptor($assigneeName) => 'acceptor',
            default => 'others',
        };
    }

    /**
     * @param  Collection<int, RedmineIssue>  $issues
     */
    protected function medianAgeDays(Collection $issues, CarbonImmutable $asOf): ?int
    {
        $median = $issues
            ->map(fn (RedmineIssue $issue): int => (int) floor($issue->created_on->diffInDays($asOf)))
            ->median();

        return $median === null ? null : (int) round($median);
    }
}
