<?php

use App\Enums\PeriodType;
use App\Enums\Source;
use App\Mcp\Servers\InfolinkServer;
use App\Mcp\Tools\RecordMetrics;
use App\Models\MetricDefinition;
use App\Models\MetricValue;

beforeEach(function () {
    MetricDefinition::factory()->create(['key' => 'cash.balance']);
    MetricDefinition::factory()->create(['key' => 'delivery.verifying.others', 'period_type' => PeriodType::Week]);
});

test('record_metrics writes values with source claude and normalised periods', function () {
    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(RecordMetrics::class, ['entries' => [
            ['key' => 'cash.balance', 'value' => 1072776, 'period_start' => '2026-09-22', 'vault_ref' => '帳戶流水 2026-09.md'],
            ['key' => 'delivery.verifying.others', 'value' => 58, 'period_start' => '2026-09-24', 'dimension' => 'project:tcsb-5f-b2c'],
        ]])
        ->assertOk()
        ->assertSee(['"recorded":2', '"period_start":"2026-09-21"', '"warnings":[]']);

    $weekly = MetricValue::query()->where('metric_key', 'delivery.verifying.others')->sole();

    expect($weekly->period_start->toDateString())->toBe('2026-09-21')
        ->and($weekly->dimension)->toBe('project:tcsb-5f-b2c')
        ->and($weekly->source)->toBe(Source::Claude)
        ->and($weekly->actor)->toBe('claude-cli');
});

test('record_metrics is idempotent', function () {
    $entries = ['entries' => [['key' => 'cash.balance', 'value' => 1000, 'period_start' => '2026-09-22']]];

    InfolinkServer::actingAs(mcpUser(['write']))->tool(RecordMetrics::class, $entries)->assertOk();
    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(RecordMetrics::class, ['entries' => [['key' => 'cash.balance', 'value' => 2000, 'period_start' => '2026-09-22']]])
        ->assertOk();

    expect(MetricValue::query()->count())->toBe(1)
        ->and((float) MetricValue::query()->sole()->value)->toBe(2000.0);
});

test('record_metrics rejects the whole batch for an unknown key and suggests close matches', function () {
    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(RecordMetrics::class, ['entries' => [
            ['key' => 'cash.balance', 'value' => 1],
            ['key' => 'cash.balanse', 'value' => 2],
        ]])
        ->assertHasErrors(['Unknown metric key', '[cash.balanse]: did you mean cash.balance?']);

    expect(MetricValue::query()->count())->toBe(0);
});

test('record_metrics warns when a metric is computed by the app', function () {
    MetricDefinition::factory()->create(['key' => 'delivery.open', 'calculator' => 'DeliveryMetrics']);

    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(RecordMetrics::class, ['entries' => [['key' => 'delivery.open', 'value' => 601]]])
        ->assertOk()
        ->assertSee('[delivery.open] is computed by the app');

    expect(MetricValue::query()->count())->toBe(1);
});

test('record_metrics validates input with actionable messages', function () {
    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(RecordMetrics::class, ['entries' => [['key' => 'cash.balance', 'value' => '1,000', 'period_start' => '2026/09/22']]])
        ->assertHasErrors(['must be a number', 'YYYY-MM-DD']);
});
