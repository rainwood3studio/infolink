<?php

use App\Domain\Sales\SalesMetrics;
use App\Enums\DealStage;
use App\Models\Deal;
use App\Models\Insight;
use App\Models\MetricValue;
use Carbon\CarbonImmutable;
use Database\Seeders\AlertRuleSeeder;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-26 07:05'));
    $this->seed(MetricDefinitionSeeder::class);
    $this->value = fn (string $key, string $dimension = '', ?string $period = null): ?float => ($row = MetricValue::query()
        ->where('metric_key', $key)
        ->where('dimension', $dimension)
        ->when($period !== null, fn ($query) => $query->whereDate('period_start', $period))
        ->first()) === null ? null : (float) $row->value;
});

test('it records the pipeline, open counts by stage, deals without next action and won amount', function () {
    Deal::factory()->create(['stage' => DealStage::Lead, 'amount_untaxed' => 100_000, 'probability' => 10, 'next_action_on' => null]);
    Deal::factory()->create(['stage' => DealStage::Proposal, 'amount_untaxed' => 1_000_000, 'probability' => 30, 'next_action_on' => '2026-09-25']);
    Deal::factory()->create(['stage' => DealStage::Proposal, 'amount_untaxed' => 200_000, 'probability' => 50, 'next_action_on' => '2026-09-26']);
    Deal::factory()->create(['stage' => DealStage::Won, 'amount_untaxed' => 900_000, 'closed_at' => '2026-09-03 12:00']);
    Deal::factory()->create(['stage' => DealStage::Won, 'amount_untaxed' => 400_000, 'closed_at' => '2026-08-31 18:00']);
    Deal::factory()->create(['stage' => DealStage::Lost, 'amount_untaxed' => 700_000, 'closed_at' => '2026-09-10']);

    app(SalesMetrics::class)->record();

    expect(($this->value)('sales.pipeline_weighted'))->toBe(10_000.0 + 300_000 + 100_000)
        ->and(($this->value)('sales.deals_open'))->toBe(3.0)
        ->and(($this->value)('sales.deals_open', 'stage:lead'))->toBe(1.0)
        ->and(($this->value)('sales.deals_open', 'stage:proposal'))->toBe(2.0)
        ->and(($this->value)('sales.deals_open', 'stage:negotiation'))->toBe(0.0)
        ->and(($this->value)('sales.deals_no_next_action'))->toBe(2.0)
        ->and(($this->value)('sales.won_amount', '', '2026-09-01'))->toBe(900_000.0)
        ->and(($this->value)('sales.won_amount', '', '2026-08-01'))->toBe(400_000.0)
        ->and(MetricValue::where('metric_key', 'sales.pipeline_weighted')->sole()->period_start->toDateString())->toBe('2026-09-26');
});

test('the command records the metrics idempotently and evaluates the rules', function () {
    $this->seed(AlertRuleSeeder::class);
    Deal::factory()->create(['next_action_on' => null, 'updated_at' => '2026-09-01']);

    $this->artisan('infolink:snapshot-sales')->assertSuccessful();
    $this->artisan('infolink:snapshot-sales')->assertSuccessful();

    expect(MetricValue::where('metric_key', 'sales.deals_no_next_action')->count())->toBe(1)
        ->and(Insight::query()->where('fingerprint', 'like', 'deal-stale:%')->count())->toBe(1);
});

test('it is scheduled daily at 07:05', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event) => str_contains((string) $event->command, 'infolink:snapshot-sales'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('5 7 * * *');
});
