<?php

namespace Tests\Feature\Domain\Engineering;

use App\Domain\Engineering\GithubSync;
use App\Enums\SyncJob;
use App\Enums\SyncStatus;
use App\Models\Developer;
use App\Models\GithubBranch;
use App\Models\GithubCommit;
use App\Models\GithubIdentity;
use App\Models\GithubPullRequest;
use App\Models\GithubRepo;
use App\Models\GithubReview;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * In-memory GitHub API for Http::fake(): GraphQL requests are answered by which connection the query asks for,
 * history is a fixed newest-first list per head oid (filtered by `since`), REST serves commit details.
 */
class FakeGithub
{
    /** @var array<string, list<array<string, mixed>>> org login => repository nodes (newest push first) */
    public array $repos = [];

    /** @var array<string, array<string, string>> repo full name => branch name => head oid */
    public array $branches = [];

    /** @var array<string, list<array<string, mixed>>> head oid => commit nodes, newest first */
    public array $histories = [];

    /** @var array<string, list<array<string, mixed>>> repo full name => pull request nodes (newest update first) */
    public array $pullRequests = [];

    /** @var array<string, list<array<string, mixed>>> sha => REST files */
    public array $files = [];

    /** @var list<string> repo full names GitHub answers NOT_FOUND for */
    public array $missingRepos = [];

    public ?int $failStatus = null;

    /** When set, only this many requests fail with $failStatus; later ones are answered normally. */
    public ?int $failCount = null;

    /** @var list<string> shas whose REST commit detail times out (HTTP 504) */
    public array $timeoutShas = [];

    /** History pages asking for line counts with more than this many commits time out (HTTP 502). */
    public ?int $historyTimeoutAbove = null;

    public function __construct()
    {
        Http::fake(fn (Request $request) => $this->respond($request));
    }

    /**
     * @return array<string, mixed>
     */
    public static function repo(int $id, string $fullName, string $pushedAt, string $defaultBranch = 'main'): array
    {
        return [
            'databaseId' => $id,
            'name' => explode('/', $fullName)[1],
            'nameWithOwner' => $fullName,
            'isPrivate' => true,
            'isArchived' => false,
            'pushedAt' => $pushedAt,
            'defaultBranchRef' => ['name' => $defaultBranch],
        ];
    }

    /**
     * @param  array{login?: ?string, email?: string, name?: string}  $author
     * @return array<string, mixed>
     */
    public static function commit(string $oid, string $date, string $message, array $author, int $additions = 10, int $deletions = 2, int $parents = 1): array
    {
        return [
            'oid' => $oid,
            'authoredDate' => $date,
            'committedDate' => $date,
            'message' => $message,
            'additions' => $additions,
            'deletions' => $deletions,
            'changedFilesIfAvailable' => 3,
            'parents' => ['totalCount' => $parents],
            'author' => [
                'name' => $author['name'] ?? 'Someone',
                'email' => $author['email'] ?? 'someone@example.com',
                'user' => isset($author['login']) ? ['login' => $author['login']] : null,
            ],
        ];
    }

    /**
     * @return list<string> the GraphQL connection asked for by each request, e.g. `history:<oid>`
     */
    public function graphqlCalls(): array
    {
        return collect(Http::recorded())
            ->map(fn (array $pair): Request => $pair[0])
            ->filter(fn (Request $request): bool => str_ends_with($request->url(), '/graphql'))
            ->map(fn (Request $request): string => $this->describe($request['query'], (array) ($request['variables'] ?? [])))
            ->values()
            ->all();
    }

    public function restCalls(): int
    {
        return collect(Http::recorded())
            ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), '/commits/'))
            ->count();
    }

    private function respond(Request $request): mixed
    {
        if ($this->failStatus !== null && ($this->failCount === null || $this->failCount-- > 0)) {
            return Http::response(['message' => 'Server Error'], $this->failStatus);
        }

        if (preg_match('#/repos/([^/]+/[^/]+)/commits/(\w+)$#', $request->url(), $matches) === 1) {
            if (in_array($matches[2], $this->timeoutShas, true)) {
                return Http::response('Gateway Timeout', 504);
            }

            return Http::response(['sha' => $matches[2], 'files' => $this->files[$matches[2]] ?? []]);
        }

        $variables = (array) ($request['variables'] ?? []);
        $fullName = isset($variables['owner']) ? $variables['owner'].'/'.$variables['name'] : null;

        if ($fullName !== null && in_array($fullName, $this->missingRepos, true)) {
            return Http::response([
                'data' => ['repository' => null],
                'errors' => [['type' => 'NOT_FOUND', 'message' => "Could not resolve to a Repository with the name '{$fullName}'."]],
            ]);
        }

        $withCounts = str_contains($request['query'], 'additions');

        if (str_contains($request['query'], 'history(') && $withCounts && $this->historyTimeoutAbove !== null
            && (int) $variables['first'] > $this->historyTimeoutAbove) {
            return Http::response('<html>502 Bad Gateway</html>', 502);
        }

        $data = match (strtok($this->describe($request['query'], $variables), ':')) {
            'viewer' => ['viewer' => ['organizations' => ['nodes' => collect(array_keys($this->repos))->map(fn ($login) => ['login' => $login])->all()]]],
            'repositories' => ['organization' => ['repositories' => $this->page($this->repos[$variables['login']] ?? [], $variables)]],
            'refs' => ['repository' => ['refs' => $this->page($this->branchNodes($fullName), $variables)]],
            'history' => ['repository' => ['object' => ['history' => $this->page($this->history($variables, $withCounts), $variables)]]],
            'pullRequests' => ['repository' => ['pullRequests' => $this->page($this->pullRequests[$fullName] ?? [], $variables)]],
        };

        return Http::response(['data' => $data]);
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function describe(string $query, array $variables): string
    {
        return match (true) {
            str_contains($query, 'viewer') => 'viewer',
            str_contains($query, 'repositories(') => 'repositories:'.$variables['login'],
            str_contains($query, 'refs(') => 'refs:'.$variables['owner'].'/'.$variables['name'],
            str_contains($query, 'history(') => 'history:'.$variables['oid'],
            str_contains($query, 'pullRequests(') => 'pullRequests:'.$variables['owner'].'/'.$variables['name'],
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function branchNodes(string $fullName): array
    {
        return collect($this->branches[$fullName] ?? [])
            ->map(fn (string $oid, string $name): array => [
                'name' => $name,
                'target' => ['oid' => $oid, 'committedDate' => $this->histories[$oid][0]['committedDate'] ?? '2026-09-01T00:00:00Z'],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return list<array<string, mixed>>
     */
    private function history(array $variables, bool $withCounts = true): array
    {
        return collect($this->histories[$variables['oid']] ?? [])
            ->filter(fn (array $node): bool => Carbon::parse($node['committedDate'])->gte(Carbon::parse($variables['since'])))
            ->map(fn (array $node): array => $withCounts ? $node : Arr::except($node, ['additions', 'deletions', 'changedFilesIfAvailable']))
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function page(array $nodes, array $variables): array
    {
        $offset = (int) ($variables['after'] ?? 0);
        $end = $offset + (int) $variables['first'];

        return [
            'pageInfo' => ['hasNextPage' => $end < count($nodes), 'endCursor' => (string) $end],
            'nodes' => array_slice($nodes, $offset, (int) $variables['first']),
        ];
    }
}

beforeEach(function () {
    config([
        'services.github.token' => 'test-token',
        'services.github.orgs' => 'acme',
        'services.github.retention_months' => 1,
    ]);

    Carbon::setTestNow('2026-10-01 12:00:00');

    $this->github = new FakeGithub;
    $this->github->repos['acme'] = [
        FakeGithub::repo(11, 'acme/app', '2026-09-30T08:00:00Z'),
        FakeGithub::repo(12, 'acme/legacy', '2025-11-01T00:00:00Z'),
    ];
    $this->github->branches['acme/app'] = ['feature/pay' => 'f1', 'main' => 'c3'];

    $kenneth = ['login' => 'kenneth', 'email' => 'Kenneth@infolink.test', 'name' => 'Kenneth'];
    $c3 = FakeGithub::commit('c3', '2026-09-20T02:00:00Z', "Merge branch 'feature/x'", $kenneth, 0, 0, parents: 2);
    $c2 = FakeGithub::commit('c2', '2026-09-10T02:00:00Z', "feat(pos): 作廢原因 (#2881)\n\nCo-Authored-By: Claude <noreply@anthropic.com>", ['email' => 'kenneth@infolink.test', 'name' => 'Kenneth (laptop)']);
    $c1 = FakeGithub::commit('c1', '2025-12-30T02:00:00Z', 'chore: old work', $kenneth);
    $c1['committedDate'] = '2026-01-05T02:00:00Z'; // rebased later: authored before `since`, so never stored
    $f1 = FakeGithub::commit('f1', '2026-09-25T02:00:00Z', 'fix: rounding', ['login' => 'howl', 'email' => 'howl@infolink.test']);

    $this->github->histories = [
        'c3' => [$c3, $c2, $c1],
        'f1' => [$f1, $c2, $c1],
    ];
});

afterEach(fn () => Carbon::setTestNow());

it('stores repos, branches and deduplicated commits on a full sync', function () {
    $developer = Developer::factory()->create();
    GithubIdentity::factory()->create([
        'key' => 'login:kenneth', 'login' => 'kenneth', 'email' => 'kenneth@infolink.test', 'developer_id' => $developer->id,
    ]);

    $run = app(GithubSync::class)->sync(full: true);

    expect($run->status)->toBe(SyncStatus::Ok)
        ->and($run->job)->toBe(SyncJob::GithubActivity)
        ->and($run->stats)->toMatchArray([
            'repos' => 1, 'branches_walked' => 2, 'commits_created' => 3, 'commits_updated' => 0, 'identities_created' => 2, 'repo_errors' => 0,
        ]);

    expect(GithubRepo::pluck('full_name')->sort()->values()->all())->toBe(['acme/app', 'acme/legacy'])
        ->and(GithubRepo::find(12)->last_synced_at)->toBeNull()
        ->and(GithubRepo::find(11)->last_synced_at)->not->toBeNull()
        ->and(GithubBranch::where('github_repo_id', 11)->pluck('head_sha', 'name')->all())->toEqual(['feature/pay' => 'f1', 'main' => 'c3'])
        ->and(GithubCommit::pluck('branch', 'sha')->all())->toEqual(['c3' => 'main', 'c2' => 'main', 'f1' => 'feature/pay']);

    $c2 = GithubCommit::firstWhere('sha', 'c2');
    expect(GithubCommit::firstWhere('sha', 'c3')->is_merge)->toBeTrue()
        ->and($c2->is_merge)->toBeFalse()
        ->and($c2->redmine_issue_ids)->toBe([2881])
        ->and($c2->is_ai_assisted)->toBeTrue()
        ->and($c2->authored_at->format('Y-m-d H:i'))->toBe('2026-09-10 10:00')
        ->and($c2->identity->key)->toBe('email:kenneth@infolink.test')
        ->and($c2->identity->developer_id)->toBe($developer->id)
        ->and(GithubCommit::firstWhere('sha', 'f1')->identity->developer_id)->toBeNull();

    expect($this->github->graphqlCalls())->not->toContain('refs:acme/legacy');
});

it('only walks moved branches of pushed repos on an incremental sync', function () {
    $this->github->repos['acme'][] = FakeGithub::repo(13, 'acme/quiet', '2026-09-01T00:00:00Z');
    $this->github->branches['acme/quiet'] = ['main' => 'q1'];
    $this->github->histories['q1'] = [FakeGithub::commit('q1', '2026-09-01T00:00:00Z', 'feat: quiet', ['login' => 'howl'])];
    $this->github->repos['acme'] = [$this->github->repos['acme'][0], $this->github->repos['acme'][2], $this->github->repos['acme'][1]];

    app(GithubSync::class)->sync(full: true);

    Carbon::setTestNow('2026-10-02 12:00:00');
    $callsBefore = count($this->github->graphqlCalls());
    $this->github->repos['acme'][0]['pushedAt'] = '2026-10-02T03:00:00Z';
    $this->github->branches['acme/app']['feature/pay'] = 'f2';
    $this->github->histories['f2'] = [
        FakeGithub::commit('f2', '2026-10-02T02:00:00Z', 'fix: more rounding', ['login' => 'howl']),
        ...$this->github->histories['f1'],
    ];

    $run = app(GithubSync::class)->sync();

    expect($run->status)->toBe(SyncStatus::Ok)
        ->and($run->stats)->toMatchArray(['mode' => 'incremental', 'repos' => 1, 'branches_walked' => 1, 'commits_created' => 1])
        ->and(GithubBranch::where('github_repo_id', 11)->where('name', 'feature/pay')->value('head_sha'))->toBe('f2')
        ->and(GithubCommit::firstWhere('sha', 'f2')->branch)->toBe('feature/pay');

    $calls = array_slice($this->github->graphqlCalls(), $callsBefore);
    expect($calls)->toContain('history:f2')
        ->not->toContain('history:c3')
        ->not->toContain('refs:acme/quiet');
});

it('fetches effective lines only for large commits, excluding lock and vendored files', function () {
    $this->github->histories['c3'][0] = FakeGithub::commit('c3', '2026-09-20T02:00:00Z', 'feat: deps', ['login' => 'kenneth'], 900, 100);
    $this->github->files['c3'] = [
        ['filename' => 'composer.lock', 'additions' => 800, 'deletions' => 50],
        ['filename' => 'vendor/acme/lib/Foo.php', 'additions' => 50, 'deletions' => 0],
        ['filename' => 'app/lib/foo.g.dart', 'additions' => 6, 'deletions' => 5],
        ['filename' => 'app/Services/Pay.php', 'additions' => 40, 'deletions' => 45],
        ['filename' => 'resources/js/build.js', 'additions' => 4, 'deletions' => 0],
    ];

    $run = app(GithubSync::class)->sync(full: true);

    $c3 = GithubCommit::firstWhere('sha', 'c3');
    expect($run->stats)->toMatchArray(['effective_fetched' => 1, 'effective_pending' => 0])
        ->and($this->github->restCalls())->toBe(1)
        ->and([$c3->effective_additions, $c3->effective_deletions])->toBe([44, 45])
        ->and(GithubCommit::firstWhere('sha', 'c2')->effective_additions)->toBeNull();
});

it('shrinks history pages GitHub times out on and fills counts of unreadable pages from REST', function () {
    Sleep::fake();
    $this->github->historyTimeoutAbove = 0;
    $this->github->files['f1'] = [
        ['filename' => 'pubspec.lock', 'additions' => 300, 'deletions' => 0],
        ['filename' => 'lib/pay.dart', 'additions' => 12, 'deletions' => 3],
    ];

    $run = app(GithubSync::class)->sync(full: true);

    $f1 = GithubCommit::firstWhere('sha', 'f1');
    expect($run->error)->toBeNull()->and($run->status)->toBe(SyncStatus::Ok)
        ->and(GithubCommit::count())->toBe(3)
        ->and([$f1->additions, $f1->deletions, $f1->changed_files])->toBe([312, 3, 2])
        ->and([$f1->effective_additions, $f1->effective_deletions])->toBe([12, 3]);
});

it('leaves a commit whose detail times out for the next run instead of failing', function () {
    Sleep::fake();
    $this->github->histories['c3'][0] = FakeGithub::commit('c3', '2026-09-20T02:00:00Z', 'feat: deps', ['login' => 'kenneth'], 900, 100);
    $this->github->timeoutShas = ['c3'];

    $run = app(GithubSync::class)->sync(full: true);

    expect($run->status)->toBe(SyncStatus::Ok)
        ->and($run->stats)->toMatchArray(['effective_fetched' => 0, 'effective_pending' => 1])
        ->and(GithubCommit::firstWhere('sha', 'c3')->effective_additions)->toBeNull();
});

it('classifies excluded paths', function (string $path, bool $excluded) {
    expect(GithubSync::isExcludedPath($path))->toBe($excluded);
})->with([
    ['package-lock.json', true],
    ['ios/Podfile.lock', true],
    ['public/build/assets/app-abc.js', true],
    ['ios/Pods/Alamofire/Source.swift', true],
    ['lib/models/user.freezed.dart', true],
    ['src/__generated__/graphql.ts', true],
    ['assets/logo.svg', true],
    ['packages/INFOLINK/LearningKit/tests/Golden/ebbinghaus.json', true],
    ['tests/fixtures/orders.json', true],
    ['database/seeders/data/products.csv', true],
    ['app/Http/Controllers/BuildController.php', false],
    ['lib/main.dart', false],
]);

it('upserts pull requests and reviews until they are older than the cutoff', function () {
    $this->github->pullRequests['acme/app'] = [
        [
            'databaseId' => 4430284614, 'number' => 12, 'title' => 'feat: 金流憑證', 'body' => "Closes #2881\nsee #5",
            'state' => 'MERGED', 'isDraft' => false, 'createdAt' => '2026-09-01T01:00:00Z', 'mergedAt' => '2026-09-03T01:00:00Z',
            'closedAt' => '2026-09-03T01:00:00Z', 'updatedAt' => '2026-09-03T01:00:00Z', 'additions' => 120, 'deletions' => 4,
            'headRefName' => 'feature/pay', 'baseRefName' => 'main', 'author' => ['login' => 'howl'], 'mergedBy' => ['login' => 'kenneth'],
            'reviews' => ['nodes' => [
                ['databaseId' => 901, 'state' => 'APPROVED', 'submittedAt' => '2026-09-02T01:00:00Z', 'author' => ['login' => 'kenneth']],
                ['databaseId' => 902, 'state' => 'PENDING', 'submittedAt' => null, 'author' => ['login' => 'kenneth']],
                ['databaseId' => 903, 'state' => 'COMMENTED', 'submittedAt' => '2026-09-02T02:00:00Z', 'author' => null],
            ]],
        ],
        [
            'databaseId' => 77, 'number' => 3, 'title' => 'old', 'body' => '', 'state' => 'CLOSED', 'isDraft' => false,
            'createdAt' => '2025-10-01T00:00:00Z', 'mergedAt' => null, 'closedAt' => '2025-12-01T00:00:00Z',
            'updatedAt' => '2025-12-01T00:00:00Z', 'additions' => 1, 'deletions' => 1, 'headRefName' => 'x', 'baseRefName' => 'main',
            'author' => ['login' => 'howl'], 'mergedBy' => null, 'reviews' => ['nodes' => []],
        ],
    ];

    $run = app(GithubSync::class)->sync(full: true);

    $pullRequest = GithubPullRequest::find(4430284614);
    expect($run->stats)->toMatchArray(['prs' => 1, 'reviews' => 2])
        ->and(GithubPullRequest::find(77))->toBeNull()
        ->and($pullRequest->redmine_issue_ids)->toBe([2881])
        ->and($pullRequest->identity->key)->toBe('login:howl')
        ->and($pullRequest->mergedBy->key)->toBe('login:kenneth')
        ->and(GithubReview::find(901)->identity->key)->toBe('login:kenneth')
        ->and(GithubReview::find(902))->toBeNull()
        ->and(GithubReview::find(903)->github_identity_id)->toBeNull();
});

it('keeps syncing other repos when GitHub refuses one', function () {
    $this->github->repos['acme'] = [
        FakeGithub::repo(14, 'acme/gone', '2026-09-30T09:00:00Z'),
        ...$this->github->repos['acme'],
    ];
    $this->github->missingRepos = ['acme/gone'];

    $run = app(GithubSync::class)->sync();

    expect($run->status)->toBe(SyncStatus::Ok)
        ->and($run->stats)->toMatchArray(['repos' => 1, 'repo_errors' => 1, 'commits_created' => 3])
        ->and(GithubRepo::find(14)->last_synced_at)->toBeNull();
});

it('retries a transient gateway error instead of failing the run', function () {
    Sleep::fake();
    $this->github->failStatus = 502;
    $this->github->failCount = 2;

    $run = app(GithubSync::class)->sync();

    expect($run->status)->toBe(SyncStatus::Ok)
        ->and(GithubCommit::count())->toBeGreaterThan(0);
});

it('records a failed run when GitHub is unavailable', function (int $status) {
    Sleep::fake();
    $this->github->failStatus = $status;

    $run = app(GithubSync::class)->sync();

    expect($run->status)->toBe(SyncStatus::Failed)
        ->and($run->error)->toContain("HTTP {$status}")
        ->and(GithubCommit::count())->toBe(0);
})->with([502, 429]);

it('only syncs the retention window and prunes activity that fell out of it', function () {
    $old = GithubCommit::factory()->create(['authored_at' => '2026-08-31 23:00:00']);
    $recent = GithubCommit::factory()->create(['authored_at' => '2026-09-01 08:00:00']);
    $stalePr = GithubPullRequest::factory()->merged()->create(['opened_at' => '2026-08-01', 'merged_at' => '2026-08-02', 'closed_at' => '2026-08-02']);
    $mergedInWindow = GithubPullRequest::factory()->merged()->create(['opened_at' => '2026-08-01', 'merged_at' => '2026-09-05', 'closed_at' => '2026-09-05']);
    GithubReview::factory()->for($stalePr, 'pullRequest')->create();

    expect(GithubSync::windowStart()->toDateTimeString())->toBe('2026-09-01 00:00:00')
        ->and(app(GithubSync::class)->prune())->toBe(['pruned_commits' => 1, 'pruned_prs' => 1])
        ->and(GithubCommit::find($old->id))->toBeNull()
        ->and(GithubCommit::find($recent->id))->not->toBeNull()
        ->and(GithubPullRequest::find($mergedInWindow->id))->not->toBeNull()
        ->and(GithubReview::count())->toBe(0);
});
