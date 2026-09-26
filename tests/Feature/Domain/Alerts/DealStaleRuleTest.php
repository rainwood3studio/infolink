<?php

use App\Domain\Alerts\RuleEvaluator;
use App\Enums\Category;
use App\Enums\DealStage;
use App\Enums\InsightSeverity;
use App\Enums\InsightStatus;
use App\Models\AlertRule;
use App\Models\Deal;
use App\Models\Insight;
use Carbon\CarbonImmutable;
use Database\Seeders\AlertRuleSeeder;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-26 09:00'));
    $this->seed(AlertRuleSeeder::class);
    $this->evaluate = fn () => app(RuleEvaluator::class)->evaluate('deal-stale');
});

test('fires per deal whose next action has been overdue for 7 days or more', function () {
    $stale = Deal::factory()->create([
        'prospect_name' => '多羅滿賞鯨', 'title' => '賞鯨訂位中樞', 'next_action' => '寄修正版提案', 'next_action_on' => '2026-09-19',
    ]);
    Deal::factory()->create(['next_action_on' => '2026-09-20']);
    Deal::factory()->create(['next_action_on' => '2026-10-01']);

    ($this->evaluate)();

    expect(Insight::sole())
        ->fingerprint->toBe("deal-stale:{$stale->id}")
        ->severity->toBe(InsightSeverity::Info)
        ->category->toBe(Category::Sales)
        ->title->toBe('多羅滿賞鯨「賞鯨訂位中樞」下一步「寄修正版提案」已逾期 7 天')
        ->evidence->toMatchArray(['alert_rule' => 'deal-stale', 'deal_id' => $stale->id, 'days_without_next_action' => 7]);
});

test('a deal without a next action counts from its last update', function () {
    $stale = Deal::factory()->create(['prospect_name' => 'A', 'title' => 'T', 'next_action' => null, 'next_action_on' => null, 'updated_at' => '2026-09-15 18:00']);
    Deal::factory()->create(['next_action_on' => null, 'updated_at' => '2026-09-21']);

    ($this->evaluate)();

    expect(Insight::sole())
        ->fingerprint->toBe("deal-stale:{$stale->id}")
        ->title->toBe('A「T」沒有下一步已 11 天');
});

test('closed deals never fire', function (DealStage $stage) {
    Deal::factory()->create(['stage' => $stage, 'next_action_on' => null, 'updated_at' => '2026-01-01']);

    ($this->evaluate)();

    expect(Insight::count())->toBe(0);
})->with([DealStage::Won, DealStage::Lost]);

test('resolves once the deal gets a future next action', function () {
    $deal = Deal::factory()->create(['next_action_on' => '2026-09-01']);
    ($this->evaluate)();

    $deal->update(['next_action_on' => '2026-10-01']);
    ($this->evaluate)();

    expect(Insight::sole()->status)->toBe(InsightStatus::Resolved);
});

test('the threshold in days is editable on the rule', function () {
    Deal::factory()->create(['next_action_on' => '2026-09-23']);
    AlertRule::where('key', 'deal-stale')->update(['threshold' => 3]);

    ($this->evaluate)();

    expect(Insight::count())->toBe(1);
});
