<?php

namespace App\Domain\Delivery;

use App\Domain\Delivery\Exceptions\RedmineRequestException;
use App\Domain\Delivery\Exceptions\RedmineUnavailableException;
use App\Enums\SyncJob;
use App\Enums\SyncStatus;
use App\Models\RedmineIssue;
use App\Models\RedmineStatusChange;
use App\Models\RedmineTimeEntry;
use App\Models\SyncRun;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Mirrors Redmine issues and time entries into `redmine_issues` / `redmine_time_entries` (one-way, read-only).
 *
 * Every call records a SyncRun. Failures (typically: off the company network) end as a `failed` run and are
 * only logged — they never throw to the caller/scheduler and never notify.
 */
class RedmineSync
{
    /** Incremental issue syncs re-read this much before the last successful run to absorb clock skew. */
    public const int INCREMENTAL_OVERLAP_MINUTES = 10;

    /** Incremental time-entry syncs re-read entries spent in this many trailing days. */
    public const int TIME_ENTRY_WINDOW_DAYS = 60;

    /** Fallback when neither the issue payload nor /issue_statuses.json says whether a status is closed. */
    public const array CLOSED_STATUS_NAMES = ['完成', '拒絕', '重覆建立'];

    /** @var array<int, string>|null project id => identifier, loaded once per run */
    private ?array $projectIdentifiers = null;

    /** @var list<int>|null closed status ids, loaded once per run (only if the payload lacks is_closed) */
    private ?array $closedStatusIds = null;

    public function __construct(private readonly RedmineClient $client) {}

    /**
     * Sync issues. Incremental by default (updated since the last OK run − overlap); the first run ever is full.
     * A full run also soft-deletes mirrored issues that Redmine no longer returns.
     */
    public function syncIssues(bool $full = false): SyncRun
    {
        $lastOk = SyncRun::latestFor(SyncJob::RedmineIssues, SyncStatus::Ok);
        $full = $full || $lastOk === null;

        return $this->record(SyncJob::RedmineIssues, function (array &$stats, Carbon $syncedAt) use ($full, $lastOk): void {
            $query = ['status_id' => '*', 'sort' => 'id'];
            $stats['mode'] = $full ? 'full' : 'incremental';

            if (! $full) {
                $since = $lastOk->started_at->copy()->subMinutes(self::INCREMENTAL_OVERLAP_MINUTES)->utc();
                $query['updated_on'] = '>='.$since->format('Y-m-d\TH:i:s\Z');
                $stats['since'] = $since->toIso8601ZuluString();
            }

            $seenIds = [];

            $this->client->paginate('issues.json', $query)
                ->chunk(RedmineClient::PAGE_SIZE)
                ->each(function ($chunk) use (&$stats, &$seenIds, $syncedAt): void {
                    $payloads = collect($chunk->all())->values();
                    $seenIds = [...$seenIds, ...$payloads->pluck('id')->all()];
                    $this->upsertIssues($payloads, $syncedAt, $stats);
                });

            $stats['fetched'] = count($seenIds);

            if ($full && $seenIds !== []) {
                $stats['deleted'] = $this->softDeleteMissingIssues($seenIds);
            }
        });
    }

    /**
     * Sync time entries. Incremental re-reads the trailing window (spent_on ≥ today − 60 days) and removes mirror
     * rows in that window that Redmine no longer returns; full does the same over all time. The first run is full.
     */
    public function syncTimeEntries(bool $full = false): SyncRun
    {
        $full = $full || SyncRun::latestFor(SyncJob::RedmineTime, SyncStatus::Ok) === null;

        return $this->record(SyncJob::RedmineTime, function (array &$stats, Carbon $syncedAt) use ($full): void {
            $query = [];
            $windowStart = null;
            $stats['mode'] = $full ? 'full' : 'incremental';

            if (! $full) {
                $windowStart = today()->subDays(self::TIME_ENTRY_WINDOW_DAYS);
                $query['spent_on'] = '>='.$windowStart->toDateString();
                $stats['since'] = $windowStart->toDateString();
            }

            $seenIds = [];

            $this->client->paginate('time_entries.json', $query)
                ->chunk(RedmineClient::PAGE_SIZE)
                ->each(function ($chunk) use (&$stats, &$seenIds, $syncedAt): void {
                    $payloads = collect($chunk->all())->values();
                    $seenIds = [...$seenIds, ...$payloads->pluck('id')->all()];
                    $this->upsertTimeEntries($payloads, $syncedAt, $stats);
                });

            $stats['fetched'] = count($seenIds);
            $stats['deleted'] = $this->deleteMissingTimeEntries($seenIds, $windowStart);
        });
    }

    /**
     * @param  Closure(array<string, mixed>&, Carbon): void  $work
     */
    private function record(SyncJob $job, Closure $work): SyncRun
    {
        $this->projectIdentifiers = null;
        $this->closedStatusIds = null;

        $run = SyncRun::create([
            'job' => $job,
            'started_at' => now(),
            'status' => SyncStatus::Running,
        ]);

        $stats = ['created' => 0, 'updated' => 0, 'restored' => 0, 'status_changes' => 0, 'deleted' => 0];

        try {
            $work($stats, now()->startOfSecond());

            $run->update(['status' => SyncStatus::Ok, 'finished_at' => now(), 'stats' => $stats]);
        } catch (Throwable $exception) {
            $context = ['job' => $job->value, 'sync_run_id' => $run->id, 'error' => $exception->getMessage()];

            if ($exception instanceof RedmineUnavailableException) {
                Log::warning('Redmine sync skipped: Redmine unavailable.', $context);
            } else {
                Log::error('Redmine sync failed.', [...$context, 'exception' => $exception]);
            }

            $run->update([
                'status' => SyncStatus::Failed,
                'finished_at' => now(),
                'stats' => $stats,
                'error' => Str::limit($exception->getMessage(), 2000),
            ]);
        }

        return $run->refresh();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $payloads
     * @param  array<string, mixed>  $stats
     */
    private function upsertIssues(Collection $payloads, Carbon $syncedAt, array &$stats): void
    {
        $rows = $payloads->map(fn (array $payload): array => $this->issueAttributes($payload, $syncedAt));

        $existing = RedmineIssue::withTrashed()
            ->whereIn('id', $rows->pluck('id'))
            ->get()
            ->keyBy('id');

        DB::transaction(function () use ($rows, $existing, &$stats): void {
            foreach ($rows as $attributes) {
                /** @var RedmineIssue|null $issue */
                $issue = $existing->get($attributes['id']);

                if ($issue === null) {
                    (new RedmineIssue($attributes))->save();
                    $stats['created']++;

                    continue;
                }

                if ((int) $issue->status_id !== $attributes['status_id']) {
                    RedmineStatusChange::create([
                        'issue_id' => $issue->id,
                        'project_identifier' => $attributes['project_identifier'],
                        'from_status' => $issue->status,
                        'to_status' => $attributes['status'],
                        'assignee_name' => $attributes['assignee_name'],
                        'previous_assignee_name' => $issue->assignee_name,
                        'changed_at' => $attributes['updated_on'],
                    ]);
                    $stats['status_changes']++;
                }

                $wasTrashed = $issue->trashed();
                $issue->fill($attributes);
                $changed = $issue->isDirty(array_keys(array_diff_key($attributes, ['synced_at' => true])));

                if ($wasTrashed) {
                    $issue->deleted_at = null;
                    $stats['restored']++;
                } elseif ($changed) {
                    $stats['updated']++;
                }

                $issue->save();
            }
        });
    }

    /**
     * @param  list<int>  $seenIds
     */
    private function softDeleteMissingIssues(array $seenIds): int
    {
        $missing = RedmineIssue::query()->pluck('id')->diff($seenIds);
        $deleted = 0;

        foreach ($missing->chunk(500) as $ids) {
            $deleted += RedmineIssue::query()->whereIn('id', $ids->values())->delete();
        }

        return $deleted;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $payloads
     * @param  array<string, mixed>  $stats
     */
    private function upsertTimeEntries(Collection $payloads, Carbon $syncedAt, array &$stats): void
    {
        $rows = $payloads->map(fn (array $payload): array => $this->timeEntryAttributes($payload, $syncedAt));

        $existing = RedmineTimeEntry::query()
            ->whereIn('id', $rows->pluck('id'))
            ->get()
            ->keyBy('id');

        DB::transaction(function () use ($rows, $existing, &$stats): void {
            foreach ($rows as $attributes) {
                /** @var RedmineTimeEntry|null $entry */
                $entry = $existing->get($attributes['id']);

                if ($entry === null) {
                    (new RedmineTimeEntry($attributes))->save();
                    $stats['created']++;

                    continue;
                }

                $entry->fill($attributes);

                if ($entry->isDirty(array_keys(array_diff_key($attributes, ['synced_at' => true])))) {
                    $stats['updated']++;
                }

                $entry->save();
            }
        });
    }

    /**
     * Delete mirrored time entries (spent in the synced window, or anywhere for a full run) not returned this run.
     *
     * @param  list<int>  $seenIds
     */
    private function deleteMissingTimeEntries(array $seenIds, ?Carbon $windowStart): int
    {
        $missing = RedmineTimeEntry::query()
            ->when($windowStart, fn ($query) => $query->whereDate('spent_on', '>=', $windowStart))
            ->pluck('id')
            ->diff($seenIds);

        $deleted = 0;

        foreach ($missing->chunk(500) as $ids) {
            $deleted += RedmineTimeEntry::query()->whereIn('id', $ids->values())->delete();
        }

        return $deleted;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function issueAttributes(array $payload, Carbon $syncedAt): array
    {
        $status = $payload['status'] ?? [];

        return [
            'id' => (int) $payload['id'],
            'project_id' => (int) $payload['project']['id'],
            'project_identifier' => $this->projectIdentifier($payload['project']),
            'project_name' => (string) ($payload['project']['name'] ?? ''),
            'tracker_id' => (int) ($payload['tracker']['id'] ?? 0),
            'tracker' => (string) ($payload['tracker']['name'] ?? ''),
            'status_id' => (int) ($status['id'] ?? 0),
            'status' => (string) ($status['name'] ?? ''),
            'is_closed' => $this->isClosedStatus($status),
            'priority_id' => (int) ($payload['priority']['id'] ?? 0),
            'priority' => (string) ($payload['priority']['name'] ?? ''),
            'assignee_id' => $payload['assigned_to']['id'] ?? null,
            'assignee_name' => $payload['assigned_to']['name'] ?? null,
            'author_id' => $payload['author']['id'] ?? null,
            'author_name' => $payload['author']['name'] ?? null,
            'subject' => Str::limit((string) ($payload['subject'] ?? ''), 509),
            'start_date' => $payload['start_date'] ?? null,
            'due_date' => $payload['due_date'] ?? null,
            'done_ratio' => (int) ($payload['done_ratio'] ?? 0),
            'estimated_hours' => $payload['estimated_hours'] ?? null,
            'created_on' => $this->toLocalTime($payload['created_on'] ?? null),
            'updated_on' => $this->toLocalTime($payload['updated_on'] ?? null),
            'closed_on' => $this->toLocalTime($payload['closed_on'] ?? null),
            'raw' => $payload,
            'synced_at' => $syncedAt,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function timeEntryAttributes(array $payload, Carbon $syncedAt): array
    {
        return [
            'id' => (int) $payload['id'],
            'issue_id' => $payload['issue']['id'] ?? null,
            'project_identifier' => $this->projectIdentifier($payload['project']),
            'user_id' => (int) ($payload['user']['id'] ?? 0),
            'user_name' => (string) ($payload['user']['name'] ?? ''),
            'activity' => (string) ($payload['activity']['name'] ?? ''),
            'hours' => (float) ($payload['hours'] ?? 0),
            'spent_on' => $payload['spent_on'],
            'comments' => ($payload['comments'] ?? '') !== '' ? $payload['comments'] : null,
            'updated_on' => $this->toLocalTime($payload['updated_on'] ?? $payload['created_on'] ?? null),
            'raw' => $payload,
            'synced_at' => $syncedAt,
        ];
    }

    /**
     * Issue/time-entry payloads only carry the project's id and name, so the identifier comes from /projects.json
     * (once per run), then /projects/{id}.json for projects missing there (closed/archived), then the mirror.
     *
     * @param  array{id: int, name?: string, identifier?: string}  $project
     */
    private function projectIdentifier(array $project): string
    {
        if (isset($project['identifier'])) {
            return (string) $project['identifier'];
        }

        $id = (int) $project['id'];

        if ($this->projectIdentifiers === null) {
            $this->projectIdentifiers = $this->client->paginate('projects.json')
                ->mapWithKeys(fn (array $row): array => [(int) $row['id'] => (string) $row['identifier']])
                ->all();
        }

        if (! isset($this->projectIdentifiers[$id])) {
            try {
                $identifier = $this->client->get("projects/{$id}.json")['project']['identifier'] ?? null;
            } catch (RedmineRequestException) {
                $identifier = null;
            }

            $this->projectIdentifiers[$id] = (string) ($identifier
                ?? RedmineIssue::withTrashed()->where('project_id', $id)->value('project_identifier')
                ?? "project-{$id}");
        }

        return $this->projectIdentifiers[$id];
    }

    /**
     * Redmine ≥ 5.1 includes `is_closed` in the issue's status; older versions need /issue_statuses.json,
     * and if even that is refused we fall back to the known closed status names.
     *
     * @param  array{id?: int, name?: string, is_closed?: bool}  $status
     */
    private function isClosedStatus(array $status): bool
    {
        if (array_key_exists('is_closed', $status)) {
            return (bool) $status['is_closed'];
        }

        if ($this->closedStatusIds === null) {
            try {
                $this->closedStatusIds = collect($this->client->get('issue_statuses.json')['issue_statuses'] ?? [])
                    ->filter(fn (array $row): bool => (bool) ($row['is_closed'] ?? false))
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->values()
                    ->all();
            } catch (RedmineRequestException) {
                $this->closedStatusIds = [];
            }
        }

        if ($this->closedStatusIds !== []) {
            return in_array((int) ($status['id'] ?? 0), $this->closedStatusIds, true);
        }

        return in_array($status['name'] ?? null, self::CLOSED_STATUS_NAMES, true);
    }

    /**
     * Redmine timestamps are UTC ISO-8601 (`2026-09-20T03:15:00Z`); the DB stores naive local time (Asia/Taipei).
     */
    private function toLocalTime(?string $value): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value)->setTimezone(config('app.timezone'));
    }
}
