<?php

use App\Enums\Source;
use App\Mcp\Servers\InfolinkServer;
use App\Mcp\Tools\CreateActionItem;
use App\Mcp\Tools\ImportBankTransactions;
use App\Mcp\Tools\RaiseInsight;
use App\Mcp\Tools\RecordMetrics;
use App\Mcp\Tools\RecordReceivablePayment;
use App\Mcp\Tools\ResolveInsight;
use App\Mcp\Tools\SaveCashForecast;
use App\Mcp\Tools\SaveReport;
use App\Mcp\Tools\UpdateActionItem;
use App\Mcp\Tools\UpsertReceivable;
use App\Models\Insight;
use App\Models\User;

$writeTools = [
    RecordMetrics::class,
    ImportBankTransactions::class,
    UpsertReceivable::class,
    RecordReceivablePayment::class,
    SaveCashForecast::class,
    RaiseInsight::class,
    ResolveInsight::class,
    CreateActionItem::class,
    UpdateActionItem::class,
    SaveReport::class,
];

test('a read-only token cannot see write tools', function () use ($writeTools) {
    InfolinkServer::actingAs(mcpUser(['read']))
        ->tools()
        ->assertNotRegistered($writeTools);
});

test('a write token sees every write tool', function () use ($writeTools) {
    InfolinkServer::actingAs(mcpUser(['write']))
        ->tools()
        ->assertRegistered($writeTools);
});

test('a read-only token cannot call a write tool', function () {
    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(RaiseInsight::class, [
            'fingerprint' => 'cash-low:2026-11',
            'kind' => 'risk',
            'severity' => 'warning',
            'category' => 'finance',
            'title' => '11 月現金低於安全水位',
        ])
        ->assertHasErrors(['not found']);

    expect(Insight::query()->count())->toBe(0);
});

test('a read-only token cannot call a write tool over http', function () {
    $user = User::factory()->create();
    $token = $user->createToken('claude-cli', ['read'])->plainTextToken;

    $this->withToken($token)
        ->postJson('/mcp/infolink', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'save_report', 'arguments' => ['type' => 'adhoc', 'period_start' => '2026-09-26', 'title' => 'x', 'body' => 'x']],
        ])
        ->assertJsonPath('error.message', 'Tool [save_report] not found.');
});

test('writes through a token record the token name as actor and claude as source', function () {
    InfolinkServer::actingAs(mcpUser(['write'], 'claude-cli'))
        ->tool(RaiseInsight::class, [
            'fingerprint' => 'cash-low:2026-11',
            'kind' => 'risk',
            'severity' => 'warning',
            'category' => 'finance',
            'title' => '11 月現金低於安全水位',
        ])
        ->assertOk();

    $insight = Insight::query()->sole();

    expect($insight->actor)->toBe('claude-cli')
        ->and($insight->source)->toBe(Source::Claude);
});
