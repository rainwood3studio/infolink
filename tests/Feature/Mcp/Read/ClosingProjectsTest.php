<?php

use App\Enums\ProjectStatus;
use App\Enums\SyncJob;
use App\Enums\SyncStatus;
use App\Mcp\Servers\InfolinkServer;
use App\Mcp\Tools\ClosingProjects;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\RedmineIssue;
use App\Models\RedmineStatusSnapshot;
use App\Models\SyncRun;

beforeEach(function () {
    config(['services.redmine.acceptor_name' => '文豪']);
    $this->travelTo('2026-10-03 10:00:00');
});

test('closing_projects returns each closing project with stages, money, projection and its most stalled issues', function () {
    $project = Project::factory()->create(['name' => '商城 APP', 'status' => ProjectStatus::Closing, 'redmine_project_id' => 21, 'target_close_date' => '2026-10-01']);
    Project::factory()->create(['name' => '進行中的專案']);
    Receivable::factory()->for($project)->create(['item' => '尾款', 'amount_untaxed' => 800_000, 'expected_on' => '2026-10-31']);

    $issue = ['project_id' => 21, 'project_identifier' => 'mall-app'];
    RedmineIssue::factory()->create([...$issue, 'id' => 3001, 'subject' => '結帳金額錯誤', 'status' => '實作中', 'assignee_name' => '裕樺', 'updated_on' => '2026-08-24 09:00']);
    RedmineIssue::factory()->create([...$issue, 'id' => 3002, 'subject' => '推播沒收到', 'status' => '新建立', 'assignee_name' => null, 'updated_on' => '2026-10-01 09:00']);
    RedmineIssue::factory()->create([...$issue, 'id' => 3003, 'subject' => '首頁輪播', 'status' => '驗證中', 'assignee_name' => '文豪', 'updated_on' => '2026-06-01 09:00']);
    RedmineStatusSnapshot::factory()->create(['snapshot_date' => '2026-09-26', 'project_identifier' => 'mall-app', 'count' => 10]);
    SyncRun::factory()->create(['job' => SyncJob::RedmineIssues, 'status' => SyncStatus::Ok, 'started_at' => '2026-10-03 09:00:00', 'finished_at' => '2026-10-03 09:01:00']);

    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(ClosingProjects::class)
        ->assertOk()
        ->assertSee([
            '"generated_at":"2026-10-03T10:00:00+08:00"',
            '"acceptor":"文豪"',
            '"redmine_issues":{"last_status":"ok","last_run_at":"2026-10-03T09:00:00+08:00","last_ok_at":"2026-10-03T09:01:00+08:00","last_error":null}',
            '"redmine_snapshot":{"last_status":null',
            '"summary":{"projects":1,"open":3,"outstanding_taxed":840000,"outstanding_overdue_taxed":0,"overdue":1,"projected_late":0,"not_converging":0,"at_risk":1}',
            '"name":"商城 APP"',
            '"target_close_date":"2026-10-01","days_left":-2,"is_overdue":true',
            '"open":3,"by_stage":{"unassigned":1,"in_progress":1,"off_flow":0,"awaiting_acceptance":1}',
            '"by_assignee":[{"name":"(未指派)","count":1,"is_acceptor":false},{"name":"文豪","count":1,"is_acceptor":true},{"name":"裕樺","count":1,"is_acceptor":false}]',
            '"stalled_30d":2,"outstanding_taxed":840000,"outstanding_overdue_taxed":0,"next_expected_on":"2026-10-31"',
            '"trend":[["2026-09-26",10],["2026-10-03",3]]',
            '"burn_from":{"date":"2026-09-26","open":10,"days_ago":7},"burn_per_day":1,"projection":"projected","projected_close_date":"2026-10-06","days_late":5',
            '"most_stalled":[[3001,"結帳金額錯誤","實作中","裕樺",40],[3002,"推播沒收到","新建立",null,2]]',
        ])
        ->assertDontSee('進行中的專案');
});

test('closing_projects returns an empty list when nothing is closing', function () {
    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(ClosingProjects::class)
        ->assertOk()
        ->assertSee(['"summary":{"projects":0,"open":0', '"projects":[]']);
});

test('closing_projects needs the read ability', function () {
    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(ClosingProjects::class)
        ->assertHasErrors(['not found']);
});
