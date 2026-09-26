<?php

use App\Enums\InsightSeverity;
use App\Filament\Resources\AlertRules\Pages\CreateAlertRule;
use App\Filament\Resources\AlertRules\Pages\EditAlertRule;
use App\Filament\Resources\AlertRules\Pages\ListAlertRules;
use App\Models\AlertRule;
use App\Models\Customer;
use App\Models\Insight;
use App\Models\Receivable;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\AlertRuleSeeder;
use Database\Seeders\MetricDefinitionSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 09:00'));
    $this->actingAs(User::factory()->create());
    $this->seed(AlertRuleSeeder::class);
});

it('lists the rules with their conditions', function () {
    Livewire::test(ListAlertRules::class)
        ->assertOk()
        ->assertCanSeeTableRecords(AlertRule::all())
        ->assertSee('現金可撐月數')
        ->assertSee('cash.runway_months < 3（嚴重 < 2）')
        ->assertSee('應收逾期 > 3（嚴重 > 30）');
});

it('toggles a rule on and off from the list', function () {
    $rule = AlertRule::where('key', 'cash-low')->sole();

    Livewire::test(ListAlertRules::class)
        ->call('updateTableColumnState', 'is_active', (string) $rule->getKey(), false);

    expect($rule->refresh()->is_active)->toBeFalse();
});

it('edits thresholds, severity and templates but keeps the key', function () {
    $rule = AlertRule::where('key', 'receivable-overdue')->sole();

    Livewire::test(EditAlertRule::class, ['record' => $rule->getRouteKey()])
        ->assertSchemaStateSet(['key' => 'receivable-overdue'])
        ->fillForm([
            'threshold' => 5,
            'critical_threshold' => 45,
            'severity' => InsightSeverity::Critical,
            'title_template' => '{label} 逾期 {days} 天',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($rule->refresh())
        ->key->toBe('receivable-overdue')
        ->threshold->toEqual('5.0000')
        ->critical_threshold->toEqual('45.0000')
        ->severity->toBe(InsightSeverity::Critical)
        ->title_template->toBe('{label} 逾期 {days} 天');
});

it('creates a metric rule and requires a metric or a rule class', function () {
    $this->seed(MetricDefinitionSeeder::class);

    Livewire::test(CreateAlertRule::class)
        ->fillForm(['key' => 'bad', 'name' => 'x', 'title_template' => 't', 'fingerprint_template' => 'f'])
        ->call('create')
        ->assertHasFormErrors(['metric_key' => 'required_without', 'query_class' => 'required_without']);

    Livewire::test(CreateAlertRule::class)
        ->fillForm([
            'key' => 'ar-overdue-total',
            'name' => '逾期應收總額',
            'metric_key' => 'ar.overdue_taxed',
            'operator' => '>',
            'threshold' => 300_000,
            'title_template' => '逾期應收 {value}',
            'fingerprint_template' => 'ar-overdue-total',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(AlertRule::where('key', 'ar-overdue-total')->sole())
        ->metric_key->toBe('ar.overdue_taxed')
        ->is_active->toBeTrue();
});

it('evaluates a rule immediately from the row action', function () {
    Receivable::factory()->for(Customer::factory()->state(['short_name' => '長照']))
        ->create(['item' => '期中款', 'amount_untaxed' => 450_000, 'expected_on' => today()->subDays(5)]);
    $rule = AlertRule::where('key', 'receivable-overdue')->sole();

    Livewire::test(ListAlertRules::class)
        ->callAction(TestAction::make('evaluateNow')->table($rule))
        ->assertNotified('觸發 1 項');

    expect(Insight::sole()->title)->toBe('長照期中款 47.25 萬已逾期 5 天')
        ->and($rule->refresh()->last_evaluated_at)->not->toBeNull();
});

it('hides 立即評估 for inactive rules', function () {
    $rule = AlertRule::where('key', 'deal-stale')->sole();

    Livewire::test(ListAlertRules::class)
        ->assertActionHidden(TestAction::make('evaluateNow')->table($rule));
});
