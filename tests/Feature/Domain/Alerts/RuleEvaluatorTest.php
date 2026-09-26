<?php

use App\Domain\Alerts\RuleEvaluator;
use App\Domain\Insights\InsightService;
use App\Enums\Category;
use App\Enums\InsightSeverity;
use App\Enums\InsightStatus;
use App\Enums\Source;
use App\Enums\SyncJob;
use App\Enums\SyncStatus;
use App\Models\AlertRule;
use App\Models\BankTransaction;
use App\Models\Customer;
use App\Models\Insight;
use App\Models\Receivable;
use App\Models\SyncRun;
use Carbon\CarbonImmutable;
use Database\Seeders\AlertRuleSeeder;
use Database\Seeders\MetricDefinitionSeeder;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 09:00'));
    $this->seed([MetricDefinitionSeeder::class, AlertRuleSeeder::class]);
    $this->evaluator = app(RuleEvaluator::class);
    $this->overdue = fn (int $days = 5) => Receivable::factory()
        ->for(Customer::query()->firstWhere('short_name', '長照') ?? Customer::factory()->state(['short_name' => '長照']))
        ->create(['item' => '期中款', 'amount_untaxed' => 450_000, 'expected_on' => today()->subDays($days)]);
});

test('a firing rule raises a system insight with the rendered title and fingerprint', function () {
    $receivable = ($this->overdue)();

    $result = $this->evaluator->evaluate('receivable-overdue');

    $insight = Insight::sole();

    expect($insight)
        ->fingerprint->toBe("receivable-overdue:{$receivable->id}")
        ->title->toBe('長照期中款 47.25 萬已逾期 5 天')
        ->severity->toBe(InsightSeverity::Warning)
        ->category->toBe(Category::Finance)
        ->source->toBe(Source::System)
        ->status->toBe(InsightStatus::Open)
        ->and($insight->body)->toContain('NT$472,500')->toContain('**建議**')
        ->and($insight->evidence)->toMatchArray(['alert_rule' => 'receivable-overdue', 'receivable_id' => $receivable->id, 'days_overdue' => 5])
        ->and($result->stats())->toMatchArray(['rules' => 1, 'fired' => 1, 'raised' => 1, 'updated' => 0, 'resolved' => 0]);
});

test('evaluating twice keeps the same insights and counts the second pass as updates', function () {
    ($this->overdue)();
    ($this->overdue)(40);

    $this->evaluator->evaluate();
    $ids = Insight::query()->orderBy('id')->pluck('id')->all();

    $second = $this->evaluator->evaluate();

    expect(Insight::query()->orderBy('id')->pluck('id')->all())->toBe($ids)
        ->and($ids)->toHaveCount(2)
        ->and($second->stats())->toMatchArray(['fired' => 2, 'raised' => 0, 'updated' => 2, 'resolved' => 0]);
});

test('an insight whose condition cleared is resolved automatically', function () {
    $receivable = ($this->overdue)();
    $this->evaluator->evaluate();

    $receivable->update(['status' => 'received', 'received_on' => today()]);
    $result = $this->evaluator->evaluate();

    expect(Insight::sole())
        ->status->toBe(InsightStatus::Resolved)
        ->notes->toContain(RuleEvaluator::RESOLVE_NOTE)
        ->and($result->stats()['resolved'])->toBe(1);
});

test('auto-resolve only touches system insights the rule owns', function () {
    $service = app(InsightService::class);
    $claude = $service->raise(['fingerprint' => 'receivable-overdue:長照-期中款', 'title' => 'x', 'category' => Category::Finance], Source::Claude);
    $other = $service->raise(['fingerprint' => 'cash-runway-note', 'title' => 'y', 'category' => Category::Finance], Source::System);
    $foreign = $service->raise(['fingerprint' => 'receivable-overdue-extra:1', 'title' => 'z', 'category' => Category::Finance], Source::System);

    $this->evaluator->evaluate();

    expect($claude->refresh()->status)->toBe(InsightStatus::Open)
        ->and($other->refresh()->status)->toBe(InsightStatus::Open)
        ->and($foreign->refresh()->status)->toBe(InsightStatus::Open);
});

test('a dry run reports what would happen but writes nothing', function () {
    ($this->overdue)();

    $result = $this->evaluator->evaluate(dryRun: true);

    expect(Insight::count())->toBe(0)
        ->and(SyncRun::count())->toBe(0)
        ->and(AlertRule::query()->whereNotNull('last_evaluated_at')->count())->toBe(0)
        ->and($result->stats())->toMatchArray(['fired' => 1, 'raised' => 1])
        ->and(collect($result->outcomes)->firstWhere('key', 'receivable-overdue')->firings[0]['title'])->toBe('長照期中款 47.25 萬已逾期 5 天');
});

test('a dry run lists the resolutions it would make without resolving', function () {
    $receivable = ($this->overdue)();
    $this->evaluator->evaluate();
    $receivable->update(['status' => 'received', 'received_on' => today()]);

    $result = $this->evaluator->evaluate(dryRun: true);

    expect($result->stats()['resolved'])->toBe(1)
        ->and(Insight::sole()->status)->toBe(InsightStatus::Open);
});

test('inactive rules are skipped', function () {
    ($this->overdue)();
    AlertRule::query()->where('key', 'receivable-overdue')->update(['is_active' => false]);

    $result = $this->evaluator->evaluate();

    expect(Insight::count())->toBe(0)
        ->and(collect($result->outcomes)->pluck('key'))->not->toContain('receivable-overdue');
});

test('each evaluation is recorded as a rules sync run with stats', function () {
    ($this->overdue)();

    $this->evaluator->evaluate();

    $run = SyncRun::sole();

    expect($run)
        ->job->toBe(SyncJob::Rules)
        ->status->toBe(SyncStatus::Ok)
        ->finished_at->not->toBeNull()
        ->and($run->stats)->toBe(['rules' => AlertRule::where('is_active', true)->count(), 'fired' => 1, 'raised' => 1, 'updated' => 0, 'resolved' => 0])
        ->and(AlertRule::where('key', 'receivable-overdue')->sole()->last_evaluated_at->toDateTimeString())->toBe('2026-09-24 09:00:00');
});

test('a broken rule fails the run but the other rules still run and keep their insights', function () {
    ($this->overdue)();
    $this->evaluator->evaluate();
    AlertRule::factory()->create(['key' => 'broken', 'metric_key' => null, 'query_class' => 'App\\Nope', 'fingerprint_template' => 'receivable-overdue:{id}']);

    $result = $this->evaluator->evaluate();
    $run = SyncRun::query()->latest('id')->first();

    expect($result->stats())->toMatchArray(['errors' => 1, 'updated' => 1, 'resolved' => 0])
        ->and($run->status)->toBe(SyncStatus::Failed)
        ->and($run->error)->toContain('broken:')
        ->and(Insight::sole()->status)->toBe(InsightStatus::Open);
});

test('severity escalates on the same insight when the condition worsens', function () {
    $receivable = ($this->overdue)(29);
    $this->evaluator->evaluate();
    $insight = Insight::sole();
    app(InsightService::class)->acknowledge($insight);

    $this->travel(2)->days();
    $this->evaluator->evaluate();

    expect($insight->refresh())
        ->severity->toBe(InsightSeverity::Critical)
        ->status->toBe(InsightStatus::Open)
        ->title->toBe('長照期中款 47.25 萬已逾期 31 天')
        ->and(Insight::count())->toBe(1);
});

test('the command prints firings and supports --rule and --dry-run', function () {
    ($this->overdue)();

    $this->artisan('infolink:evaluate-rules', ['--rule' => 'receivable-overdue', '--dry-run' => true])
        ->expectsOutputToContain('長照期中款 47.25 萬已逾期 5 天')
        ->expectsOutputToContain('[dry run] 1 rule(s): 1 firing (1 new, 0 updated), 0 resolved.')
        ->assertSuccessful();

    expect(Insight::count())->toBe(0);

    $this->artisan('infolink:evaluate-rules')->assertSuccessful();

    expect(Insight::count())->toBe(1);
});

test('the command rejects an unknown or inactive rule', function (string $key) {
    AlertRule::query()->where('key', 'deal-stale')->update(['is_active' => false]);

    $this->artisan('infolink:evaluate-rules', ['--rule' => $key])->assertFailed();
})->with(['nope', 'deal-stale']);

test('the finance snapshot evaluates the rules afterwards', function () {
    ($this->overdue)();
    BankTransaction::factory()->create(['txn_date' => today(), 'balance' => 5_000_000]);

    $this->artisan('infolink:snapshot-finance')->assertSuccessful();

    expect(SyncRun::where('job', SyncJob::Rules)->count())->toBe(1)
        ->and(Insight::where('fingerprint', 'like', 'receivable-overdue:%')->count())->toBe(1);
});

test('the redmine snapshot evaluates the rules afterwards', function () {
    $this->artisan('infolink:snapshot-redmine')->assertSuccessful();

    expect(SyncRun::where('job', SyncJob::Rules)->count())->toBe(1);
});

test('templates render placeholders and leave unknown ones', function () {
    expect(RuleEvaluator::render('{a} 與 {b}', ['a' => 1]))->toBe('1 與 {b}');
});
