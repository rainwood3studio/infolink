<?php

use App\Enums\SyncJob;
use App\Enums\SyncStatus;
use App\Mcp\Servers\InfolinkServer;
use App\Mcp\Tools\RedmineSummary;
use App\Models\RedmineIssue;
use App\Models\RedmineStatusChange;
use App\Models\RedmineStatusSnapshot;
use App\Models\SyncRun;

beforeEach(function () {
    $this->travelTo('2026-09-26 10:00:00');
});

test('redmine_summary returns stock, projects, both weeks, the trend and sync status', function () {
    RedmineIssue::factory()->count(2)->create(['status' => RedmineIssue::STATUS_VERIFYING, 'assignee_name' => '文豪', 'created_on' => '2026-09-23 09:00:00']);
    RedmineIssue::factory()->create(['status' => RedmineIssue::STATUS_VERIFYING, 'assignee_name' => '裕樺', 'created_on' => '2026-08-01 09:00:00']);
    RedmineIssue::factory()->create(['project_identifier' => 'wushi-app', 'project_name' => '我識 APP', 'created_on' => '2026-09-16 09:00:00']);
    RedmineIssue::factory()->create(['is_closed' => true, 'status' => '已結案', 'created_on' => '2026-08-01 09:00:00', 'closed_on' => '2026-09-17 12:00:00']);

    RedmineStatusChange::factory()->create(['changed_at' => '2026-09-24 10:00:00', 'previous_assignee_name' => '裕樺']);
    RedmineStatusSnapshot::factory()->create(['snapshot_date' => '2026-09-25', 'count' => 4]);
    RedmineStatusSnapshot::factory()->create(['snapshot_date' => '2026-07-01', 'count' => 99]);

    SyncRun::factory()->create(['job' => SyncJob::RedmineIssues, 'status' => SyncStatus::Ok, 'started_at' => '2026-09-26 09:00:00', 'finished_at' => '2026-09-26 09:01:00']);
    SyncRun::factory()->create(['job' => SyncJob::RedmineIssues, 'status' => SyncStatus::Failed, 'started_at' => '2026-09-26 09:30:00', 'error' => 'Connection refused']);

    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(RedmineSummary::class)
        ->assertOk()
        ->assertSee([
            '"acceptor":"文豪"',
            '"redmine_issues":{"last_status":"failed","last_run_at":"2026-09-26T09:30:00+08:00","last_ok_at":"2026-09-26T09:01:00+08:00","last_error":"Connection refused"}',
            '"redmine_time":{"last_status":null',
            '"current":{"open":4',
            '"verifying":{"acceptor":2,"others":1,"unassigned":0}',
            '"projects":[{"identifier":"tcsb-5f-b2c","name":"墊腳石 | 5F | B2C","open":3,"verifying_acceptor":2,"verifying_others":1',
            '{"identifier":"wushi-app"',
            '"this_week":{"week_start":"2026-09-21","week_end":"2026-09-27","created":2,"closed":0',
            '"advanced_to_verify":1,"advanced_to_verify_by_assignee":{"裕樺":1}',
            '"last_week":{"week_start":"2026-09-14","week_end":"2026-09-20","created":1,"closed":1',
            '"trend_30d":{"columns":["date","open","verifying_acceptor","verifying_others","stalled_90d","reconstructed"],"rows":[["2026-09-25",4,0,0,0,false]]}',
        ])
        ->assertDontSee('2026-07-01');
});

test('redmine_summary returns zeros when the mirror is empty', function () {
    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(RedmineSummary::class)
        ->assertOk()
        ->assertSee([
            '"redmine_issues":{"last_status":null,"last_run_at":null,"last_ok_at":null,"last_error":null}',
            '"current":{"open":0',
            '"projects":[]',
            '"rows":[]',
        ]);
});

test('redmine_summary needs the read ability', function () {
    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(RedmineSummary::class)
        ->assertHasErrors(['not found']);
});
