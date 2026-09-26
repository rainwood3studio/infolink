<?php

use App\Enums\PeriodType;
use App\Mcp\Servers\InfolinkServer;
use App\Mcp\Tools\QueryMetrics;
use App\Models\MetricDefinition;
use App\Models\MetricValue;

beforeEach(function () {
    $this->travelTo('2026-09-26 10:00:00');

    MetricDefinition::factory()->create([
        'key' => 'delivery.open',
        'name' => '未結案存量',
        'unit' => 'count',
        'period_type' => PeriodType::Day,
        'better' => 'down',
        'description' => '未結案議題數',
    ]);

    foreach (['2026-09-01' => 620, '2026-09-20' => 610, '2026-09-25' => 601] as $date => $value) {
        MetricValue::factory()->create(['metric_key' => 'delivery.open', 'period_start' => $date, 'value' => $value]);
    }

    MetricValue::factory()->create(['metric_key' => 'delivery.open', 'period_start' => '2026-09-25', 'dimension' => 'project:tcsb-5f-b2c', 'value' => 211]);
    MetricValue::factory()->create(['metric_key' => 'delivery.open', 'period_start' => '2025-01-01', 'value' => 999]);
});

test('query_metrics returns the total series with its definition, oldest first', function () {
    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(QueryMetrics::class, ['keys' => ['delivery.open']])
        ->assertOk()
        ->assertSee([
            '"name":"未結案存量"', '"unit":"count"', '"period_type":"day"', '"better":"down"', '未結案議題數',
            '"series":[{"dimension":"","values":[{"period":"2026-09-01","value":620},{"period":"2026-09-20","value":610},{"period":"2026-09-25","value":601}]}]',
            '"truncated":false',
        ])
        ->assertDontSee(['project:tcsb-5f-b2c', '999']);
});

test('query_metrics filters by date range', function () {
    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(QueryMetrics::class, ['keys' => ['delivery.open'], 'from' => '2026-09-10', 'to' => '2026-09-21'])
        ->assertSee(['"from":"2026-09-10"', '"values":[{"period":"2026-09-20","value":610}]'])
        ->assertDontSee(['620', '601']);
});

test('query_metrics returns one dimension or all of them', function () {
    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(QueryMetrics::class, ['keys' => ['delivery.open'], 'dimension' => 'project:tcsb-5f-b2c'])
        ->assertSee('"series":[{"dimension":"project:tcsb-5f-b2c","values":[{"period":"2026-09-25","value":211}]}]')
        ->assertDontSee('"value":601');

    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(QueryMetrics::class, ['keys' => ['delivery.open'], 'dimension' => '*'])
        ->assertSee(['{"dimension":"","values":', '{"dimension":"project:tcsb-5f-b2c","values":', '"value":601', '"value":211']);
});

test('query_metrics keeps the newest values when truncating', function () {
    MetricDefinition::factory()->create(['key' => 'test.many', 'period_type' => PeriodType::Day]);

    foreach (range(0, QueryMetrics::MAX_VALUES_PER_KEY) as $daysAgo) {
        MetricValue::factory()->create(['metric_key' => 'test.many', 'period_start' => today()->subDays($daysAgo), 'value' => $daysAgo]);
    }

    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(QueryMetrics::class, ['keys' => ['test.many'], 'from' => '2020-01-01'])
        ->assertSee(['"truncated":true', '{"period":"2026-09-26","value":0}'])
        ->assertDontSee('"value":'.QueryMetrics::MAX_VALUES_PER_KEY.'}');
});

test('query_metrics reports unknown keys and empty series', function () {
    MetricDefinition::factory()->create(['key' => 'cash.balance']);

    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(QueryMetrics::class, ['keys' => ['cash.balance', 'nope.metric']])
        ->assertOk()
        ->assertSee(['"key":"cash.balance"', '"series":[]', '"unknown_keys":["nope.metric"]']);
});

test('query_metrics requires keys', function () {
    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(QueryMetrics::class, [])
        ->assertHasErrors(['keys']);
});

test('query_metrics needs the read ability', function () {
    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(QueryMetrics::class, ['keys' => ['delivery.open']])
        ->assertHasErrors(['not found']);
});
