<?php

use App\Enums\ActionItemPriority;
use App\Enums\InsightSeverity;
use App\Enums\InsightStatus;
use App\Enums\MetricDirection;
use App\Enums\MetricUnit;
use App\Enums\ProjectStatus;
use App\Enums\ReceivableStatus;
use App\Enums\ReportType;
use App\Enums\SyncJob;
use App\Mcp\Servers\InfolinkServer;
use App\Mcp\Tools\GetBriefing;
use App\Models\ActionItem;
use App\Models\BankTransaction;
use App\Models\CostBaseline;
use App\Models\Customer;
use App\Models\Insight;
use App\Models\MetricDefinition;
use App\Models\MetricValue;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\RedmineIssue;
use App\Models\Report;
use App\Models\SyncRun;

beforeEach(function () {
    $this->travelTo('2026-09-26 10:00:00');
});

test('get_briefing assembles the whole picture', function () {
    MetricDefinition::factory()->create([
        'key' => 'cash.runway_months', 'name' => '現金可撐月數', 'unit' => MetricUnit::Months, 'better' => MetricDirection::Up,
        'warn_threshold' => 3, 'critical_threshold' => 2, 'is_pinned' => true, 'description' => '餘額 ÷ 常態月成本（不計未收款）。',
    ]);
    MetricValue::factory()->create(['metric_key' => 'cash.runway_months', 'period_start' => '2026-09-20', 'value' => 5]);
    MetricValue::factory()->create(['metric_key' => 'cash.runway_months', 'period_start' => '2026-09-25', 'value' => 4.7]);

    MetricDefinition::factory()->create([
        'key' => 'delivery.verifying.others', 'name' => '驗證中（非文豪）', 'unit' => MetricUnit::Count, 'better' => MetricDirection::Down,
        'warn_threshold' => 20, 'is_pinned' => false,
    ]);
    MetricValue::factory()->create(['metric_key' => 'delivery.verifying.others', 'period_start' => '2026-09-25', 'value' => 58]);

    MetricDefinition::factory()->create(['key' => 'sales.pipeline_weighted', 'is_pinned' => true]);

    BankTransaction::factory()->create(['txn_date' => '2026-09-22', 'balance' => 1_072_776]);
    CostBaseline::factory()->create(['effective_from' => '2026-09-01', 'monthly_cost' => 229_666]);

    RedmineIssue::factory()->create(['status' => RedmineIssue::STATUS_VERIFYING, 'assignee_name' => '文豪']);
    foreach (range(1, 6) as $index) {
        RedmineIssue::factory()->create(['project_id' => 100 + $index, 'project_identifier' => "project-{$index}", 'project_name' => "專案 {$index}"]);
    }

    $critical = Insight::factory()->create(['severity' => InsightSeverity::Critical, 'fingerprint' => 'receivable-overdue:長照-期中款', 'title' => '長照期中款逾期']);
    Insight::factory()->create(['severity' => InsightSeverity::Warning, 'title' => '脫離驗收流程']);
    Insight::factory()->create(['severity' => InsightSeverity::Info, 'title' => '提醒']);
    Insight::factory()->create(['severity' => InsightSeverity::Critical, 'status' => InsightStatus::Resolved, 'title' => '舊的']);
    $actionItem = ActionItem::factory()->create(['title' => '寄出我識尾款發票', 'priority' => ActionItemPriority::P1, 'due_on' => '2026-09-24', 'owner' => 'Kenneth']);
    ActionItem::factory()->create(['title' => '下個月的事', 'due_on' => '2026-10-20']);

    $customer = Customer::factory()->create(['short_name' => '長照']);
    Receivable::factory()->for($customer)->create(['item' => '期中款', 'amount_untaxed' => 450_000, 'expected_on' => '2026-09-20', 'status' => ReceivableStatus::Invoiced]);
    Receivable::factory()->for($customer)->create(['item' => '維運費', 'amount_untaxed' => 20_000, 'expected_on' => '2026-10-05', 'is_recurring' => true]);
    Receivable::factory()->for($customer)->create(['item' => '尾款', 'amount_untaxed' => 300_000, 'expected_on' => '2026-12-31']);

    Project::factory()->for($customer)->create(['name' => '長照平台', 'status' => ProjectStatus::Closing, 'target_close_date' => '2026-12-31', 'redmine_project_id' => 15]);
    Project::factory()->for($customer)->create(['name' => '進行中專案', 'status' => ProjectStatus::Active]);

    SyncRun::factory()->create(['job' => SyncJob::RedmineIssues, 'started_at' => '2026-09-26 09:00:00', 'finished_at' => '2026-09-26 09:01:00']);
    $brief = Report::factory()->create(['type' => ReportType::DailyBrief, 'period_start' => '2026-09-26']);

    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(GetBriefing::class)
        ->assertOk()
        ->assertSee([
            '"generated_at":"2026-09-26T10:00:00+08:00"',
            // data freshness
            '"redmine_issues":{"last_status":"ok","last_run_at":"2026-09-26T09:00:00+08:00","last_ok_at":"2026-09-26T09:01:00+08:00","last_error":null}',
            '"rules":{"last_status":null',
            '"bank_latest_txn_date":"2026-09-22"',
            '"latest_daily_brief":{"id":'.$brief->id.',"date":"2026-09-26"',
            // pinned metrics
            '"pinned_metrics":[{"key":"cash.runway_months","name":"現金可撐月數","unit":"months","better":"up","period_type":"snapshot","period":"2026-09-25","value":4.7,"previous":5,"change":-0.3,"status":"ok","description":"餘額 ÷ 常態月成本（不計未收款）。"}',
            '{"key":"sales.pipeline_weighted"',
            '"value":null,"previous":null,"change":null,"status":"ok"',
            // over threshold
            '"metrics_over_threshold":[{"key":"delivery.verifying.others","name":"驗證中（非文豪）","unit":"count","better":"down","period":"2026-09-25","value":58,"status":"warn","warn_threshold":20,"critical_threshold":null}]',
            // finance
            '"finance":{"as_of":"2026-09-22","balance":1072776,"monthly_cost":229666,"runway_months":4.67,"ar_outstanding_taxed":787500,"ar_low_confidence_taxed":0,"ar_overdue_taxed":472500,"forecast_year_end":',
            '"forecast_min_90d":',
            // delivery
            '"delivery":{"open":7',
            '"verifying":{"acceptor":1,"others":0,"unassigned":0}',
            // attention
            '"attention":[{"type":"insight","id":'.$critical->id.',"fingerprint":"receivable-overdue:長照-期中款","severity":"critical"',
            '{"type":"action_item","id":'.$actionItem->id.',"priority":"p1","title":"寄出我識尾款發票","due_on":"2026-09-24","days_overdue":2,"owner":"Kenneth"}',
            '"open_insights_by_severity":{"critical":1,"warning":1,"info":1}',
            // receivables
            '"overdue_receivables":[{"id":',
            '"item":"期中款","untaxed":450000,"taxed":472500,"expected_on":"2026-09-20","days_overdue":6',
            '"upcoming_receivables":[{"id":',
            '"item":"維運費"',
            // closing projects
            '"closing_projects":[{"id":',
            '"name":"長照平台","customer":"長照","target_close_date":"2026-12-31","days_left":96,"open_issues":1}]',
        ])
        ->assertDontSee(['舊的', '下個月的事', '"item":"尾款"', '進行中專案', '"projects":', 'project-6']);
});

test('get_briefing works with an empty database', function () {
    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(GetBriefing::class)
        ->assertOk()
        ->assertSee([
            '"bank_latest_txn_date":null',
            '"latest_daily_brief":null',
            '"pinned_metrics":[]',
            '"metrics_over_threshold":[]',
            '"finance":null',
            '"delivery":{"open":0',
            '"top_projects":[]',
            '"attention":[]',
            '"open_insights_by_severity":{"critical":0,"warning":0,"info":0}',
            '"overdue_receivables":[]',
            '"upcoming_receivables":[]',
            '"closing_projects":[]',
        ]);
});

test('get_briefing needs the read ability', function () {
    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(GetBriefing::class)
        ->assertHasErrors(['not found']);
});
