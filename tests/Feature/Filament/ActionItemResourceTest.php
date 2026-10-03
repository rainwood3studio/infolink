<?php

use App\Enums\ActionItemPriority;
use App\Enums\ActionItemStatus;
use App\Enums\Source;
use App\Filament\Resources\ActionItems\Pages\CreateActionItem;
use App\Filament\Resources\ActionItems\Pages\EditActionItem;
use App\Filament\Resources\ActionItems\Pages\ListActionItems;
use App\Models\ActionItem;
use App\Models\Developer;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
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
        ->owner->toBe('Kenneth')
        ->source->toBe(Source::Manual);
});

it('offers the owner and the active developers as owner, and delegates on save', function () {
    Developer::factory()->create(['name' => '裕樺']);
    Developer::factory()->create(['name' => '離職的人', 'is_active' => false]);

    Livewire::test(CreateActionItem::class)
        ->assertFormFieldExists('owner', fn (Select $field): bool => $field->getOptions() === ['Kenneth' => 'Kenneth', '裕樺' => '裕樺'])
        ->assertFormSet(['owner' => 'Kenneth'])
        ->fillForm(['title' => '整理驗證中議題', 'owner' => '裕樺'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(ActionItem::sole())->owner->toBe('裕樺')->isMine()->toBeFalse();
});

it('keeps an owner that is not on the list selectable when editing', function () {
    $item = ActionItem::factory()->create(['owner' => '外包小陳']);

    Livewire::test(EditActionItem::class, ['record' => $item->getRouteKey()])
        ->assertFormFieldExists('owner', fn (Select $field): bool => $field->getOptions() === ['Kenneth' => 'Kenneth', '外包小陳' => '外包小陳'])
        ->fillForm(['title' => '改過的標題'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($item->refresh())->title->toBe('改過的標題')->owner->toBe('外包小陳');
});

it('filters the table by mine and delegated', function () {
    $mine = ActionItem::factory()->create(['owner' => 'Kenneth']);
    $unowned = ActionItem::factory()->create(['owner' => null]);
    $delegated = ActionItem::factory()->create(['owner' => '裕樺']);

    Livewire::test(ListActionItems::class)
        ->assertCanSeeTableRecords([$mine, $unowned, $delegated])
        ->filterTable('ownership', 'mine')
        ->assertCanSeeTableRecords([$mine, $unowned])
        ->assertCanNotSeeTableRecords([$delegated])
        ->filterTable('ownership', 'delegated')
        ->assertCanSeeTableRecords([$delegated])
        ->assertCanNotSeeTableRecords([$mine, $unowned]);
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
