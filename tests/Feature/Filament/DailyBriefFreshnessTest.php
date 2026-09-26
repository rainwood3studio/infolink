<?php

use App\Enums\ReportType;
use App\Filament\Resources\Reports\ReportResource;
use App\Filament\Widgets\DataFreshnessWidget;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * @return array{label:string, value:string, color:string, icon:?string, hint:?string, url?:?string}
 */
function dailyBriefSource(): array
{
    return collect(Livewire::test(DataFreshnessWidget::class)->instance()->getSources())->firstWhere('label', '今日簡報');
}

it('links today\'s daily brief with the time it was created', function () {
    Carbon::setTestNow('2026-09-25 11:30:00');
    Report::factory()->create(['period_start' => '2026-09-24']);
    $report = Report::factory()->create(['period_start' => '2026-09-25', 'created_at' => '2026-09-25 08:05:00']);

    expect(dailyBriefSource())->toMatchArray([
        'value' => '08:05 產生',
        'color' => 'success',
        'url' => ReportResource::getUrl('view', ['record' => $report]),
    ]);

    Livewire::test(DataFreshnessWidget::class)
        ->assertSee('08:05 產生')
        ->assertSee(ReportResource::getUrl('view', ['record' => $report]));
});

it('ignores other report types for today', function () {
    Carbon::setTestNow('2026-09-25 11:30:00');
    Report::factory()->create(['type' => ReportType::WeeklyCompany, 'period_start' => '2026-09-25']);

    expect(dailyBriefSource()['value'])->toBe('今日尚未產生');
});

it('warns when the brief is missing on a weekday after 10:00', function (string $now, string $color) {
    Carbon::setTestNow($now);

    expect(dailyBriefSource())->toMatchArray([
        'value' => '今日尚未產生',
        'color' => $color,
        'url' => null,
    ]);
})->with([
    'friday 10:00' => ['2026-09-25 10:00:00', 'warning'],
    'friday 09:59' => ['2026-09-25 09:59:00', 'gray'],
    'saturday 15:00' => ['2026-09-26 15:00:00', 'gray'],
]);
