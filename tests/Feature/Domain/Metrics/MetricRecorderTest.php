<?php

use App\Domain\Metrics\MetricRecorder;
use App\Domain\Metrics\UnknownMetricException;
use App\Enums\MetricDirection;
use App\Enums\PeriodType;
use App\Enums\Source;
use App\Models\MetricDefinition;
use App\Models\MetricValue;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 15:30')); // a Thursday
    $this->recorder = app(MetricRecorder::class);
});

test('recording the same period twice updates the value instead of duplicating', function () {
    MetricDefinition::factory()->create(['key' => 'cash.balance', 'period_type' => PeriodType::Snapshot]);

    $this->recorder->record('cash.balance', 1_000_000);
    $value = $this->recorder->record('cash.balance', 1_072_776, source: Source::Claude, notes: '09/22 明細');

    expect(MetricValue::count())->toBe(1)
        ->and((float) $value->fresh()->value)->toBe(1_072_776.0)
        ->and($value->source)->toBe(Source::Claude)
        ->and($value->notes)->toBe('09/22 明細');
});

test('dimensions are recorded as separate rows', function () {
    MetricDefinition::factory()->create(['key' => 'delivery.open', 'period_type' => PeriodType::Day]);

    $this->recorder->record('delivery.open', 601);
    $this->recorder->record('delivery.open', 80, dimension: 'project:tcsb-5f-b2c');

    expect(MetricValue::count())->toBe(2)
        ->and((float) $this->recorder->latest('delivery.open', 'project:tcsb-5f-b2c')->value)->toBe(80.0);
});

test('the period start defaults and normalises to the metric granularity', function (PeriodType $periodType, ?string $given, string $expected) {
    MetricDefinition::factory()->create(['key' => 'test.metric', 'period_type' => $periodType]);

    $value = $this->recorder->record('test.metric', 1, $given ? CarbonImmutable::parse($given) : null);

    expect($value->period_start->toDateString())->toBe($expected);
})->with([
    'snapshot defaults to today' => [PeriodType::Snapshot, null, '2026-09-24'],
    'day defaults to today' => [PeriodType::Day, null, '2026-09-24'],
    'week defaults to Monday' => [PeriodType::Week, null, '2026-09-21'],
    'month defaults to the 1st' => [PeriodType::Month, null, '2026-09-01'],
    'a mid-week date lands on its Monday' => [PeriodType::Week, '2026-09-27', '2026-09-21'],
    'a mid-month date lands on the 1st' => [PeriodType::Month, '2026-08-15', '2026-08-01'],
]);

test('a normalised period start upserts onto the same row', function () {
    MetricDefinition::factory()->create(['key' => 'delivery.created', 'period_type' => PeriodType::Week]);

    $this->recorder->record('delivery.created', 40, CarbonImmutable::parse('2026-09-22'));
    $this->recorder->record('delivery.created', 46, CarbonImmutable::parse('2026-09-25'));

    expect(MetricValue::count())->toBe(1)
        ->and((float) MetricValue::sole()->value)->toBe(46.0);
});

test('recording an unknown metric key throws', function () {
    $this->recorder->record('nope.missing', 1);
})->throws(UnknownMetricException::class);

test('recordMany writes a batch and rolls back entirely on an unknown key', function () {
    MetricDefinition::factory()->create(['key' => 'cash.balance']);
    MetricDefinition::factory()->create(['key' => 'delivery.open', 'period_type' => PeriodType::Day]);

    $values = $this->recorder->recordMany([
        ['key' => 'cash.balance', 'value' => 1_072_776, 'period_start' => '2026-09-22'],
        ['key' => 'delivery.open', 'value' => 601, 'dimension' => ''],
    ], Source::Claude);

    expect($values)->toHaveCount(2)
        ->and($values->first()->period_start->toDateString())->toBe('2026-09-22')
        ->and($values->every(fn (MetricValue $value) => $value->source === Source::Claude))->toBeTrue();

    expect(fn () => $this->recorder->recordMany([
        ['key' => 'cash.balance', 'value' => 1, 'period_start' => '2026-09-23'],
        ['key' => 'nope.missing', 'value' => 2],
    ], Source::Claude))->toThrow(UnknownMetricException::class);

    expect(MetricValue::count())->toBe(2);
});

test('latestWithPrevious returns the two most recent periods and the change', function () {
    MetricDefinition::factory()->create(['key' => 'cash.balance']);

    $this->recorder->record('cash.balance', 900_000, CarbonImmutable::parse('2026-08-30'));
    $this->recorder->record('cash.balance', 1_072_776, CarbonImmutable::parse('2026-09-22'));
    $this->recorder->record('cash.balance', 800_000, CarbonImmutable::parse('2026-07-31'));

    $result = $this->recorder->latestWithPrevious('cash.balance');

    expect($result['current']->period_start->toDateString())->toBe('2026-09-22')
        ->and($result['previous']->period_start->toDateString())->toBe('2026-08-30')
        ->and($result['change'])->toBe(172_776.0)
        ->and($result['status'])->toBe('ok');
});

test('latestWithPrevious copes with no history', function () {
    MetricDefinition::factory()->create(['key' => 'cash.balance']);

    expect($this->recorder->latestWithPrevious('cash.balance'))
        ->toBe(['current' => null, 'previous' => null, 'change' => null, 'status' => 'ok']);
});

test('threshold status respects which direction is better', function (MetricDirection $better, ?float $warn, ?float $critical, float $value, string $expected) {
    MetricDefinition::factory()->create([
        'key' => 'test.metric',
        'better' => $better,
        'warn_threshold' => $warn,
        'critical_threshold' => $critical,
    ]);

    $this->recorder->record('test.metric', $value);

    expect($this->recorder->latestWithPrevious('test.metric')['status'])->toBe($expected);
})->with([
    'up: above warn is ok' => [MetricDirection::Up, 3, 2, 4.7, 'ok'],
    'up: exactly at warn is ok' => [MetricDirection::Up, 3, 2, 3, 'ok'],
    'up: below warn is warn' => [MetricDirection::Up, 3, 2, 2.5, 'warn'],
    'up: below critical is critical' => [MetricDirection::Up, 3, 2, 1.5, 'critical'],
    'up: critical-only threshold' => [MetricDirection::Up, null, 500_000, 400_000, 'critical'],
    'down: below warn is ok' => [MetricDirection::Down, 20, null, 15, 'ok'],
    'down: above warn is warn' => [MetricDirection::Down, 20, null, 58, 'warn'],
    'down: above critical is critical' => [MetricDirection::Down, 20, 50, 58, 'critical'],
    'none: direction inferred from thresholds' => [MetricDirection::None, 10, 20, 25, 'critical'],
    'none: a single threshold is ignored' => [MetricDirection::None, 10, null, 25, 'ok'],
    'no thresholds is ok' => [MetricDirection::Up, null, null, -5, 'ok'],
]);
