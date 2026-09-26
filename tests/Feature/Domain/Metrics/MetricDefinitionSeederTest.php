<?php

use App\Enums\MetricDirection;
use App\Models\MetricDefinition;
use Database\Seeders\MetricDefinitionSeeder;

test('the seeder is idempotent', function () {
    $this->seed(MetricDefinitionSeeder::class);
    $count = MetricDefinition::count();

    MetricDefinition::find('cash.balance')->update(['name' => 'changed']);
    $this->seed(MetricDefinitionSeeder::class);

    expect(MetricDefinition::count())->toBe($count)
        ->and($count)->toBe(count(MetricDefinitionSeeder::definitions()))
        ->and(MetricDefinition::find('cash.balance')->name)->toBe('現金餘額');
});

test('the pinned home-page metrics are seeded', function () {
    $this->seed(MetricDefinitionSeeder::class);

    expect(MetricDefinition::where('is_pinned', true)->orderBy('sort')->pluck('key')->all())->toBe([
        'cash.balance',
        'cash.runway_months',
        'cash.forecast_min_90d',
        'ar.outstanding_taxed',
        'ar.overdue_taxed',
        'sales.pipeline_weighted',
        'delivery.open',
        'delivery.net_flow',
        'delivery.verifying.wenhao',
        'delivery.verifying.others',
        'company.closing_projects',
    ]);
});

test('alert thresholds and caveats are seeded', function () {
    $this->seed(MetricDefinitionSeeder::class);

    $runway = MetricDefinition::find('cash.runway_months');
    $offflow = MetricDefinition::find('delivery.verifying.others');

    expect((float) MetricDefinition::find('cash.forecast_min_90d')->critical_threshold)->toBe(500_000.0)
        ->and((float) $runway->warn_threshold)->toBe(3.0)
        ->and((float) $runway->critical_threshold)->toBe(2.0)
        ->and((float) $offflow->warn_threshold)->toBe(20.0)
        ->and($offflow->better)->toBe(MetricDirection::Down)
        ->and(MetricDefinition::find('delivery.closed')->description)->toContain('不是產能指標')
        ->and(MetricDefinition::whereNull('description')->orWhere('description', '')->count())->toBe(0);
});
