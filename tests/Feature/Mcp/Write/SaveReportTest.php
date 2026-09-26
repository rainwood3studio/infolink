<?php

use App\Enums\ReportType;
use App\Enums\Source;
use App\Mcp\Servers\InfolinkServer;
use App\Mcp\Tools\SaveReport;
use App\Models\Report;

test('save_report stores a periodic report and upserts it by type and period', function () {
    $arguments = [
        'type' => 'weekly_company',
        'period_start' => '2026-09-24',
        'title' => '第 39 週營運回顧',
        'body' => "## 摘要\n\n驗證中堆積 58 筆。",
        'metrics_snapshot' => ['delivery.verifying.others' => 58],
        'vault_ref' => '03.Business/INFOLINK/週報/2026-W39.md',
        'notify' => true,
    ];

    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(SaveReport::class, $arguments)
        ->assertOk()
        ->assertSee(['"result":"created"', '"period_start":"2026-09-21"', '"period_end":"2026-09-27"', '"external_key":"weekly_company:2026-09-21"', '"notify":true']);

    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(SaveReport::class, [...$arguments, 'period_start' => '2026-09-21', 'title' => '第 39 週營運回顧（修訂）'])
        ->assertOk()
        ->assertSee('"result":"updated"');

    $report = Report::query()->sole();

    expect($report->title)->toBe('第 39 週營運回顧（修訂）')
        ->and($report->type)->toBe(ReportType::WeeklyCompany)
        ->and($report->metrics_snapshot)->toBe(['delivery.verifying.others' => 58])
        ->and($report->notify)->toBeTrue()
        ->and($report->source)->toBe(Source::Claude)
        ->and($report->actor)->toBe('claude-cli');
});

test('monthly reports are keyed by the first of the month', function () {
    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(SaveReport::class, ['type' => 'monthly_finance', 'period_start' => '2026-09-15', 'title' => '9 月月結', 'body' => 'x'])
        ->assertOk()
        ->assertSee(['"period_start":"2026-09-01"', '"period_end":"2026-09-30"']);
});

test('adhoc reports upsert by external key when given and are otherwise always new', function () {
    $adhoc = ['type' => 'adhoc', 'period_start' => '2026-09-26', 'title' => '長照延到 11 月的影響', 'body' => 'x'];

    InfolinkServer::actingAs(mcpUser(['write']))->tool(SaveReport::class, [...$adhoc, 'external_key' => 'what-if:長照-11月'])->assertOk();
    InfolinkServer::actingAs(mcpUser(['write']))->tool(SaveReport::class, [...$adhoc, 'external_key' => 'what-if:長照-11月'])->assertOk()->assertSee('"result":"updated"');

    expect(Report::query()->count())->toBe(1);

    InfolinkServer::actingAs(mcpUser(['write']))->tool(SaveReport::class, $adhoc)->assertOk();
    InfolinkServer::actingAs(mcpUser(['write']))->tool(SaveReport::class, $adhoc)->assertOk();

    expect(Report::query()->count())->toBe(3);
});

test('save_report validates input with actionable messages', function () {
    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(SaveReport::class, ['type' => 'weekly', 'title' => 'x', 'metrics_snapshot' => 'cash 1m'])
        ->assertHasErrors(['type must be one of: daily_brief', 'Pass `period_start`', 'Pass the report `body`', 'metrics_snapshot must be a JSON object']);
});
