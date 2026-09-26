<?php

use App\Models\MetricValue;
use App\Models\RedmineIssue;
use App\Models\RedmineStatusSnapshot;
use Carbon\CarbonImmutable;
use Database\Seeders\MetricDefinitionSeeder;

beforeEach(function () {
    config(['services.redmine.acceptor_name' => '文豪']);
    $this->seed(MetricDefinitionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-09-07 23:50'));
});

test('by default it snapshots today and records daily and weekly metrics', function () {
    RedmineIssue::factory()->create(['created_on' => '2026-09-07 09:00']);

    $this->artisan('infolink:snapshot-redmine')->assertSuccessful();

    expect(RedmineStatusSnapshot::whereDate('snapshot_date', '2026-09-07')->count())->toBe(1)
        ->and(MetricValue::where('metric_key', 'delivery.open')->where('dimension', '')->value('value'))->toEqual(1)
        ->and(MetricValue::where('metric_key', 'delivery.created')->whereDate('period_start', '2026-09-07')->value('value'))->toEqual(1);
});

test('--week prints the week stats and records the weekly metrics', function () {
    RedmineIssue::factory()->create(['assignee_name' => '文豪', 'created_on' => '2026-09-02 09:00']);

    $this->artisan('infolink:snapshot-redmine', ['--week' => '2026-W36'])
        ->expectsOutputToContain('2026-08-31 ~ 2026-09-06')
        ->assertSuccessful();

    expect(MetricValue::where('metric_key', 'delivery.created')->whereDate('period_start', '2026-08-31')->value('value'))->toEqual(1)
        ->and(RedmineStatusSnapshot::count())->toBe(0);
});

test('--rebuild-history reconstructs past days only', function () {
    RedmineIssue::factory()->create(['created_on' => '2026-09-01 09:00']);

    $this->artisan('infolink:snapshot-redmine', ['--rebuild-history' => '2026-09-01'])->assertSuccessful();

    expect(RedmineStatusSnapshot::where('is_reconstructed', true)->count())->toBe(6)
        ->and(MetricValue::count())->toBe(0);
});

test('invalid dates and weeks are rejected', function (array $options) {
    $this->artisan('infolink:snapshot-redmine', $options)->assertExitCode(2);
})->with([
    [['--date' => '2026-13-40']],
    [['--rebuild-history' => 'yesterday']],
    [['--week' => '36']],
]);
