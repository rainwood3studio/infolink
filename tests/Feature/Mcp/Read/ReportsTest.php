<?php

use App\Enums\ReportType;
use App\Mcp\Servers\InfolinkServer;
use App\Mcp\Tools\GetReport;
use App\Mcp\Tools\ListReports;
use App\Models\Report;

beforeEach(function () {
    $this->weekly = Report::factory()->create([
        'type' => ReportType::WeeklyCompany,
        'period_start' => '2026-09-14',
        'period_end' => '2026-09-20',
        'title' => 'W38 公司週報',
        'body' => "## 摘要\n\n**現金** 107.3 萬，可撐 4.7 個月。".str_repeat('交付進度說明。', 60),
        'metrics_snapshot' => ['cash.balance' => 1072776],
    ]);
    Report::factory()->create(['type' => ReportType::DailyBrief, 'period_start' => '2026-09-25', 'title' => '09/25 每日簡報']);
    Report::factory()->create(['type' => ReportType::DailyBrief, 'period_start' => '2026-09-26', 'title' => '09/26 每日簡報']);
});

test('list_reports lists newest first with excerpts but no body', function () {
    $response = InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(ListReports::class)
        ->assertOk()
        ->assertSee([
            '"total":3',
            '"id":'.$this->weekly->id.',"type":"weekly_company","title":"W38 公司週報","period_start":"2026-09-14","period_end":"2026-09-20","excerpt":"摘要 現金 107.3 萬，可撐 4.7 個月。',
            '…"',
        ])
        ->assertDontSee(['"body"', '## 摘要', str_repeat('交付進度說明。', 40)]);

    expect(implode('', invade($response)->content()))->toMatch('/09\/26 每日簡報.*09\/25 每日簡報.*W38 公司週報/s');
});

test('list_reports filters by type, dates and limit', function () {
    $user = mcpUser(['read']);

    InfolinkServer::actingAs($user)->tool(ListReports::class, ['type' => 'daily_brief'])
        ->assertSee(['"total":2', '09/26 每日簡報'])
        ->assertDontSee('W38');

    InfolinkServer::actingAs($user)->tool(ListReports::class, ['from' => '2026-09-01', 'to' => '2026-09-25'])
        ->assertSee(['"total":2', 'W38', '09/25'])
        ->assertDontSee('09/26');

    InfolinkServer::actingAs($user)->tool(ListReports::class, ['limit' => 1])
        ->assertSee(['"total":3,"returned":1', '09/26'])
        ->assertDontSee('09/25');
});

test('list_reports is empty without reports', function () {
    Report::query()->delete();

    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(ListReports::class)
        ->assertOk()
        ->assertSee('"total":0,"returned":0,"reports":[]');
});

test('get_report returns the full body and metrics snapshot by id', function () {
    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(GetReport::class, ['id' => $this->weekly->id])
        ->assertOk()
        ->assertSee(['"title":"W38 公司週報"', '"body":"## 摘要\n\n**現金** 107.3 萬', str_repeat('交付進度說明。', 60), '"metrics_snapshot":{"cash.balance":1072776}']);
});

test('get_report finds a report by type and period start', function () {
    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(GetReport::class, ['type' => 'daily_brief', 'period_start' => '2026-09-25'])
        ->assertOk()
        ->assertSee('09/25 每日簡報')
        ->assertDontSee('09/26');
});

test('get_report says when the report does not exist', function () {
    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(GetReport::class, ['id' => 999_999])
        ->assertHasErrors(['Report not found.']);

    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(GetReport::class, ['type' => 'monthly_finance', 'period_start' => '2026-09-01'])
        ->assertHasErrors(['Report not found.']);
});

test('get_report needs an id or a type and period', function () {
    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(GetReport::class, [])
        ->assertHasErrors();

    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(GetReport::class, ['type' => 'daily_brief'])
        ->assertHasErrors(['period start']);
});

test('report tools need the read ability', function () {
    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(ListReports::class)
        ->assertHasErrors(['not found']);

    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(GetReport::class, ['id' => $this->weekly->id])
        ->assertHasErrors(['not found']);
});
