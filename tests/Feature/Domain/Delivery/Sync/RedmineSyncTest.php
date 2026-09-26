<?php

use App\Domain\Delivery\RedmineSync;
use App\Enums\SyncJob;
use App\Enums\SyncStatus;
use App\Models\RedmineIssue;
use App\Models\RedmineStatusChange;
use App\Models\RedmineTimeEntry;
use App\Models\SyncRun;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Tests\Feature\Domain\Delivery\Sync\FakeRedmine;

beforeEach(function () {
    config(['services.redmine.url' => 'http://redmine.test', 'services.redmine.key' => 'secret-key']);
    Sleep::fake();
    $this->travelTo(Carbon::parse('2026-09-26 10:00:00', 'Asia/Taipei'));
    $this->redmine = new FakeRedmine;
});

function sentIssueQueries(): array
{
    return Http::recorded()
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (Request $request) => str_contains($request->url(), '/issues.json'))
        ->map(fn (Request $request) => $request->data())
        ->values()
        ->all();
}

function recordOkRun(SyncJob $job, string $startedAt): SyncRun
{
    return SyncRun::factory()->create([
        'job' => $job,
        'status' => SyncStatus::Ok,
        'started_at' => Carbon::parse($startedAt, 'Asia/Taipei'),
        'finished_at' => Carbon::parse($startedAt, 'Asia/Taipei')->addMinute(),
    ]);
}

test('the first issue sync is full and maps every column', function () {
    $this->redmine->issues = [
        FakeRedmine::issue(2817),
        FakeRedmine::issue(2807, [
            'status' => ['id' => 5, 'name' => '完成', 'is_closed' => true],
            'assigned_to' => ['id' => 7, 'name' => '文豪'],
            'due_date' => '2026-09-30',
            'estimated_hours' => 2.5,
            'done_ratio' => 100,
            'closed_on' => '2026-09-23T11:57:17Z',
        ]),
    ];

    $run = app(RedmineSync::class)->syncIssues();

    expect($run->status)->toBe(SyncStatus::Ok)
        ->and($run->job)->toBe(SyncJob::RedmineIssues)
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->stats)->toMatchArray(['mode' => 'full', 'created' => 2, 'updated' => 0, 'status_changes' => 0, 'deleted' => 0]);

    expect(sentIssueQueries()[0])->toMatchArray(['status_id' => '*'])->not->toHaveKey('updated_on');

    $open = RedmineIssue::findOrFail(2817);
    expect($open->project_id)->toBe(26)
        ->and($open->project_identifier)->toBe('iam-monster-one')
        ->and($open->project_name)->toBe('iam-monster-one')
        ->and($open->tracker)->toBe('Bug')
        ->and($open->status)->toBe('新建立')
        ->and($open->is_closed)->toBeFalse()
        ->and($open->assignee_name)->toBe('永彬')
        ->and($open->author_id)->toBe(6)
        ->and($open->start_date->toDateString())->toBe('2026-09-24')
        ->and($open->created_on->format('Y-m-d H:i:s'))->toBe('2026-09-24 12:10:45')
        ->and($open->updated_on->format('Y-m-d H:i:s'))->toBe('2026-09-24 12:53:10')
        ->and($open->raw['custom_fields'][0]['name'])->toBe('客戶期待順序')
        ->and($open->synced_at->equalTo(now()))->toBeTrue();

    $closed = RedmineIssue::findOrFail(2807);
    expect($closed->is_closed)->toBeTrue()
        ->and($closed->closed_on->format('Y-m-d H:i:s'))->toBe('2026-09-23 19:57:17')
        ->and((float) $closed->estimated_hours)->toBe(2.5)
        ->and($closed->done_ratio)->toBe(100)
        ->and($closed->due_date->toDateString())->toBe('2026-09-30');

    Http::assertSentCount(2); // one issues page + one projects page (cached for the run)
});

test('an incremental sync asks for issues updated since the last ok run minus 10 minutes', function () {
    recordOkRun(SyncJob::RedmineIssues, '2026-09-26 09:00:00');
    SyncRun::factory()->create([
        'job' => SyncJob::RedmineIssues, 'status' => SyncStatus::Failed, 'started_at' => now()->subMinutes(5),
    ]);
    $this->redmine->issues = [FakeRedmine::issue(2817)];

    $run = app(RedmineSync::class)->syncIssues();

    expect($run->stats)->toMatchArray(['mode' => 'incremental', 'since' => '2026-09-26T00:50:00Z'])
        ->and(sentIssueQueries()[0])->toMatchArray(['status_id' => '*', 'updated_on' => '>=2026-09-26T00:50:00Z']);
});

test('full mode ignores the last run and fetches everything', function () {
    recordOkRun(SyncJob::RedmineIssues, '2026-09-26 09:00:00');

    $run = app(RedmineSync::class)->syncIssues(full: true);

    expect($run->stats['mode'])->toBe('full')
        ->and(sentIssueQueries()[0])->not->toHaveKey('updated_on');
});

test('issues are fetched across pages', function () {
    $this->redmine->issues = array_map(fn (int $id) => FakeRedmine::issue($id), range(1, 250));

    $run = app(RedmineSync::class)->syncIssues();

    expect($run->stats)->toMatchArray(['created' => 250, 'fetched' => 250])
        ->and(RedmineIssue::count())->toBe(250)
        ->and(collect(sentIssueQueries())->pluck('offset')->all())->toBe([0, 100, 200]);
});

test('re-syncing the same payload is idempotent', function () {
    $this->redmine->issues = [FakeRedmine::issue(1), FakeRedmine::issue(2)];

    app(RedmineSync::class)->syncIssues();
    $second = app(RedmineSync::class)->syncIssues(full: true);

    expect(RedmineIssue::count())->toBe(2)
        ->and(RedmineStatusChange::count())->toBe(0)
        ->and($second->stats)->toMatchArray(['created' => 0, 'updated' => 0, 'restored' => 0, 'deleted' => 0]);

    $this->redmine->issues[1]['subject'] = 'renamed';
    $third = app(RedmineSync::class)->syncIssues();

    expect($third->stats)->toMatchArray(['created' => 0, 'updated' => 1])
        ->and(RedmineIssue::find(2)->subject)->toBe('renamed');
});

test('a status change on a mirrored issue is recorded; new issues and assignee-only changes are not', function () {
    recordOkRun(SyncJob::RedmineIssues, '2026-09-26 09:00:00');
    RedmineIssue::factory()->create(['id' => 100, 'status_id' => 2, 'status' => '實作中', 'assignee_name' => '鈺文']);
    RedmineIssue::factory()->create(['id' => 101, 'status_id' => 2, 'status' => '實作中', 'assignee_name' => '鈺文']);

    $this->redmine->issues = [
        FakeRedmine::issue(100, [
            'project' => ['id' => 15, 'name' => '墊腳石 | 5F '],
            'status' => ['id' => 7, 'name' => '驗證中', 'is_closed' => false],
            'assigned_to' => ['id' => 7, 'name' => '文豪'],
            'updated_on' => '2026-09-26T01:30:00Z',
        ]),
        FakeRedmine::issue(101, [
            'status' => ['id' => 2, 'name' => '實作中', 'is_closed' => false],
            'assigned_to' => ['id' => 7, 'name' => '文豪'],
        ]),
        FakeRedmine::issue(102, ['status' => ['id' => 7, 'name' => '驗證中', 'is_closed' => false]]),
    ];

    $run = app(RedmineSync::class)->syncIssues();

    expect($run->stats)->toMatchArray(['created' => 1, 'updated' => 2, 'status_changes' => 1]);

    $change = RedmineStatusChange::sole();
    expect($change->issue_id)->toBe(100)
        ->and($change->project_identifier)->toBe('tcsb-5f-b2c')
        ->and($change->from_status)->toBe('實作中')
        ->and($change->to_status)->toBe('驗證中')
        ->and($change->assignee_name)->toBe('文豪')
        ->and($change->previous_assignee_name)->toBe('鈺文')
        ->and($change->changed_at->format('Y-m-d H:i:s'))->toBe('2026-09-26 09:30:00');

    expect(RedmineIssue::find(100)->status)->toBe('驗證中');
});

test('a full sync soft-deletes issues Redmine no longer returns and restores them when they come back', function () {
    $this->redmine->issues = [FakeRedmine::issue(1), FakeRedmine::issue(2), FakeRedmine::issue(3)];
    app(RedmineSync::class)->syncIssues();

    $this->redmine->issues = [FakeRedmine::issue(1), FakeRedmine::issue(3)];
    $incremental = app(RedmineSync::class)->syncIssues();

    expect($incremental->stats['deleted'])->toBe(0)
        ->and(RedmineIssue::count())->toBe(3);

    $full = app(RedmineSync::class)->syncIssues(full: true);

    expect($full->stats['deleted'])->toBe(1)
        ->and(RedmineIssue::pluck('id')->sort()->values()->all())->toBe([1, 3])
        ->and(RedmineIssue::onlyTrashed()->pluck('id')->all())->toBe([2]);

    $this->redmine->issues[] = FakeRedmine::issue(2, ['status' => ['id' => 5, 'name' => '完成', 'is_closed' => true]]);
    $restoring = app(RedmineSync::class)->syncIssues();

    expect($restoring->stats)->toMatchArray(['restored' => 1, 'status_changes' => 1])
        ->and(RedmineIssue::find(2))->not->toBeNull()
        ->and(RedmineIssue::find(2)->is_closed)->toBeTrue();
});

test('a full sync that fails part-way does not soft-delete anything', function () {
    RedmineIssue::factory()->create(['id' => 999]);
    $this->redmine->issues = array_map(fn (int $id) => FakeRedmine::issue($id), range(1, 150));
    $this->redmine->failFromOffset = 100;

    $run = app(RedmineSync::class)->syncIssues(full: true);

    expect($run->status)->toBe(SyncStatus::Failed)
        ->and($run->error)->toContain('HTTP 500')
        ->and($run->stats)->toMatchArray(['created' => 100, 'deleted' => 0])
        ->and(RedmineIssue::find(999))->not->toBeNull();
});

test('an unreachable Redmine records a failed run, logs a warning and does not throw', function () {
    Log::spy();
    recordOkRun(SyncJob::RedmineIssues, '2026-09-26 09:00:00');
    $this->redmine->unreachable = true;

    $run = app(RedmineSync::class)->syncIssues();

    expect($run->status)->toBe(SyncStatus::Failed)
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->error)->toContain('Redmine unreachable');

    Log::shouldHaveReceived('warning')->once();
    Log::shouldNotHaveReceived('error');

    expect(SyncRun::latestFor(SyncJob::RedmineIssues, SyncStatus::Ok)->started_at->format('H:i'))->toBe('09:00');
});

test('is_closed falls back to /issue_statuses.json when the payload lacks it', function () {
    $this->redmine->issues = [
        FakeRedmine::issue(1, ['status' => ['id' => 5, 'name' => '完成']]),
        FakeRedmine::issue(2, ['status' => ['id' => 7, 'name' => '驗證中']]),
    ];

    app(RedmineSync::class)->syncIssues();

    expect(RedmineIssue::find(1)->is_closed)->toBeTrue()
        ->and(RedmineIssue::find(2)->is_closed)->toBeFalse();
});

test('a project missing from /projects.json falls back to the mirror, then to a placeholder', function () {
    RedmineIssue::factory()->create(['id' => 50, 'project_id' => 99, 'project_identifier' => 'old-archived']);
    $this->redmine->issues = [
        FakeRedmine::issue(50, ['project' => ['id' => 99, 'name' => 'Archived']]),
        FakeRedmine::issue(51, ['project' => ['id' => 98, 'name' => 'Unknown']]),
    ];

    app(RedmineSync::class)->syncIssues();

    expect(RedmineIssue::find(50)->project_identifier)->toBe('old-archived')
        ->and(RedmineIssue::find(51)->project_identifier)->toBe('project-98');
});

test('the first time-entry sync is full and maps every column', function () {
    $this->redmine->timeEntries = [
        FakeRedmine::timeEntry(1907),
        FakeRedmine::timeEntry(1906, ['issue' => null, 'hours' => 0.5, 'comments' => '會議', 'user' => ['id' => 6, 'name' => '永彬']]),
    ];

    $run = app(RedmineSync::class)->syncTimeEntries();

    expect($run->job)->toBe(SyncJob::RedmineTime)
        ->and($run->stats)->toMatchArray(['mode' => 'full', 'created' => 2]);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/time_entries.json') && ! isset($request['spent_on']));

    $entry = RedmineTimeEntry::findOrFail(1907);
    expect($entry->issue_id)->toBe(2780)
        ->and($entry->project_identifier)->toBe('im')
        ->and($entry->user_name)->toBe('鈺文')
        ->and($entry->activity)->toBe('開發')
        ->and((float) $entry->hours)->toBe(1.0)
        ->and($entry->spent_on->toDateString())->toBe('2026-09-24')
        ->and($entry->comments)->toBeNull()
        ->and($entry->updated_on->format('Y-m-d H:i:s'))->toBe('2026-09-24 11:19:50');

    expect(RedmineTimeEntry::find(1906))
        ->issue_id->toBeNull()
        ->comments->toBe('會議');
});

test('incremental time-entry sync re-reads the last 60 days and drops entries deleted inside that window', function () {
    recordOkRun(SyncJob::RedmineTime, '2026-09-26 09:00:00');
    RedmineTimeEntry::factory()->create(['id' => 10, 'spent_on' => '2026-09-01']);
    RedmineTimeEntry::factory()->create(['id' => 11, 'spent_on' => '2026-09-02']);
    RedmineTimeEntry::factory()->create(['id' => 12, 'spent_on' => '2026-07-01']);
    $this->redmine->timeEntries = [FakeRedmine::timeEntry(10, ['spent_on' => '2026-09-01'])];

    $run = app(RedmineSync::class)->syncTimeEntries();

    expect($run->stats)->toMatchArray(['mode' => 'incremental', 'since' => '2026-07-28', 'updated' => 1, 'deleted' => 1])
        ->and(RedmineTimeEntry::pluck('id')->sort()->values()->all())->toBe([10, 12]);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/time_entries.json')
        && $request['spent_on'] === '>=2026-07-28');
});

test('a failed time-entry sync deletes nothing', function () {
    recordOkRun(SyncJob::RedmineTime, '2026-09-26 09:00:00');
    RedmineTimeEntry::factory()->create(['id' => 10, 'spent_on' => '2026-09-01']);
    $this->redmine->unreachable = true;

    $run = app(RedmineSync::class)->syncTimeEntries();

    expect($run->status)->toBe(SyncStatus::Failed)
        ->and(RedmineTimeEntry::count())->toBe(1);
});
