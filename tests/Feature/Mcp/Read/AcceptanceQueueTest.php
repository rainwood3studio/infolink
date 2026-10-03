<?php

use App\Enums\ProjectStatus;
use App\Enums\SyncJob;
use App\Mcp\Servers\InfolinkServer;
use App\Mcp\Tools\AcceptanceQueueSummary;
use App\Models\Project;
use App\Models\RedmineIssue;
use App\Models\RedmineStatusChange;
use App\Models\SyncRun;

beforeEach(function () {
    config(['services.redmine.url' => 'http://redmine.test', 'services.redmine.acceptor_name' => '文豪']);
    $this->travelTo('2026-10-03 10:00:00');
});

test('acceptance_queue returns the summary, weekly flow, the queue in suggested order and off-flow issues', function () {
    SyncRun::factory()->create(['job' => SyncJob::RedmineIssues, 'started_at' => '2026-09-26 09:00:00', 'finished_at' => '2026-09-26 09:01:00']);
    Project::factory()->create(['name' => '商城 APP', 'status' => ProjectStatus::Closing, 'redmine_project_id' => 21, 'target_close_date' => '2026-10-01']);

    $verifying = ['status' => RedmineIssue::STATUS_VERIFYING, 'assignee_name' => '文豪', 'priority' => '正常'];
    RedmineIssue::factory()->create([...$verifying, 'id' => 12, 'subject' => '報表匯出', 'updated_on' => '2026-09-01 09:00:00']);
    RedmineIssue::factory()->create([...$verifying, 'id' => 11, 'subject' => '購物車金額錯誤', 'project_id' => 21, 'project_identifier' => 'mall-app', 'project_name' => '商城 APP']);
    RedmineIssue::factory()->create([...$verifying, 'id' => 21, 'subject' => '掛在別人名下', 'assignee_name' => '妤欣', 'updated_on' => '2026-08-04 09:00:00']);
    RedmineIssue::factory()->count(2)->create(['status' => '完成', 'is_closed' => true, 'assignee_name' => '文豪', 'closed_on' => '2026-09-23 10:00:00']);
    RedmineStatusChange::factory()->create(['issue_id' => 11, 'previous_assignee_name' => '裕樺', 'changed_at' => '2026-09-29 15:00:00']);

    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(AcceptanceQueueSummary::class)
        ->assertOk()
        ->assertSee([
            '"generated_at":"2026-10-03T10:00:00+08:00"',
            '"sync":{"redmine_issues":{"last_status":"ok","last_run_at":"2026-09-26T09:00:00+08:00","last_ok_at":"2026-09-26T09:01:00+08:00","last_error":null}}',
            '"status_tracked_since":"2026-09-26"',
            '"summary":{"acceptor":"文豪","queue":2,"oldest_waiting_days":32,"median_waiting_days":18,"waiting_observed":1,"closing":{"issues":1,"projects":1,"outstanding_taxed":0}',
            '"age_buckets":{"le_7":1,"d8_30":0,"d31_90":1,"gt_90":0}',
            '"by_project":[{"identifier":"mall-app","name":"商城 APP","count":1,"is_closing":true,"closing_project":"商城 APP","target_close_date":"2026-10-01","days_left":-2',
            '"off_flow":{"total":1,"unassigned":0,"by_assignee":[{"assignee":"妤欣","count":1,"oldest_days_since_update":60}]}',
            '{"week_start":"2026-09-14","week_end":"2026-09-20","is_current":false,"accepted":0,"handed_over":null,"handed_over_partial":false,"acceptor_commits":null}',
            '{"week_start":"2026-09-21","week_end":"2026-09-27","is_current":false,"accepted":2,"handed_over":0,"handed_over_partial":true,"acceptor_commits":null}',
            '"queue":2,"avg_accepted_per_week":0.5,"weeks_to_clear":4,"projected_clear_date":"2026-10-31","recent":{"since":"2026-09-26","handed_over":1,"accepted":0},"is_growing":true',
            '"queue":{"total":2,"columns":["id","subject","project_name","is_closing","target_close_date","priority","waiting_days","waiting_observed","handed_over_by","url"],"rows":[[11,"購物車金額錯誤","商城 APP",true,"2026-10-01","正常",4,true,"裕樺","http://redmine.test/issues/11"],[12,"報表匯出"',
            '"off_flow":[{"assignee":"妤欣","count":1,"issues":[[21,"掛在別人名下","墊腳石 | 5F | B2C",60]]}]',
        ]);
});

test('acceptance_queue lists at most 30 queue rows and reports an empty mirror as zeros', function () {
    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(AcceptanceQueueSummary::class)
        ->assertOk()
        ->assertSee([
            '"status_tracked_since":null',
            '"summary":{"acceptor":"文豪","queue":0,"oldest_waiting_days":null',
            '"weeks_to_clear":null,"projected_clear_date":null,"recent":null,"is_growing":null',
            '"queue":{"total":0',
            '"off_flow":[]',
        ]);

    foreach (range(1, AcceptanceQueueSummary::QUEUE_ROWS + 1) as $id) {
        RedmineIssue::factory()->create(['id' => $id, 'status' => RedmineIssue::STATUS_VERIFYING, 'assignee_name' => '文豪']);
    }

    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(AcceptanceQueueSummary::class)
        ->assertOk()
        ->assertSee(['"queue":{"total":31', 'http://redmine.test/issues/30"'])
        ->assertDontSee('http://redmine.test/issues/31"');
});

test('acceptance_queue needs the read ability', function () {
    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(AcceptanceQueueSummary::class)
        ->assertHasErrors(['not found']);
});
