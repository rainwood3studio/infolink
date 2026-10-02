<?php

namespace App\Domain\Engineering;

use App\Domain\Engineering\Exceptions\GithubRequestException;
use App\Domain\Engineering\Exceptions\GithubTimeoutException;
use App\Domain\Engineering\Exceptions\GithubUnavailableException;
use App\Enums\SyncJob;
use App\Enums\SyncStatus;
use App\Models\GithubBranch;
use App\Models\GithubCommit;
use App\Models\GithubIdentity;
use App\Models\GithubPullRequest;
use App\Models\GithubRepo;
use App\Models\GithubReview;
use App\Models\SyncRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Mirrors GitHub activity (repos, branch heads, commits, pull requests, reviews) into the github_* tables
 * (one-way, read-only), keeping a rolling window of services.github.retention_months: every successful run prunes
 * commits and pull requests that fell out of it.
 *
 * Repos of every configured organisation are listed newest-push first; only repos pushed since `since` are walked,
 * and incremental runs also skip repos not pushed since their last sync. Within a walked repo only new or moved
 * branches are walked (default branch first, so its name wins on shared commits), and a walk stops at the first
 * page holding only already-known shas. Large non-merge commits then get "effective" line counts that leave out
 * lock, generated and vendored files (a capped number of REST detail fetches per run; the rest follow next run).
 *
 * Every call records a SyncRun. A repo GitHub refuses (404/403) is logged and counted in `repo_errors`; GitHub
 * being unreachable or rate limited fails the run. Failures are only logged, never thrown to the caller.
 */
class GithubSync
{
    public const int REPO_PAGE_SIZE = 100;

    public const int BRANCH_PAGE_SIZE = 100;

    /** Kept below GraphQL's 100 max: history pages with additions/deletions time out (502) on large repos. */
    public const int COMMIT_PAGE_SIZE = 50;

    /**
     * A history page that still times out at this size is re-read without line counts; those commits get their
     * counts from the REST commit detail in the effective-lines pass instead.
     */
    public const int MIN_COMMIT_PAGE_SIZE = 5;

    public const int PULL_REQUEST_PAGE_SIZE = 50;

    public const int REVIEW_PAGE_SIZE = 50;

    public const int UPSERT_CHUNK = 200;

    /** Incremental runs walk a repo pushed after its last sync minus this overlap (clock skew between hosts). */
    public const int INCREMENTAL_OVERLAP_MINUTES = 10;

    /** A moved branch is re-read from its previous head's commit date minus this many days. */
    public const int BRANCH_OVERLAP_DAYS = 1;

    /** Incremental pull-request reads go back to the repo's last sync minus this many days. */
    public const int PULL_REQUEST_OVERLAP_DAYS = 1;

    /** Non-merge commits with more changed lines than this get effective line counts. */
    public const int LARGE_COMMIT_LINES = 400;

    /** At most this many REST commit-detail fetches per run (REST allows 5000/h). */
    public const int MAX_DETAIL_FETCHES = 1500;

    /** File-name globs (matched case-insensitively against the basename) left out of effective line counts. */
    public const array EXCLUDED_FILE_PATTERNS = [
        'composer.lock', 'package-lock.json', 'yarn.lock', 'pnpm-lock.yaml', 'pubspec.lock', 'Podfile.lock',
        'Gemfile.lock', 'poetry.lock', 'Cargo.lock', '*.lock',
        '*.min.js', '*.min.css', '*.map', '*.snap', '*.svg', '*.pbxproj',
        '*.g.dart', '*.freezed.dart', '*.gr.dart', '*.mocks.dart', '*.pb.go',
        '*.csv', '*.tsv',
    ];

    /** Directories (any path segment or segment pair, case-insensitive) whose files are left out of effective line counts. */
    public const array EXCLUDED_DIRECTORIES = [
        'vendor', 'node_modules', 'dist', 'build', 'public/build', '.dart_tool', 'Pods', 'generated', '__generated__',
        'golden', 'goldens', 'fixtures', '__fixtures__', 'testdata', 'snapshots', '__snapshots__',
    ];

    private const string VIEWER_ORGS_QUERY = <<<'GRAPHQL'
        query { viewer { organizations(first: 100) { nodes { login } } } }
        GRAPHQL;

    private const string REPOS_QUERY = <<<'GRAPHQL'
        query($login: String!, $first: Int!, $after: String) {
          organization(login: $login) {
            repositories(first: $first, after: $after, orderBy: {field: PUSHED_AT, direction: DESC}) {
              pageInfo { hasNextPage endCursor }
              nodes { databaseId name nameWithOwner isPrivate isArchived pushedAt defaultBranchRef { name } }
            }
          }
        }
        GRAPHQL;

    private const string BRANCHES_QUERY = <<<'GRAPHQL'
        query($owner: String!, $name: String!, $first: Int!, $after: String) {
          repository(owner: $owner, name: $name) {
            refs(refPrefix: "refs/heads/", first: $first, after: $after) {
              pageInfo { hasNextPage endCursor }
              nodes { name target { ... on Commit { oid committedDate } } }
            }
          }
        }
        GRAPHQL;

    private const string HISTORY_QUERY = <<<'GRAPHQL'
        query($owner: String!, $name: String!, $oid: GitObjectID!, $since: GitTimestamp, $first: Int!, $after: String) {
          repository(owner: $owner, name: $name) {
            object(oid: $oid) {
              ... on Commit {
                history(first: $first, after: $after, since: $since) {
                  pageInfo { hasNextPage endCursor }
                  nodes {
                    oid authoredDate committedDate message additions deletions changedFilesIfAvailable
                    parents { totalCount }
                    author { name email user { login } }
                  }
                }
              }
            }
          }
        }
        GRAPHQL;

    /** HISTORY_QUERY without line counts, for pages GitHub cannot compute diffs for in time. */
    private const string HISTORY_LITE_QUERY = <<<'GRAPHQL'
        query($owner: String!, $name: String!, $oid: GitObjectID!, $since: GitTimestamp, $first: Int!, $after: String) {
          repository(owner: $owner, name: $name) {
            object(oid: $oid) {
              ... on Commit {
                history(first: $first, after: $after, since: $since) {
                  pageInfo { hasNextPage endCursor }
                  nodes {
                    oid authoredDate committedDate message
                    parents { totalCount }
                    author { name email user { login } }
                  }
                }
              }
            }
          }
        }
        GRAPHQL;

    private const string PULL_REQUESTS_QUERY = <<<'GRAPHQL'
        query($owner: String!, $name: String!, $first: Int!, $reviews: Int!, $after: String) {
          repository(owner: $owner, name: $name) {
            pullRequests(first: $first, after: $after, orderBy: {field: UPDATED_AT, direction: DESC}) {
              pageInfo { hasNextPage endCursor }
              nodes {
                databaseId number title body state isDraft createdAt mergedAt closedAt updatedAt additions deletions
                headRefName baseRefName author { login } mergedBy { login }
                reviews(first: $reviews) { nodes { databaseId state submittedAt author { login } } }
              }
            }
          }
        }
        GRAPHQL;

    /** @var array<string, GithubIdentity> identity key => identity, cached per run */
    private array $identities = [];

    private Carbon $since;

    public function __construct(
        private readonly GithubClient $client,
        private readonly CommitMessageParser $parser,
    ) {}

    /**
     * Sync every configured organisation. Incremental by default; `$full` re-walks every branch of every repo
     * pushed inside the retention window and re-reads all pull requests updated inside it.
     */
    public function sync(bool $full = false): SyncRun
    {
        $this->identities = [];
        $this->since = self::windowStart();

        $run = SyncRun::create([
            'job' => SyncJob::GithubActivity,
            'started_at' => now(),
            'status' => SyncStatus::Running,
        ]);

        $stats = [
            'mode' => $full ? 'full' : 'incremental',
            'repos' => 0,
            'repo_errors' => 0,
            'branches_walked' => 0,
            'commits_created' => 0,
            'commits_updated' => 0,
            'prs' => 0,
            'reviews' => 0,
            'identities_created' => 0,
            'effective_fetched' => 0,
            'effective_pending' => 0,
            'pruned_commits' => 0,
            'pruned_prs' => 0,
        ];

        try {
            $syncedAt = now()->startOfSecond();

            foreach ($this->organisations() as $organisation) {
                foreach ($this->reposToWalk($organisation, $full) as $repo) {
                    try {
                        $this->syncRepo($repo, $full, $syncedAt, $stats);
                        $stats['repos']++;
                    } catch (GithubRequestException $exception) {
                        $stats['repo_errors']++;
                        Log::warning('GitHub sync skipped a repo GitHub refused.', [
                            'repo' => $repo->full_name,
                            'sync_run_id' => $run->id,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }
            }

            $this->fetchEffectiveLines($stats);
            $stats = [...$stats, ...$this->prune()];

            $run->update(['status' => SyncStatus::Ok, 'finished_at' => now(), 'stats' => $stats]);
        } catch (Throwable $exception) {
            $context = ['job' => SyncJob::GithubActivity->value, 'sync_run_id' => $run->id, 'error' => $exception->getMessage()];

            if ($exception instanceof GithubUnavailableException) {
                Log::warning('GitHub sync skipped: GitHub unavailable.', $context);
            } else {
                Log::error('GitHub sync failed.', [...$context, 'exception' => $exception]);
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
     * Start of the retention window: midnight (app time) services.github.retention_months ago.
     */
    public static function windowStart(): Carbon
    {
        return now(config('app.timezone'))
            ->subMonthsNoOverflow(max(1, (int) config('services.github.retention_months', 1)))
            ->startOfDay();
    }

    /**
     * Delete commits authored before the window and pull requests with no activity (opened/merged/closed) inside
     * it; their reviews go with them. Repos, branches and identities (and their developer mapping) are kept.
     *
     * @return array{pruned_commits: int, pruned_prs: int}
     */
    public function prune(): array
    {
        $start = self::windowStart();

        return [
            'pruned_commits' => GithubCommit::query()->where('authored_at', '<', $start)->delete(),
            'pruned_prs' => GithubPullRequest::query()
                ->where('opened_at', '<', $start)
                ->where(fn ($query) => $query->whereNull('merged_at')->orWhere('merged_at', '<', $start))
                ->where(fn ($query) => $query->whereNull('closed_at')->orWhere('closed_at', '<', $start))
                ->delete(),
        ];
    }

    /**
     * Whether a file is a lock file, minified/generated output or vendored code (left out of effective lines).
     */
    public static function isExcludedPath(string $path): bool
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        $basename = mb_strtolower(basename($path));

        foreach (self::EXCLUDED_FILE_PATTERNS as $pattern) {
            if (fnmatch(mb_strtolower($pattern), $basename)) {
                return true;
            }
        }

        $directory = '/'.mb_strtolower(dirname($path)).'/';

        foreach (self::EXCLUDED_DIRECTORIES as $excluded) {
            if (str_contains($directory, '/'.mb_strtolower($excluded).'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function organisations(): array
    {
        $configured = collect(explode(',', (string) config('services.github.orgs')))
            ->map(fn (string $login): string => trim($login))
            ->filter()
            ->values()
            ->all();

        if ($configured !== []) {
            return $configured;
        }

        return collect($this->client->graphql(self::VIEWER_ORGS_QUERY)['viewer']['organizations']['nodes'] ?? [])
            ->pluck('login')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Upsert the organisation's repos (newest push first, stopping at the first one pushed before `since`) and
     * return those that need walking.
     *
     * @return list<GithubRepo>
     */
    private function reposToWalk(string $organisation, bool $full): array
    {
        $walk = [];
        $after = null;

        do {
            $connection = $this->client->graphql(self::REPOS_QUERY, [
                'login' => $organisation,
                'first' => self::REPO_PAGE_SIZE,
                'after' => $after,
            ])['organization']['repositories'] ?? [];

            $reachedOld = false;

            foreach ($connection['nodes'] ?? [] as $node) {
                $repo = $this->upsertRepo($node);

                if ($repo->pushed_at === null || $repo->pushed_at->lt($this->since)) {
                    $reachedOld = true;

                    continue;
                }

                $unchanged = $repo->last_synced_at !== null
                    && $repo->pushed_at->lte($repo->last_synced_at->copy()->subMinutes(self::INCREMENTAL_OVERLAP_MINUTES));

                if ($full || ! $unchanged) {
                    $walk[] = $repo;
                }
            }

            $after = $connection['pageInfo']['endCursor'] ?? null;
        } while (! $reachedOld && ($connection['pageInfo']['hasNextPage'] ?? false) && $after !== null);

        return $walk;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function upsertRepo(array $node): GithubRepo
    {
        $repo = GithubRepo::query()->findOrNew((int) $node['databaseId']);
        $repo->id = (int) $node['databaseId'];
        $repo->fill([
            'owner' => Str::before((string) $node['nameWithOwner'], '/'),
            'name' => (string) $node['name'],
            'full_name' => (string) $node['nameWithOwner'],
            'default_branch' => $node['defaultBranchRef']['name'] ?? null,
            'is_private' => (bool) ($node['isPrivate'] ?? true),
            'is_archived' => (bool) ($node['isArchived'] ?? false),
            'pushed_at' => $this->toLocalTime($node['pushedAt'] ?? null),
        ])->save();

        return $repo;
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function syncRepo(GithubRepo $repo, bool $full, Carbon $syncedAt, array &$stats): void
    {
        $this->syncBranchesAndCommits($repo, $full, $syncedAt, $stats);
        $this->syncPullRequests($repo, $full, $stats);

        $repo->forceFill(['last_synced_at' => $syncedAt])->save();
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function syncBranchesAndCommits(GithubRepo $repo, bool $full, Carbon $syncedAt, array &$stats): void
    {
        $heads = $this->fetchBranchHeads($repo);
        $existing = $repo->branches()->get()->keyBy('name');

        $order = collect($heads)->keys()
            ->sortBy(fn (string $name): int => $name === $repo->default_branch ? 0 : 1)
            ->values();

        $storedShas = $full ? [] : array_flip($repo->commits()->pluck('sha')->all());
        $seenShas = [];
        /** @var array<string, array{node: array<string, mixed>, branch: string}> $commits */
        $commits = [];
        $walked = [];

        foreach ($order as $name) {
            /** @var GithubBranch|null $branch */
            $branch = $existing->get($name);
            $head = $heads[$name];

            if (! $full && $branch !== null && $branch->head_sha === $head['oid']) {
                continue;
            }

            $since = $this->since;

            if (! $full && $branch?->head_committed_at !== null) {
                $since = $branch->head_committed_at->copy()->subDays(self::BRANCH_OVERLAP_DAYS)->max($this->since);
            }

            foreach ($this->walkHistory($repo, $head['oid'], $since, $storedShas + $seenShas) as $node) {
                $seenShas[$node['oid']] = true;
                $commits[$node['oid']] ??= ['node' => $node, 'branch' => $name];
            }

            $walked[] = $name;
            $stats['branches_walked']++;
        }

        DB::transaction(function () use ($repo, $full, $commits, $heads, $existing, $walked, $syncedAt, &$stats): void {
            $this->storeCommits($repo, $full, $commits, $stats);
            $this->flushIdentities();

            foreach ($heads as $name => $head) {
                $branch = $existing->get($name) ?? new GithubBranch(['github_repo_id' => $repo->id, 'name' => $name]);
                $branch->fill([
                    'head_sha' => $head['oid'],
                    'head_committed_at' => $this->toLocalTime($head['committedDate']),
                ]);

                if (in_array($name, $walked, true)) {
                    $branch->synced_at = $syncedAt;
                }

                $branch->save();
            }

            $gone = $existing->keys()->diff(array_keys($heads));

            if ($gone->isNotEmpty()) {
                $repo->branches()->whereIn('name', $gone->values())->delete();
            }
        });
    }

    /**
     * @return array<string, array{oid: string, committedDate: ?string}> branch name => head
     */
    private function fetchBranchHeads(GithubRepo $repo): array
    {
        $heads = [];
        $after = null;

        do {
            $connection = $this->client->graphql(self::BRANCHES_QUERY, [
                'owner' => $repo->owner,
                'name' => $repo->name,
                'first' => self::BRANCH_PAGE_SIZE,
                'after' => $after,
            ])['repository']['refs'] ?? [];

            foreach ($connection['nodes'] ?? [] as $node) {
                if (isset($node['target']['oid'])) {
                    $heads[(string) $node['name']] = [
                        'oid' => (string) $node['target']['oid'],
                        'committedDate' => $node['target']['committedDate'] ?? null,
                    ];
                }
            }

            $after = $connection['pageInfo']['endCursor'] ?? null;
        } while (($connection['pageInfo']['hasNextPage'] ?? false) && $after !== null);

        return $heads;
    }

    /**
     * Walk a branch's history (newest first) back to `$since`, stopping after a page that held only known shas.
     * When GitHub times out computing line counts, the page size is halved for the rest of the branch; below
     * MIN_COMMIT_PAGE_SIZE the page is read without line counts (those nodes have no `additions` key).
     *
     * @param  array<string, true>  $knownShas
     * @return list<array<string, mixed>> commit nodes
     */
    private function walkHistory(GithubRepo $repo, string $oid, Carbon $since, array $knownShas): array
    {
        $nodes = [];
        $after = null;
        $pageSize = self::COMMIT_PAGE_SIZE;

        while (true) {
            $variables = [
                'owner' => $repo->owner,
                'name' => $repo->name,
                'oid' => $oid,
                'since' => $since->copy()->utc()->toIso8601ZuluString(),
                'first' => $pageSize,
                'after' => $after,
            ];

            try {
                $connection = $this->client->graphql(self::HISTORY_QUERY, $variables)['repository']['object']['history'] ?? [];
            } catch (GithubTimeoutException) {
                if ($pageSize > self::MIN_COMMIT_PAGE_SIZE) {
                    $pageSize = max(self::MIN_COMMIT_PAGE_SIZE, intdiv($pageSize, 2));

                    continue;
                }

                Log::info('GitHub history page timed out; reading it without line counts.', [
                    'repo' => $repo->full_name,
                    'after' => $after,
                ]);
                $connection = $this->client->graphql(self::HISTORY_LITE_QUERY, $variables)['repository']['object']['history'] ?? [];
            }

            $onlyKnown = true;

            foreach ($connection['nodes'] ?? [] as $node) {
                $onlyKnown = $onlyKnown && isset($knownShas[$node['oid']]);
                $nodes[] = $node;
            }

            $after = $connection['pageInfo']['endCursor'] ?? null;

            if ($onlyKnown || ! ($connection['pageInfo']['hasNextPage'] ?? false) || $after === null) {
                return $nodes;
            }
        }
    }

    /**
     * Upsert the walked commits. The branch of an existing commit is only rewritten on a full run (whose walk
     * starts at the default branch); incremental runs keep the branch it was first seen on.
     *
     * @param  array<string, array{node: array<string, mixed>, branch: string}>  $commits
     * @param  array<string, mixed>  $stats
     */
    private function storeCommits(GithubRepo $repo, bool $full, array $commits, array &$stats): void
    {
        $now = now();
        $rows = [];

        foreach ($commits as $sha => ['node' => $node, 'branch' => $branch]) {
            $authoredAt = $this->toLocalTime($node['authoredDate'] ?? null);

            if ($authoredAt === null || $authoredAt->lt($this->since)) {
                continue;
            }

            $parsed = $this->parser->parse((string) ($node['message'] ?? ''));
            $author = $node['author'] ?? [];

            $rows[] = [
                'github_repo_id' => $repo->id,
                'sha' => $sha,
                'github_identity_id' => $this->identityId(
                    $author['user']['login'] ?? null,
                    $author['email'] ?? null,
                    $author['name'] ?? null,
                    $authoredAt,
                    $stats,
                ),
                'branch' => $branch,
                'authored_at' => $authoredAt,
                'committed_at' => $this->toLocalTime($node['committedDate'] ?? null),
                'subject' => $parsed['subject'],
                'message' => (string) ($node['message'] ?? ''),
                'type' => $parsed['type']->value,
                'scope' => $parsed['scope'],
                'redmine_issue_ids' => json_encode($parsed['redmine_issue_ids']),
                'is_merge' => (int) ($node['parents']['totalCount'] ?? 1) > 1,
                'is_ai_assisted' => $parsed['is_ai_assisted'],
                'additions' => (int) ($node['additions'] ?? 0),
                'deletions' => (int) ($node['deletions'] ?? 0),
                'changed_files' => $node['changedFilesIfAvailable'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $update = [
            'github_identity_id', 'authored_at', 'committed_at', 'subject', 'message', 'type', 'scope',
            'redmine_issue_ids', 'is_merge', 'is_ai_assisted', 'additions', 'deletions', 'changed_files', 'updated_at',
        ];

        if ($full) {
            $update[] = 'branch';
        }

        foreach (array_chunk($rows, self::UPSERT_CHUNK) as $chunk) {
            $existing = $repo->commits()->whereIn('sha', array_column($chunk, 'sha'))->count();

            GithubCommit::query()->upsert($chunk, ['github_repo_id', 'sha'], $update);

            $stats['commits_updated'] += $existing;
            $stats['commits_created'] += count($chunk) - $existing;
        }
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function syncPullRequests(GithubRepo $repo, bool $full, array &$stats): void
    {
        $cutoff = $full || $repo->last_synced_at === null
            ? $this->since
            : $repo->last_synced_at->copy()->subDays(self::PULL_REQUEST_OVERLAP_DAYS);

        $after = null;

        do {
            $connection = $this->client->graphql(self::PULL_REQUESTS_QUERY, [
                'owner' => $repo->owner,
                'name' => $repo->name,
                'first' => self::PULL_REQUEST_PAGE_SIZE,
                'reviews' => self::REVIEW_PAGE_SIZE,
                'after' => $after,
            ])['repository']['pullRequests'] ?? [];

            $reachedOld = false;
            $fresh = [];

            foreach ($connection['nodes'] ?? [] as $node) {
                $updatedAt = $this->toLocalTime($node['updatedAt'] ?? null);

                if ($updatedAt === null || $updatedAt->lt($cutoff)) {
                    $reachedOld = true;

                    break;
                }

                $fresh[] = $node;
            }

            DB::transaction(function () use ($repo, $fresh, &$stats): void {
                foreach ($fresh as $node) {
                    $this->upsertPullRequest($repo, $node, $stats);
                }

                $this->flushIdentities();
            });

            $after = $connection['pageInfo']['endCursor'] ?? null;
        } while (! $reachedOld && ($connection['pageInfo']['hasNextPage'] ?? false) && $after !== null);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $stats
     */
    private function upsertPullRequest(GithubRepo $repo, array $node, array &$stats): void
    {
        $openedAt = $this->toLocalTime($node['createdAt']);
        $mergedAt = $this->toLocalTime($node['mergedAt'] ?? null);
        $title = (string) ($node['title'] ?? '');

        $pullRequest = GithubPullRequest::query()->findOrNew((int) $node['databaseId']);
        $pullRequest->id = (int) $node['databaseId'];
        $pullRequest->fill([
            'github_repo_id' => $repo->id,
            'number' => (int) $node['number'],
            'github_identity_id' => $this->identityId($node['author']['login'] ?? null, null, null, $openedAt, $stats),
            'merged_by_identity_id' => $mergedAt === null
                ? null
                : $this->identityId($node['mergedBy']['login'] ?? null, null, null, $mergedAt, $stats),
            'title' => Str::limit($title, 497),
            'state' => (string) $node['state'],
            'is_draft' => (bool) ($node['isDraft'] ?? false),
            'head_ref' => $node['headRefName'] ?? null,
            'base_ref' => $node['baseRefName'] ?? null,
            'opened_at' => $openedAt,
            'merged_at' => $mergedAt,
            'closed_at' => $this->toLocalTime($node['closedAt'] ?? null),
            'additions' => (int) ($node['additions'] ?? 0),
            'deletions' => (int) ($node['deletions'] ?? 0),
            'redmine_issue_ids' => $this->parser->issueIds($title."\n".($node['body'] ?? '')),
        ])->save();

        $stats['prs']++;

        foreach ($node['reviews']['nodes'] ?? [] as $review) {
            if (($review['databaseId'] ?? null) === null || ($review['state'] ?? null) === 'PENDING') {
                continue;
            }

            $submittedAt = $this->toLocalTime($review['submittedAt'] ?? null);

            GithubReview::query()->updateOrCreate(['id' => (int) $review['databaseId']], [
                'github_pull_request_id' => $pullRequest->id,
                'github_identity_id' => $this->identityId($review['author']['login'] ?? null, null, null, $submittedAt ?? $openedAt, $stats),
                'state' => (string) $review['state'],
                'submitted_at' => $submittedAt,
            ]);

            $stats['reviews']++;
        }
    }

    /**
     * Resolve (or create) the identity for a GitHub login, else a git email, widening its seen-at range.
     * A new email-only identity inherits the developer of a login identity with the same email.
     *
     * @param  array<string, mixed>  $stats
     */
    private function identityId(?string $login, ?string $email, ?string $name, Carbon $seenAt, array &$stats): ?int
    {
        $login = filled($login) ? $login : null;
        $email = filled($email) ? $email : null;

        if ($login === null && $email === null) {
            return null;
        }

        $key = GithubIdentity::keyFor($login, $email);
        $identity = $this->identities[$key] ??= GithubIdentity::query()->firstWhere('key', $key)
            ?? $this->createIdentity($key, $login, $email, $name, $seenAt, $stats);

        $identity->fill(array_filter(['login' => $login, 'email' => $email, 'name' => filled($name) ? $name : null]));

        if ($identity->first_seen_at === null || $seenAt->lt($identity->first_seen_at)) {
            $identity->first_seen_at = $seenAt;
        }

        if ($identity->last_seen_at === null || $seenAt->gt($identity->last_seen_at)) {
            $identity->last_seen_at = $seenAt;
        }

        return $identity->id;
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function createIdentity(string $key, ?string $login, ?string $email, ?string $name, Carbon $seenAt, array &$stats): GithubIdentity
    {
        $developerId = null;

        if ($login === null && $email !== null) {
            $developerId = GithubIdentity::query()
                ->where('key', 'like', 'login:%')
                ->whereNotNull('developer_id')
                ->whereRaw('lower(email) = ?', [mb_strtolower($email)])
                ->value('developer_id');
        }

        $stats['identities_created']++;

        return GithubIdentity::query()->create([
            'key' => $key,
            'developer_id' => $developerId,
            'login' => $login,
            'email' => $email,
            'name' => filled($name) ? $name : null,
            'first_seen_at' => $seenAt,
            'last_seen_at' => $seenAt,
        ]);
    }

    private function flushIdentities(): void
    {
        foreach ($this->identities as $identity) {
            if ($identity->isDirty()) {
                $identity->save();
            }
        }
    }

    /**
     * Fill effective line counts for large non-merge commits (newest first, capped per run) from REST commit
     * details, summing only files that are not lock/generated/vendored. GitHub lists at most 300 files per commit.
     *
     * @param  array<string, mixed>  $stats
     */
    private function fetchEffectiveLines(array &$stats): void
    {
        $pending = fn () => GithubCommit::query()
            ->where('is_merge', false)
            ->whereNull('effective_additions')
            ->where(fn ($query) => $query
                ->whereRaw('additions + deletions > ?', [self::LARGE_COMMIT_LINES])
                ->orWhereNull('changed_files'));

        $pending()
            ->with('repo')
            ->orderByDesc('authored_at')
            ->limit(self::MAX_DETAIL_FETCHES)
            ->get()
            ->each(function (GithubCommit $commit) use (&$stats): void {
                try {
                    $commit->forceFill($this->effectiveLines($commit))->save();
                } catch (GithubTimeoutException $exception) {
                    Log::info('GitHub commit detail timed out; retrying next run.', [
                        'repo' => $commit->repo->full_name,
                        'sha' => $commit->sha,
                        'error' => $exception->getMessage(),
                    ]);

                    return;
                }

                $stats['effective_fetched']++;
            });

        $stats['effective_pending'] = $pending()->count();
    }

    /**
     * Effective counts from the REST commit detail. Commits stored without counts (changed_files null) also get
     * their raw counts and file count from it.
     *
     * @return array<string, int> attributes to fill
     */
    private function effectiveLines(GithubCommit $commit): array
    {
        try {
            $detail = $this->client->get("repos/{$commit->repo->full_name}/commits/{$commit->sha}");
        } catch (GithubRequestException $exception) {
            Log::warning('GitHub commit detail refused; keeping raw line counts.', [
                'repo' => $commit->repo->full_name,
                'sha' => $commit->sha,
                'error' => $exception->getMessage(),
            ]);

            return ['effective_additions' => $commit->additions, 'effective_deletions' => $commit->deletions];
        }

        /** @var Collection<int, array{filename: string, additions?: int, deletions?: int}> $files */
        $files = collect($detail['files'] ?? []);
        $kept = $files->reject(fn (array $file): bool => self::isExcludedPath((string) ($file['filename'] ?? '')));
        $attributes = [
            'effective_additions' => (int) $kept->sum('additions'),
            'effective_deletions' => (int) $kept->sum('deletions'),
        ];

        if ($commit->changed_files === null) {
            $attributes['additions'] = (int) ($detail['stats']['additions'] ?? $files->sum('additions'));
            $attributes['deletions'] = (int) ($detail['stats']['deletions'] ?? $files->sum('deletions'));
            $attributes['changed_files'] = $files->count();
        }

        return $attributes;
    }

    /**
     * GitHub timestamps are UTC ISO-8601 (`2026-10-02T08:51:34Z`); the DB stores naive local time (Asia/Taipei).
     */
    private function toLocalTime(?string $value): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value)->setTimezone(config('app.timezone'));
    }
}
