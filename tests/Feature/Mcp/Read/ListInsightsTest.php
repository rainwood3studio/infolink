<?php

use App\Enums\Category;
use App\Enums\InsightSeverity;
use App\Enums\InsightStatus;
use App\Mcp\Servers\InfolinkServer;
use App\Mcp\Tools\ListInsights;
use App\Models\ActionItem;
use App\Models\Insight;

beforeEach(function () {
    $this->overdue = Insight::factory()->create([
        'fingerprint' => 'receivable-overdue:長照-期中款',
        'severity' => InsightSeverity::Critical,
        'category' => Category::Finance,
        'title' => '長照期中款逾期',
        'evidence' => ['amount_taxed' => 472500],
        'status' => InsightStatus::Acknowledged,
    ]);
    Insight::factory()->create(['fingerprint' => 'delivery-offflow:tcsb', 'severity' => InsightSeverity::Warning, 'category' => Category::Delivery, 'title' => '脫離驗收流程']);
    Insight::factory()->create(['fingerprint' => 'receivable-due:12', 'severity' => InsightSeverity::Info, 'category' => Category::Finance, 'title' => '即將到期']);
    Insight::factory()->create(['fingerprint' => 'cash-runway', 'status' => InsightStatus::Resolved, 'resolved_at' => now(), 'title' => '已解決的現金警示']);

    ActionItem::factory()->count(2)->create(['related_type' => $this->overdue->getMorphClass(), 'related_id' => $this->overdue->id]);
});

test('list_insights lists unresolved insights, most severe first, with action item counts', function () {
    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(ListInsights::class)
        ->assertOk()
        ->assertSee([
            '"total":3',
            '"id":'.$this->overdue->id.',"fingerprint":"receivable-overdue:長照-期中款","kind":"risk","severity":"critical","category":"finance","status":"acknowledged"',
            '"evidence":{"amount_taxed":472500}',
            '"first_seen_at":"',
            '"action_items_count":2',
            '脫離驗收流程',
            '即將到期',
        ])
        ->assertDontSee('已解決的現金警示');
});

test('list_insights orders critical before warning before info', function () {
    $response = InfolinkServer::actingAs(mcpUser(['read']))->tool(ListInsights::class);

    $response->assertSee('"severity":"critical"');
    expect(implode('', invade($response)->content()))
        ->toMatch('/"severity":"critical".*"severity":"warning".*"severity":"info"/s');
});

test('list_insights filters by status, severity, category and fingerprint prefix', function () {
    $user = mcpUser(['read']);

    InfolinkServer::actingAs($user)->tool(ListInsights::class, ['status' => 'resolved'])
        ->assertSee(['已解決的現金警示', '"total":1']);

    InfolinkServer::actingAs($user)->tool(ListInsights::class, ['status' => 'all'])
        ->assertSee('"total":4');

    InfolinkServer::actingAs($user)->tool(ListInsights::class, ['severity' => 'warning'])
        ->assertSee(['脫離驗收流程', '"total":1']);

    InfolinkServer::actingAs($user)->tool(ListInsights::class, ['category' => 'delivery'])
        ->assertSee('"total":1');

    InfolinkServer::actingAs($user)->tool(ListInsights::class, ['fingerprint' => 'receivable-'])
        ->assertSee(['長照期中款逾期', '即將到期', '"total":2'])
        ->assertDontSee('脫離驗收流程');

    InfolinkServer::actingAs($user)->tool(ListInsights::class, ['fingerprint' => 'overdue'])
        ->assertSee('"total":0');
});

test('list_insights is empty without insights', function () {
    Insight::query()->delete();

    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(ListInsights::class)
        ->assertOk()
        ->assertSee('"total":0,"insights":[]');
});

test('list_insights needs the read ability', function () {
    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(ListInsights::class)
        ->assertHasErrors(['not found']);
});
