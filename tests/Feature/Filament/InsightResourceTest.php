<?php

use App\Enums\ActionItemPriority;
use App\Enums\InsightSeverity;
use App\Enums\InsightStatus;
use App\Filament\Resources\Insights\Pages\ListInsights;
use App\Filament\Resources\Insights\Pages\ViewInsight;
use App\Models\Insight;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('shows unresolved insights by default', function () {
    $open = Insight::factory()->create();
    $resolved = Insight::factory()->create(['status' => InsightStatus::Resolved]);

    Livewire::test(ListInsights::class)
        ->assertCanSeeTableRecords([$open])
        ->assertCanNotSeeTableRecords([$resolved]);
});

it('acknowledges, then resolves an insight', function () {
    $insight = Insight::factory()->create();

    Livewire::test(ListInsights::class)
        ->callAction(TestAction::make('acknowledge')->table($insight))
        ->assertNotified();

    expect($insight->refresh()->status)->toBe(InsightStatus::Acknowledged);

    Livewire::test(ListInsights::class)
        ->assertActionHidden(TestAction::make('acknowledge')->table($insight))
        ->callAction(TestAction::make('resolve')->table($insight), data: ['note' => '已催款']);

    expect($insight->refresh())
        ->status->toBe(InsightStatus::Resolved)
        ->resolved_at->not->toBeNull();
});

it('dismisses an insight', function () {
    $insight = Insight::factory()->create();

    Livewire::test(ListInsights::class)
        ->callAction(TestAction::make('dismiss')->table($insight));

    expect($insight->refresh()->status)->toBe(InsightStatus::Dismissed);
});

it('creates a linked action item from an insight', function () {
    $insight = Insight::factory()->create(['severity' => InsightSeverity::Critical, 'title' => '長照期中款已逾期']);

    Livewire::test(ViewInsight::class, ['record' => $insight->getRouteKey()])
        ->assertOk()
        ->mountAction('createActionItem')
        ->assertSchemaStateSet(['title' => '長照期中款已逾期', 'priority' => ActionItemPriority::P1])
        ->setActionData(['due_on' => '2026-09-30'])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect($insight->actionItems()->sole())
        ->title->toBe('長照期中款已逾期')
        ->priority->toBe(ActionItemPriority::P1)
        ->due_on->toDateString()->toBe('2026-09-30');
});
