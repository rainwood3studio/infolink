<?php

use App\Enums\InsightSeverity;
use App\Enums\InsightStatus;
use App\Enums\Source;
use App\Mcp\Servers\InfolinkServer;
use App\Mcp\Tools\RaiseInsight;
use App\Mcp\Tools\ResolveInsight;
use App\Models\Insight;

/**
 * @return array<string, mixed>
 */
function mcpWriteOverdueInsight(array $overrides = []): array
{
    return [
        'fingerprint' => 'receivable-overdue:長照-期中款',
        'kind' => 'risk',
        'severity' => 'warning',
        'category' => 'finance',
        'title' => '長照期中款 47.25 萬逾期 3 天',
        'body' => "## 依據\n\n預計 9/30 入帳，尚未收到。",
        'evidence' => ['receivable_id' => 42, 'amount_taxed' => 472500],
        ...$overrides,
    ];
}

test('raise_insight creates an insight', function () {
    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(RaiseInsight::class, mcpWriteOverdueInsight())
        ->assertOk()
        ->assertSee(['"result":"created"', '"status":"open"']);

    $insight = Insight::query()->sole();

    expect($insight->source)->toBe(Source::Claude)
        ->and($insight->evidence)->toEqualCanonicalizing(['receivable_id' => 42, 'amount_taxed' => 472500]);
});

test('raise_insight is idempotent on fingerprint and says it updated', function () {
    InfolinkServer::actingAs(mcpUser(['write']))->tool(RaiseInsight::class, mcpWriteOverdueInsight())->assertOk();

    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(RaiseInsight::class, mcpWriteOverdueInsight(['severity' => 'critical', 'title' => '長照期中款 47.25 萬逾期 10 天']))
        ->assertOk()
        ->assertSee(['"result":"updated"', '"severity":"critical"']);

    $insight = Insight::query()->sole();

    expect($insight->severity)->toBe(InsightSeverity::Critical)
        ->and($insight->title)->toBe('長照期中款 47.25 萬逾期 10 天');
});

test('raise_insight rejects a malformed fingerprint and unknown enums', function (array $overrides, string $message) {
    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(RaiseInsight::class, mcpWriteOverdueInsight($overrides))
        ->assertHasErrors([$message]);

    expect(Insight::query()->count())->toBe(0);
})->with([
    'no colon' => [['fingerprint' => 'receivable-overdue-長照'], 'fingerprint must look like'],
    'empty identifier' => [['fingerprint' => 'cash-low:'], 'fingerprint must look like'],
    'uppercase prefix' => [['fingerprint' => 'Cash Low:2026-11'], 'fingerprint must look like'],
    'severity' => [['severity' => 'high'], 'severity must be one of: critical, warning, info'],
    'category' => [['category' => 'hr'], 'category must be one of: finance, sales, delivery, company'],
]);

test('resolve_insight resolves by fingerprint and is idempotent', function () {
    $insight = Insight::factory()->create(['fingerprint' => 'receivable-overdue:長照-期中款']);

    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(ResolveInsight::class, ['fingerprint' => 'receivable-overdue:長照-期中款', 'note' => '9/30 已入帳 472,500'])
        ->assertOk()
        ->assertSee(['"result":"resolved"', '"resolved":1']);

    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(ResolveInsight::class, ['fingerprint' => 'receivable-overdue:長照-期中款', 'note' => '9/30 已入帳 472,500'])
        ->assertOk()
        ->assertSee('"result":"already_closed"');

    expect($insight->refresh()->status)->toBe(InsightStatus::Resolved)
        ->and($insight->notes)->toBe('9/30 已入帳 472,500');
});

test('resolve_insight resolves by id', function () {
    $insight = Insight::factory()->create();

    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(ResolveInsight::class, ['id' => $insight->id, 'note' => '已處理'])
        ->assertOk()
        ->assertSee('"result":"resolved"');

    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(ResolveInsight::class, ['id' => $insight->id, 'note' => '已處理'])
        ->assertOk()
        ->assertSee(['"result":"already_closed"', '"status":"resolved"']);

    expect($insight->refresh()->status)->toBe(InsightStatus::Resolved);
});

test('resolve_insight needs an identifier, a note, and an existing insight', function () {
    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(ResolveInsight::class, [])
        ->assertHasErrors(['Identify the insight', 'Pass a `note`']);

    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(ResolveInsight::class, ['fingerprint' => 'cash-low:2030-01', 'note' => 'x'])
        ->assertHasErrors(['No insight with fingerprint [cash-low:2030-01]']);
});
