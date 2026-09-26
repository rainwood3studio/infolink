<?php

use App\Enums\ReportType;
use App\Enums\Source;
use App\Filament\Resources\Reports\Pages\ListReports;
use App\Filament\Resources\Reports\Pages\ViewReport;
use App\Filament\Resources\Reports\ReportResource;
use App\Filament\Resources\Reports\Schemas\ReportInfolist;
use App\Models\Report;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('lists reports newest period first', function () {
    $older = Report::factory()->create(['period_start' => '2026-09-01']);
    $newer = Report::factory()->create(['period_start' => '2026-09-22', 'period_end' => '2026-09-28', 'type' => ReportType::WeeklyCompany]);

    Livewire::test(ListReports::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$newer, $older], inOrder: true)
        ->assertSee('2026-09-22 ~ 2026-09-28')
        ->assertSee('公司週回顧');

    $this->get('/admin/reports')->assertOk();
});

it('filters by type and period', function () {
    $brief = Report::factory()->create(['period_start' => '2026-09-25']);
    $weekly = Report::factory()->create(['type' => ReportType::WeeklyRedmine, 'period_start' => '2026-09-21']);
    $old = Report::factory()->create(['period_start' => '2026-08-01']);

    Livewire::test(ListReports::class)
        ->filterTable('type', ReportType::WeeklyRedmine->value)
        ->assertCanSeeTableRecords([$weekly])
        ->assertCanNotSeeTableRecords([$brief, $old]);

    Livewire::test(ListReports::class)
        ->filterTable('period', ['from' => '2026-09-01', 'until' => '2026-09-30'])
        ->assertCanSeeTableRecords([$brief, $weekly])
        ->assertCanNotSeeTableRecords([$old]);
});

it('renders the body as markdown with the snapshot, source and vault link', function () {
    $report = Report::factory()->create([
        'title' => '每日簡報 09/26',
        'body' => "## 現金\n\n餘額 **1,072,776**",
        'metrics_snapshot' => ['cash_balance' => 1072776, 'open_issues' => 42],
        'source' => Source::Claude,
        'actor' => 'launchd-brief',
        'vault_ref' => '03.Business/Reports/每日簡報 2026-09-26.md',
    ]);

    Livewire::test(ViewReport::class, ['record' => $report->getRouteKey()])
        ->assertOk()
        ->assertSee('<h2>現金</h2>', escape: false)
        ->assertSee('<strong>1,072,776</strong>', escape: false)
        ->assertSee('cash_balance')
        ->assertSee('1072776')
        ->assertSee('launchd-brief')
        ->assertSee('Claude')
        ->assertSee('obsidian://open?vault=2ndBrain&amp;file='.rawurlencode('03.Business/Reports/每日簡報 2026-09-26.md'), escape: false);
});

it('flattens record-style metric snapshots', function () {
    $rows = ReportInfolist::snapshotRows([
        ['key' => 'cash_balance', 'value' => 100],
        ['key' => 'open_issues', 'dimension' => 'INFOLINK', 'value' => 3],
    ]);

    expect($rows)->toBe(['cash_balance' => '100', 'open_issues (INFOLINK)' => '3']);
});

it('is read only', function () {
    $report = Report::factory()->create();

    expect(ReportResource::canCreate())->toBeFalse()
        ->and(ReportResource::canEdit($report))->toBeFalse()
        ->and(ReportResource::canDelete($report))->toBeFalse()
        ->and(Route::has('filament.admin.resources.reports.create'))->toBeFalse()
        ->and(Route::has('filament.admin.resources.reports.edit'))->toBeFalse();

    $this->get('/admin/reports/'.$report->getKey().'/edit')->assertNotFound();
    $this->get('/admin/reports/create')->assertNotFound();
});
