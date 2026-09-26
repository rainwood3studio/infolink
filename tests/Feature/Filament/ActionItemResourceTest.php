<?php

use App\Enums\ActionItemPriority;
use App\Enums\ActionItemStatus;
use App\Enums\Source;
use App\Filament\Resources\ActionItems\Pages\CreateActionItem;
use App\Filament\Resources\ActionItems\Pages\EditActionItem;
use App\Filament\Resources\ActionItems\Pages\ListActionItems;
use App\Models\ActionItem;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('hides finished items by default', function () {
    $pending = ActionItem::factory()->create(['status' => ActionItemStatus::Doing]);
    $done = ActionItem::factory()->create(['status' => ActionItemStatus::Done]);

    Livewire::test(ListActionItems::class)
        ->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$done]);
});

it('creates an action item', function () {
    Livewire::test(CreateActionItem::class)
        ->fillForm([
            'title' => '寄出我識尾款發票',
            'priority' => ActionItemPriority::P1,
            'status' => ActionItemStatus::Todo,
            'due_on' => '2026-10-15',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(ActionItem::sole())
        ->title->toBe('寄出我識尾款發票')
        ->priority->toBe(ActionItemPriority::P1)
        ->due_on->toDateString()->toBe('2026-10-15')
        ->source->toBe(Source::Manual);
});

it('edits an action item and stamps completion when marked done', function () {
    $item = ActionItem::factory()->create();

    Livewire::test(EditActionItem::class, ['record' => $item->getRouteKey()])
        ->fillForm(['title' => '改過的標題', 'status' => ActionItemStatus::Done])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($item->refresh())
        ->title->toBe('改過的標題')
        ->status->toBe(ActionItemStatus::Done)
        ->completed_at->not->toBeNull();
});

it('completes and reopens from the table', function () {
    $item = ActionItem::factory()->create();

    Livewire::test(ListActionItems::class)
        ->assertActionHidden(TestAction::make('reopen')->table($item))
        ->callAction(TestAction::make('complete')->table($item))
        ->assertNotified();

    expect($item->refresh())->status->toBe(ActionItemStatus::Done)->completed_at->not->toBeNull();

    Livewire::test(ListActionItems::class)
        ->filterTable('status', [ActionItemStatus::Done->value])
        ->callAction(TestAction::make('reopen')->table($item));

    expect($item->refresh())->status->toBe(ActionItemStatus::Todo)->completed_at->toBeNull();
});
