<?php

namespace App\Domain\Engineering;

use App\Domain\Delivery\RedmineSync;
use App\Enums\SyncJob;
use App\Enums\SyncStatus;
use App\Models\Developer;
use App\Models\RedmineIssue;
use App\Models\RedmineStatusChange;
use App\Models\RedmineTimeEntry;
use App\Models\SyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What each developer did in Redmine, matched by `developers.redmine_name` (exact, or the start of the Redmine
 * display name): logged hours, issues handed over to 驗證中, issues closed without going through 驗證中 and, for
 * the acceptor (services.redmine.acceptor_name), issues accepted — plus the issues currently assigned to them.
 *
 * The mirror does not know who changed a status, so a change is attributed to the person the issue was assigned
 * to before it (else the assignee after it), like DeliveryMetrics does. Status changes are only observed from the
 * first successful issue sync on ({@see statusTrackedSince()}); hours are whatever people logged (often partial).
 */
class RedmineActivity
{
    /** Closed without being delivered: never counted as accepted or as closed without verification. */
    public const array REJECTED_STATUS_NAMES = ['拒絕', '重覆建立'];

    /** Open issues assigned to someone and not updated for this many days count as stalled. */
    public const int STALLED_DAYS = 30;

    /**
     * @param  Collection<int, Developer>  $developers
     * @return array<int, array{redmine_name:string, is_acceptor:bool, hours:float, hours_days:int, issue_ids:list<int>, advanced_to_verify:int, closed_without_verify:int, accepted:?int, open_assigned:int, verifying_assigned:int, stalled_30d:int, by_day:array<string, array{hours:float, issue_ids:list<int>}>}> developer id => activity
     */
    public function forDevelopers(Collection $developers, CarbonImmutable $from, CarbonImmutable $to): array
    {
        /** @var array<string, int> $names redmine name => developer id */
        $names = $developers
            ->filter(fn (Developer $developer): bool => filled($developer->redmine_name))
            ->mapWithKeys(fn (Developer $developer): array => [(string) $developer->redmine_name => $developer->id])
            ->all();

        if ($names === []) {
            return [];
        }

        $match = function (?string $name) use ($names): ?int {
            if ($name === null || $name === '') {
                return null;
            }

            foreach ($names as $redmineName => $developerId) {
                if ($name === $redmineName || str_starts_with($name, $redmineName)) {
                    return $developerId;
                }
            }

            return null;
        };

        $activity = [];

        foreach ($names as $redmineName => $developerId) {
            $isAcceptor = RedmineIssue::isAcceptor($redmineName);
            $activity[$developerId] = [
                'redmine_name' => $redmineName,
                'is_acceptor' => $isAcceptor,
                'hours' => 0.0,
                'hour_dates' => [],
                'worked' => [],
                'advanced' => [],
                'closed_without_verify' => [],
                'accepted' => $isAcceptor ? [] : null,
                'open_assigned' => 0,
                'verifying_assigned' => 0,
                'stalled_30d' => 0,
                'by_day' => [],
            ];
        }

        $touch = function (int $developerId, string $date, ?int $issueId, float $hours = 0.0) use (&$activity): void {
            $activity[$developerId]['by_day'][$date] ??= ['hours' => 0.0, 'issue_ids' => []];
            $activity[$developerId]['by_day'][$date]['hours'] += $hours;

            if ($issueId !== null) {
                $activity[$developerId]['by_day'][$date]['issue_ids'][$issueId] = $issueId;
                $activity[$developerId]['worked'][$issueId] = $issueId;
            }
        };

        RedmineTimeEntry::query()
            ->whereDate('spent_on', '>=', $from->toDateString())
            ->whereDate('spent_on', '<=', $to->toDateString())
            ->get(['id', 'issue_id', 'user_name', 'hours', 'spent_on'])
            ->each(function (RedmineTimeEntry $entry) use ($match, $touch, &$activity): void {
                if (($developerId = $match($entry->user_name)) === null) {
                    return;
                }

                $date = $entry->spent_on->toDateString();
                $activity[$developerId]['hours'] += (float) $entry->hours;
                $activity[$developerId]['hour_dates'][$date] = true;
                $touch($developerId, $date, $entry->issue_id === null ? null : (int) $entry->issue_id, (float) $entry->hours);
            });

        $closedStatuses = $this->closedStatusNames();

        RedmineStatusChange::query()
            ->whereBetween('changed_at', [$from, $to])
            ->orderBy('changed_at')
            ->orderBy('id')
            ->get()
            ->each(function (RedmineStatusChange $change) use ($match, $touch, $closedStatuses, &$activity): void {
                $developerId = $match($change->previous_assignee_name ?: $change->assignee_name);

                if ($developerId === null) {
                    return;
                }

                $bucket = match (true) {
                    $change->to_status === RedmineIssue::STATUS_VERIFYING => 'advanced',
                    ! in_array($change->to_status, $closedStatuses, true) => null,
                    $activity[$developerId]['is_acceptor'] => 'accepted',
                    $change->from_status !== RedmineIssue::STATUS_VERIFYING => 'closed_without_verify',
                    default => null,
                };

                if ($bucket === null) {
                    return;
                }

                $issueId = (int) $change->issue_id;
                $activity[$developerId][$bucket][$issueId] = $issueId;
                $touch($developerId, $change->changed_at->toDateString(), $issueId);
            });

        $stalledBefore = now()->subDays(self::STALLED_DAYS);

        RedmineIssue::query()
            ->open()
            ->whereNotNull('assignee_name')
            ->get(['id', 'assignee_name', 'status', 'updated_on'])
            ->each(function (RedmineIssue $issue) use ($match, $stalledBefore, &$activity): void {
                if (($developerId = $match($issue->assignee_name)) === null) {
                    return;
                }

                $activity[$developerId]['open_assigned']++;
                $activity[$developerId]['verifying_assigned'] += (int) ($issue->status === RedmineIssue::STATUS_VERIFYING);
                $activity[$developerId]['stalled_30d'] += (int) ($issue->updated_on !== null && $issue->updated_on->lt($stalledBefore));
            });

        return array_map(function (array $row): array {
            ksort($row['by_day']);

            return [
                'redmine_name' => $row['redmine_name'],
                'is_acceptor' => $row['is_acceptor'],
                'hours' => round($row['hours'], 2),
                'hours_days' => count($row['hour_dates']),
                'issue_ids' => collect($row['worked'])->sort()->values()->all(),
                'advanced_to_verify' => count($row['advanced']),
                'closed_without_verify' => count($row['closed_without_verify']),
                'accepted' => $row['accepted'] === null ? null : count($row['accepted']),
                'open_assigned' => $row['open_assigned'],
                'verifying_assigned' => $row['verifying_assigned'],
                'stalled_30d' => $row['stalled_30d'],
                'by_day' => array_map(fn (array $day): array => [
                    'hours' => round($day['hours'], 2),
                    'issue_ids' => collect($day['issue_ids'])->sort()->values()->all(),
                ], $row['by_day']),
            ];
        }, $activity);
    }

    /**
     * Status changes before this moment were never observed (the mirror only sees changes between syncs), so
     * 送驗／結案 counts for earlier dates are missing, not zero. Null until the first successful issue sync.
     */
    public function statusTrackedSince(): ?CarbonImmutable
    {
        $firstSync = SyncRun::query()
            ->where('job', SyncJob::RedmineIssues)
            ->where('status', SyncStatus::Ok)
            ->min('started_at');

        return $firstSync === null ? null : CarbonImmutable::parse($firstSync);
    }

    /**
     * Names of the closed statuses that mean "delivered" (完成…), from the mirror plus the known fallback names.
     *
     * @return list<string>
     */
    protected function closedStatusNames(): array
    {
        return RedmineIssue::withTrashed()
            ->where('is_closed', true)
            ->distinct()
            ->pluck('status')
            ->merge(RedmineSync::CLOSED_STATUS_NAMES)
            ->unique()
            ->diff(self::REJECTED_STATUS_NAMES)
            ->values()
            ->all();
    }
}
