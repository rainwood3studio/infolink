<?php

use App\Enums\Category;
use App\Mcp\Servers\InfolinkServer;
use App\Mcp\Tools\ListMetricDefinitions;
use App\Models\MetricDefinition;

test('list_metric_definitions returns definitions with thresholds and descriptions', function () {
    MetricDefinition::factory()->create([
        'key' => 'cash.runway_months',
        'name' => '現金可撐月數',
        'unit' => 'months',
        'warn_threshold' => 3,
        'critical_threshold' => 2,
        'calculator' => 'finance_position',
        'is_pinned' => true,
        'description' => '餘額 ÷ 常態月成本',
    ]);

    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(ListMetricDefinitions::class)
        ->assertOk()
        ->assertSee(['"key":"cash.runway_months"', '"name":"現金可撐月數"', '"warn_threshold":3', '"critical_threshold":2', '"calculator":"finance_position"', '"is_pinned":true', '餘額 ÷ 常態月成本']);
});

test('list_metric_definitions filters by category and pinned', function () {
    MetricDefinition::factory()->create(['key' => 'cash.balance', 'category' => Category::Finance, 'is_pinned' => true]);
    MetricDefinition::factory()->create(['key' => 'cash.monthly_cost', 'category' => Category::Finance, 'is_pinned' => false]);
    MetricDefinition::factory()->create(['key' => 'delivery.open', 'category' => Category::Delivery, 'is_pinned' => true]);

    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(ListMetricDefinitions::class, ['category' => 'finance'])
        ->assertSee(['cash.balance', 'cash.monthly_cost', '"count":2'])
        ->assertDontSee('delivery.open');

    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(ListMetricDefinitions::class, ['pinned_only' => true])
        ->assertSee(['cash.balance', 'delivery.open'])
        ->assertDontSee('cash.monthly_cost');
});

test('list_metric_definitions rejects an unknown category', function () {
    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(ListMetricDefinitions::class, ['category' => 'marketing'])
        ->assertHasErrors();
});

test('list_metric_definitions is empty without definitions', function () {
    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(ListMetricDefinitions::class)
        ->assertOk()
        ->assertSee('"count":0,"definitions":[]');
});

test('list_metric_definitions needs the read ability', function () {
    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(ListMetricDefinitions::class)
        ->assertHasErrors(['not found']);
});
