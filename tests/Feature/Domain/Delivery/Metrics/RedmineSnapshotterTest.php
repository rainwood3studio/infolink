<?php

use App\Domain\Delivery\RedmineSnapshotter;
use App\Enums\SyncJob;
use App\Enums\SyncStatus;
use App\Models\RedmineIssue;
use App\Models\RedmineStatusSnapshot;
use App\Models\SyncRun;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['services.redmine.acceptor_name' => '文豪']);
    $this->travelTo(CarbonImmutable::parse('2026-09-07 23:50'));
    $this->snapshotter = app(RedmineSnapshotter::class);
});

test('snapshot groups open issues by project, status and assignee with stalled and overdue counts', function () {
    $issue = fn (array $attributes) => RedmineIssue::factory()->create([
        'project_identifier' => 'tcsb-5f-b2c',
        'status' => '驗證中',
        'assignee_name' => '妤欣',
        'updated_on' => now()->subDays(5),
        ...$attributes,
    ]);

    $issue(['updated_on' => now()->subDays(100), 'due_date' => '2025-12-31']); // stalled 30 + 90, overdue
    $issue(['updated_on' => now()->subDays(45), 'due_date' => '2026-09-07']);   // stalled 30, due today is not overdue
    $issue([]);
    $issue(['assignee_name' => null, 'status' => '新建立']);
    $issue(['is_closed' => true, 'status' => '已結案', 'closed_on' => now()->subDay()]);
    $issue(['updated_on' => now()->subDays(200)])->delete();

    expect($this->snapshotter->snapshot())->toBe(2);

    $verifying = RedmineStatusSnapshot::where('status', '驗證中')->sole();
    $unassigned = RedmineStatusSnapshot::where('status', '新建立')->sole();

    expect($verifying->snapshot_date->toDateString())->toBe('2026-09-07')
        ->and($verifying->assignee_name)->toBe('妤欣')
        ->and($verifying->count)->toBe(3)
        ->and($verifying->stalled_30d)->toBe(2)
        ->and($verifying->stalled_90d)->toBe(1)
        ->and($verifying->overdue)->toBe(1)
        ->and($verifying->is_reconstructed)->toBeFalse()
        ->and($unassigned->assignee_name)->toBe('')
        ->and($unassigned->count)->toBe(1);

    $run = SyncRun::latestFor(SyncJob::RedmineSnapshot);
    expect($run->status)->toBe(SyncStatus::Ok)
        ->and($run->stats)->toMatchArray(['date' => '2026-09-07', 'rows' => 2, 'open' => 4]);
});

test('re-running a snapshot replaces the rows for that date', function () {
    $issue = RedmineIssue::factory()->create(['status' => '新建立']);
    $this->snapshotter->snapshot();

    $issue->update(['status' => '實作中']);
    RedmineIssue::factory()->create(['status' => '實作中']);
    $this->snapshotter->snapshot();

    expect(RedmineStatusSnapshot::count())->toBe(1)
        ->and(RedmineStatusSnapshot::sole()->status)->toBe('實作中')
        ->and(RedmineStatusSnapshot::sole()->count)->toBe(2)
        ->and(SyncRun::where('job', SyncJob::RedmineSnapshot)->count())->toBe(2);
});

test('a real snapshot replaces reconstructed rows of the same date', function () {
    RedmineStatusSnapshot::factory()->create([
        'snapshot_date' => '2026-09-07',
        'status' => RedmineSnapshotter::RECONSTRUCTED_STATUS,
        'is_reconstructed' => true,
    ]);
    RedmineIssue::factory()->create();

    $this->snapshotter->snapshot();

    expect(RedmineStatusSnapshot::where('is_reconstructed', true)->count())->toBe(0)
        ->and(RedmineStatusSnapshot::count())->toBe(1);
});

test('rebuilding history approximates daily open stock per project from created_on and closed_on', function () {
    RedmineIssue::factory()->create(['project_identifier' => 'a', 'created_on' => '2026-09-01 10:00']);
    RedmineIssue::factory()->create(['project_identifier' => 'a', 'created_on' => '2026-08-20 10:00', 'is_closed' => true, 'closed_on' => '2026-09-02 18:00']);
    RedmineIssue::factory()->create(['project_identifier' => 'b', 'created_on' => '2026-09-02 23:00']);
    // Closed without closed_on: assumed closed at updated_on.
    RedmineIssue::factory()->create(['project_identifier' => 'b', 'created_on' => '2026-08-01', 'is_closed' => true, 'closed_on' => null, 'updated_on' => '2026-09-02 09:00']);
    // Soft-deleted issues never count.
    RedmineIssue::factory()->create(['project_identifier' => 'b', 'created_on' => '2026-08-01'])->delete();

    $days = $this->snapshotter->rebuildHistory(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-03'));

    $countFor = fn (string $date, string $project): ?int => RedmineStatusSnapshot::whereDate('snapshot_date', $date)
        ->where('project_identifier', $project)
        ->value('count');

    expect($days)->toBe(3)
        ->and($countFor('2026-09-01', 'a'))->toBe(2)
        ->and($countFor('2026-09-01', 'b'))->toBe(1)
        ->and($countFor('2026-09-02', 'a'))->toBe(1)  // closed 09-02 18:00 → not open at end of day
        ->and($countFor('2026-09-02', 'b'))->toBe(1)  // created 23:00 counts, the updated_on-closed one does not
        ->and($countFor('2026-09-03', 'a'))->toBe(1)
        ->and(RedmineStatusSnapshot::where('is_reconstructed', false)->count())->toBe(0)
        ->and(RedmineStatusSnapshot::pluck('status')->unique()->all())->toBe([RedmineSnapshotter::RECONSTRUCTED_STATUS])
        ->and(RedmineStatusSnapshot::pluck('assignee_name')->unique()->all())->toBe(['']);
});

test('rebuilding history never overwrites a real snapshot and is idempotent', function () {
    RedmineIssue::factory()->create(['created_on' => '2026-08-01', 'status' => '驗證中']);
    $this->travelTo(CarbonImmutable::parse('2026-09-02 23:50'));
    $this->snapshotter->snapshot();
    $this->travelTo(CarbonImmutable::parse('2026-09-07 10:00'));

    expect($this->snapshotter->rebuildHistory(CarbonImmutable::parse('2026-09-01')))->toBe(5); // 09-01, 09-03..09-06
    expect($this->snapshotter->rebuildHistory(CarbonImmutable::parse('2026-09-01')))->toBe(5);

    expect(RedmineStatusSnapshot::count())->toBe(6)
        ->and(RedmineStatusSnapshot::whereDate('snapshot_date', '2026-09-02')->sole()->status)->toBe('驗證中')
        ->and(RedmineStatusSnapshot::whereDate('snapshot_date', '2026-09-07')->exists())->toBeFalse();
});
