<?php

namespace App\Domain\Delivery;

use App\Enums\SyncJob;
use App\Enums\SyncStatus;
use App\Models\RedmineIssue;
use App\Models\RedmineStatusSnapshot;
use App\Models\SyncRun;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Photographs the Redmine mirror into `redmine_status_snapshots` (the only source of delivery trends), and can
 * approximate earlier days from created_on / closed_on.
 *
 * Definitions (match the weekly Redmine report):
 * - open: is_closed = false, not soft-deleted.
 * - stalled_Nd: open and updated_on older than N days before the observation moment.
 * - overdue: open with a due_date before the observation date (issues without due_date never count).
 * - assignee_name '' = unassigned.
 */
class RedmineSnapshotter
{
    public const string RECONSTRUCTED_STATUS = '(重建)';

    /**
     * Replace the snapshot for a date (default today) with the current open-issue groups.
     *
     * The mirror only knows *current* state, so a past date gets today's state stamped on it. Any rows already stored
     * for that date — real or reconstructed — are replaced, so a date never mixes the two.
     *
     * @return int number of snapshot rows written
     *
     * @throws Throwable
     */
    public function snapshot(?CarbonInterface $date = null): int
    {
        $date = CarbonImmutable::instance($date ?? today())->startOfDay();

        $run = SyncRun::create([
            'job' => SyncJob::RedmineSnapshot,
            'started_at' => now(),
            'status' => SyncStatus::Running,
        ]);

        try {
            $groups = $this->groups(self::asOf($date));
            $timestamp = now();

            DB::transaction(function () use ($date, $groups, $timestamp): void {
                RedmineStatusSnapshot::query()->whereDate('snapshot_date', $date->toDateString())->delete();

                foreach ($groups->chunk(500) as $chunk) {
                    RedmineStatusSnapshot::query()->insert($chunk->map(fn (array $group): array => [
                        ...$group,
                        'snapshot_date' => $date->toDateString(),
                        'is_reconstructed' => false,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ])->values()->all());
                }
            });

            $run->update([
                'status' => SyncStatus::Ok,
                'finished_at' => now(),
                'stats' => [
                    'date' => $date->toDateString(),
                    'rows' => $groups->count(),
                    'open' => $groups->sum('count'),
                ],
            ]);
        } catch (Throwable $exception) {
            $run->update([
                'status' => SyncStatus::Failed,
                'finished_at' => now(),
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        return $groups->count();
    }

    /**
     * Approximate the daily open stock per project for [$from, $to] (default: up to yesterday).
     *
     * An issue counts as open at the end of day D when created_on ≤ D end and it was not yet closed. Historical status
     * and assignee are unknown, so rows carry status '(重建)', assignee '' and is_reconstructed = true (stalled and
     * overdue stay 0). An issue that is open now is treated as open since creation (a later reopen resets nothing in
     * Redmine's closed_on, so closed_on is ignored for it); a closed issue without closed_on is assumed closed at its
     * updated_on. Dates that already hold a real snapshot are skipped; earlier reconstructed rows are replaced.
     *
     * @return int number of days (re)written
     */
    public function rebuildHistory(CarbonInterface $from, ?CarbonInterface $to = null): int
    {
        $from = CarbonImmutable::instance($from)->startOfDay();
        $to = CarbonImmutable::instance($to ?? today()->subDay())->startOfDay();

        if ($from->gt($to)) {
            return 0;
        }

        $realDates = RedmineStatusSnapshot::query()
            ->where('is_reconstructed', false)
            ->whereDate('snapshot_date', '>=', $from->toDateString())
            ->whereDate('snapshot_date', '<=', $to->toDateString())
            ->pluck('snapshot_date')
            ->map(fn (CarbonInterface $date): string => $date->toDateString())
            ->unique()
            ->flip();

        /** @var list<array{project: string, created: int, closed: ?int}> $lifetimes */
        $lifetimes = RedmineIssue::query()
            ->get(['id', 'project_identifier', 'created_on', 'is_closed', 'closed_on', 'updated_on'])
            ->map(fn (RedmineIssue $issue): array => [
                'project' => $issue->project_identifier,
                'created' => $issue->created_on->getTimestamp(),
                'closed' => $issue->is_closed ? ($issue->closed_on ?? $issue->updated_on)->getTimestamp() : null,
            ])
            ->all();

        $days = 0;
        $timestamp = now();

        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $dateString = $day->toDateString();

            if ($realDates->has($dateString)) {
                continue;
            }

            $endOfDay = $day->endOfDay()->getTimestamp();
            $counts = [];

            foreach ($lifetimes as $lifetime) {
                if ($lifetime['created'] <= $endOfDay && ($lifetime['closed'] === null || $lifetime['closed'] > $endOfDay)) {
                    $counts[$lifetime['project']] = ($counts[$lifetime['project']] ?? 0) + 1;
                }
            }

            DB::transaction(function () use ($dateString, $counts, $timestamp): void {
                RedmineStatusSnapshot::query()
                    ->whereDate('snapshot_date', $dateString)
                    ->where('is_reconstructed', true)
                    ->delete();

                if ($counts === []) {
                    return;
                }

                RedmineStatusSnapshot::query()->insert(collect($counts)->map(fn (int $count, string $project): array => [
                    'snapshot_date' => $dateString,
                    'project_identifier' => $project,
                    'status' => self::RECONSTRUCTED_STATUS,
                    'assignee_name' => '',
                    'count' => $count,
                    'stalled_30d' => 0,
                    'stalled_90d' => 0,
                    'overdue' => 0,
                    'is_reconstructed' => true,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ])->values()->all());
            });

            $days++;
        }

        return $days;
    }

    /**
     * Open, non-deleted mirror issues with the columns the delivery read models need.
     *
     * @return Collection<int, RedmineIssue>
     */
    public function openIssues(): Collection
    {
        return RedmineIssue::query()
            ->open()
            ->get(['id', 'project_identifier', 'project_name', 'status', 'assignee_name', 'due_date', 'created_on', 'updated_on']);
    }

    /**
     * Open issues grouped by project / status / assignee, with stalled and overdue counts per group.
     *
     * @param  Collection<int, RedmineIssue>|null  $issues  pre-loaded open issues (defaults to openIssues())
     * @return Collection<int, array{project_identifier: string, status: string, assignee_name: string, count: int, stalled_30d: int, stalled_90d: int, overdue: int}>
     */
    public function groups(?CarbonInterface $asOf = null, ?Collection $issues = null): Collection
    {
        $asOf = CarbonImmutable::instance($asOf ?? now());
        $issues ??= $this->openIssues();

        return $issues
            ->groupBy(fn (RedmineIssue $issue): string => implode("\0", [$issue->project_identifier, $issue->status, $issue->assignee_name ?? '']))
            ->map(function (Collection $group) use ($asOf): array {
                /** @var RedmineIssue $first */
                $first = $group->first();

                return [
                    'project_identifier' => $first->project_identifier,
                    'status' => $first->status,
                    'assignee_name' => $first->assignee_name ?? '',
                    'count' => $group->count(),
                    'stalled_30d' => $group->filter(fn (RedmineIssue $issue): bool => self::isStalled($issue, $asOf, 30))->count(),
                    'stalled_90d' => $group->filter(fn (RedmineIssue $issue): bool => self::isStalled($issue, $asOf, 90))->count(),
                    'overdue' => $group->filter(fn (RedmineIssue $issue): bool => self::isOverdue($issue, $asOf))->count(),
                ];
            })
            ->sortBy(fn (array $group): string => implode("\0", [$group['project_identifier'], $group['status'], $group['assignee_name']]))
            ->values();
    }

    /**
     * The observation moment for a date: its end of day, but never later than now (so "today" means right now).
     */
    public static function asOf(?CarbonInterface $date = null): CarbonImmutable
    {
        if ($date === null) {
            return CarbonImmutable::now();
        }

        $endOfDay = CarbonImmutable::instance($date)->endOfDay();

        return $endOfDay->gt(now()) ? CarbonImmutable::now() : $endOfDay;
    }

    public static function isStalled(RedmineIssue $issue, CarbonInterface $asOf, int $days): bool
    {
        return $issue->updated_on->lt($asOf->copy()->subDays($days));
    }

    public static function isOverdue(RedmineIssue $issue, CarbonInterface $asOf): bool
    {
        return $issue->due_date !== null && $issue->due_date->toDateString() < $asOf->toDateString();
    }
}
