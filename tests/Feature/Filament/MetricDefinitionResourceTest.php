<?php

use App\Enums\PeriodType;
use App\Enums\Source;
use App\Filament\Resources\MetricDefinitions\Pages\EditMetricDefinition;
use App\Filament\Resources\MetricDefinitions\Pages\ListMetricDefinitions;
use App\Filament\Resources\MetricDefinitions\RelationManagers\ValuesRelationManager;
use App\Models\MetricDefinition;
use App\Models\MetricValue;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('records a manual value for a non-calculated metric', function () {
    $metric = MetricDefinition::factory()->create(['period_type' => PeriodType::Month, 'calculator' => null]);

    Livewire::test(ListMetricDefinitions::class)
        ->callAction(TestAction::make('recordValue')->table($metric), data: ['period_start' => '2026-09-17', 'value' => 12])
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect(MetricValue::sole())
        ->metric_key->toBe($metric->key)
        ->period_start->toDateString()->toBe('2026-09-01')
        ->value->toEqual('12.0000')
        ->source->toBe(Source::Manual);
});

it('does not offer manual values for calculated metrics', function () {
    $metric = MetricDefinition::factory()->create(['calculator' => 'App\\Domain\\Metrics\\Calculators\\CashBalance']);

    Livewire::test(ListMetricDefinitions::class)
        ->assertActionHidden(TestAction::make('recordValue')->table($metric));
});

it('edits thresholds and pinning but keeps the key', function () {
    $metric = MetricDefinition::factory()->create();

    Livewire::test(EditMetricDefinition::class, ['record' => $metric->getRouteKey()])
        ->fillForm(['name' => '現金餘額', 'warn_threshold' => 1_000_000, 'is_pinned' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($metric->refresh())
        ->name->toBe('現金餘額')
        ->warn_threshold->toEqual('1000000.0000')
        ->is_pinned->toBeTrue();
});

it('lists values and records one from the relation manager', function () {
    $metric = MetricDefinition::factory()->create(['period_type' => PeriodType::Snapshot]);
    $value = MetricValue::factory()->for($metric, 'definition')->create(['period_start' => '2026-08-01']);

    Livewire::test(ValuesRelationManager::class, ['ownerRecord' => $metric, 'pageClass' => EditMetricDefinition::class])
        ->assertOk()
        ->assertCanSeeTableRecords([$value])
        ->callAction(TestAction::make('recordValue')->table(), data: ['period_start' => '2026-09-26', 'value' => 3.5, 'dimension' => 'saas'])
        ->assertHasNoFormErrors();

    expect($metric->values()->where('dimension', 'saas')->sole()->value)->toEqual('3.5000');
});
