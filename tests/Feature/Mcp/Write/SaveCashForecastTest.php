<?php

use App\Enums\Source;
use App\Mcp\Servers\InfolinkServer;
use App\Mcp\Tools\SaveCashForecast;
use App\Models\BankTransaction;
use App\Models\CashForecast;
use App\Models\CostBaseline;
use App\Models\Receivable;

beforeEach(function () {
    BankTransaction::factory()->create(['txn_date' => '2026-09-22', 'withdrawal' => 0, 'deposit' => 0, 'balance' => 1_072_776]);
    CostBaseline::factory()->create(['effective_from' => '2026-01-01', 'monthly_cost' => 229_666]);
    Receivable::factory()->create(['amount_untaxed' => 450_000, 'expected_on' => '2026-10-31']);
});

test('save_cash_forecast saves a snapshot from the latest bank balance', function () {
    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(SaveCashForecast::class)
        ->assertOk()
        ->assertSee(['"result":"created"', '"as_of":"2026-09-22"', '"opening_balance":1072776', '"opening_balance_source":"bank"', '"month":"2026-10","inflow":472500,"outflow":229666']);

    $forecast = CashForecast::query()->sole();

    expect($forecast->source)->toBe(Source::Claude)
        ->and($forecast->actor)->toBe('claude-cli')
        ->and($forecast->assumptions)->toHaveKey('label', null);
});

test('save_cash_forecast is idempotent on as_of and label', function () {
    $save = fn (array $arguments) => InfolinkServer::actingAs(mcpUser(['write']))->tool(SaveCashForecast::class, $arguments);

    $save(['as_of' => '2026-09-22'])->assertOk();
    $save(['as_of' => '2026-09-22'])->assertOk()->assertSee('"result":"updated"');
    $save(['as_of' => '2026-09-22', 'label' => '長照延到11月'])->assertOk()->assertSee('"result":"created"');
    $save(['as_of' => '2026-09-22', 'label' => '長照延到11月', 'opening_balance' => 1_000_000])
        ->assertOk()
        ->assertSee(['"result":"updated"', '"opening_balance":1000000']);

    expect(CashForecast::query()->count())->toBe(2)
        ->and(CashForecast::query()->where('assumptions->label', '長照延到11月')->sole()->opening_balance)->toBe(1_000_000);
});

test('save_cash_forecast never overwrites a snapshot saved by the app', function () {
    $system = CashForecast::factory()->create(['as_of' => '2026-09-22', 'source' => Source::System, 'opening_balance' => 5]);

    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(SaveCashForecast::class, ['as_of' => '2026-09-22'])
        ->assertOk()
        ->assertSee('"result":"created"');

    expect(CashForecast::query()->count())->toBe(2)
        ->and($system->refresh()->opening_balance)->toBe(5);
});

test('save_cash_forecast validates its arguments', function () {
    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(SaveCashForecast::class, ['as_of' => '22/09/2026', 'until' => 'next year'])
        ->assertHasErrors(['as_of must be YYYY-MM-DD.', 'until must be YYYY-MM or YYYY-MM-DD.']);
});
