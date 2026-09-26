<?php

use App\Enums\DealEventType;
use App\Enums\DealStage;
use App\Filament\Resources\Deals\Pages\CreateDeal;
use App\Filament\Resources\Deals\Pages\DealBoard;
use App\Filament\Resources\Deals\Pages\EditDeal;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\Deals\RelationManagers\EventsRelationManager;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\DealEvent;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('lists open deals by default with party, weighted amount and last interaction', function () {
    $customer = Customer::factory()->create(['short_name' => '墊腳石']);
    $open = Deal::factory()->create(['customer_id' => $customer->id, 'title' => '5F B2C 擴充', 'amount_untaxed' => 1_000_000, 'probability' => 30]);
    DealEvent::factory()->create(['deal_id' => $open->id, 'occurred_on' => '2026-09-20']);
    $won = Deal::factory()->create(['stage' => DealStage::Won]);

    Livewire::test(ListDeals::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$open])
        ->assertCanNotSeeTableRecords([$won])
        ->assertSee('墊腳石')
        ->assertSee('NT$300,000')
        ->filterTable('open', false)
        ->assertCanSeeTableRecords([$open, $won]);

    // SQLite returns '2026-09-20 00:00:00', Postgres '2026-09-20'.
    expect((string) Deal::withMax('events', 'occurred_on')->find($open->id)->events_max_occurred_on)->toStartWith('2026-09-20');
});

it('filters deals without a usable next action', function () {
    $planned = Deal::factory()->create();
    $missing = Deal::factory()->create(['next_action' => null, 'next_action_on' => null]);
    $overdue = Deal::factory()->create(['next_action_on' => today()->subDay()]);

    Livewire::test(ListDeals::class)
        ->filterTable('no_next_action', true)
        ->assertCanSeeTableRecords([$missing, $overdue])
        ->assertCanNotSeeTableRecords([$planned]);
});

it('creates a deal for a prospect', function () {
    Livewire::test(CreateDeal::class)
        ->fillForm([
            'prospect_name' => '多羅滿賞鯨',
            'title' => '官網改版',
            'stage' => DealStage::Lead,
            'probability' => 20,
            'amount_untaxed' => 500_000,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Deal::sole())
        ->party_name->toBe('多羅滿賞鯨')
        ->stage->toBe(DealStage::Lead)
        ->weighted_amount->toBe(100_000);
});

it('requires a customer or a prospect name', function () {
    Livewire::test(CreateDeal::class)
        ->fillForm(['title' => '沒有對象', 'stage' => DealStage::Lead, 'probability' => 10])
        ->call('create')
        ->assertHasFormErrors(['prospect_name' => 'required']);
});

it('logs a stage change event when the stage is changed in the edit form', function () {
    $deal = Deal::factory()->create(['stage' => DealStage::Proposal]);

    Livewire::test(EditDeal::class, ['record' => $deal->getRouteKey()])
        ->fillForm(['stage' => DealStage::Negotiation, 'title' => '改過的標題'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($deal->refresh())
        ->stage->toBe(DealStage::Negotiation)
        ->title->toBe('改過的標題')
        ->and($deal->events()->sole())
        ->type->toBe(DealEventType::StageChange)
        ->from_stage->toBe(DealStage::Proposal)
        ->to_stage->toBe(DealStage::Negotiation);
});

it('saving without a stage change logs no event', function () {
    $deal = Deal::factory()->create();

    Livewire::test(EditDeal::class, ['record' => $deal->getRouteKey()])
        ->fillForm(['next_action' => '約下週會議'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($deal->refresh()->next_action)->toBe('約下週會議')
        ->and($deal->events()->count())->toBe(0);
});

it('advances the stage from the table with a note', function () {
    $deal = Deal::factory()->create(['stage' => DealStage::Negotiation]);

    Livewire::test(ListDeals::class)
        ->callAction(TestAction::make('advanceStage')->table($deal), data: ['stage' => DealStage::Won->value, 'note' => '簽約完成'])
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect($deal->refresh())
        ->stage->toBe(DealStage::Won)
        ->probability->toBe(100)
        ->closed_at->not->toBeNull()
        ->and($deal->events()->sole())
        ->type->toBe(DealEventType::StageChange)
        ->content->toBe('簽約完成');
});

it('logs an interaction from the table and updates the next action', function () {
    $deal = Deal::factory()->create();

    Livewire::test(ListDeals::class)
        ->callAction(TestAction::make('logInteraction')->table($deal), data: [
            'type' => DealEventType::Meeting->value,
            'content' => '與窗口開會確認需求',
            'next_action' => '寄出報價',
            'next_action_on' => '2026-10-05',
        ])
        ->assertHasNoFormErrors();

    expect($deal->events()->sole())
        ->type->toBe(DealEventType::Meeting)
        ->content->toBe('與窗口開會確認需求')
        ->and($deal->refresh())
        ->next_action->toBe('寄出報價')
        ->next_action_on->toDateString()->toBe('2026-10-05');
});

it('logs an interaction from the events relation manager', function () {
    $deal = Deal::factory()->create();
    DealEvent::factory()->create(['deal_id' => $deal->id, 'content' => '先前的備註']);

    Livewire::test(EventsRelationManager::class, ['ownerRecord' => $deal, 'pageClass' => EditDeal::class])
        ->assertOk()
        ->assertSee('先前的備註')
        ->callAction(TestAction::make('create')->table(), data: [
            'type' => DealEventType::ProposalSent->value,
            'occurred_on' => '2026-09-25',
            'content' => '送出 v2 報價',
        ])
        ->assertHasNoFormErrors();

    expect($deal->events()->where('type', DealEventType::ProposalSent)->sole())
        ->content->toBe('送出 v2 報價')
        ->occurred_on->toDateString()->toBe('2026-09-25');
});

it('renders the board with open columns and closed counts', function () {
    Deal::factory()->create(['stage' => DealStage::Lead, 'title' => '潛在案子', 'amount_untaxed' => 1_000_000, 'probability' => 10]);
    Deal::factory()->create(['stage' => DealStage::Negotiation, 'title' => '議價案子', 'next_action' => null, 'next_action_on' => null]);
    Deal::factory()->count(2)->create(['stage' => DealStage::Won, 'title' => '已成交案子']);

    Livewire::test(DealBoard::class)
        ->assertOk()
        ->assertSee('潛在案子')
        ->assertSee('加權 NT$100,000')
        ->assertSee('議價案子')
        ->assertSee('沒有下一步')
        ->assertSee('2 件')
        ->assertDontSee('已成交案子');
});

it('moves a card to another stage with the board action', function () {
    $deal = Deal::factory()->create(['stage' => DealStage::Lead]);

    Livewire::test(DealBoard::class)
        ->callAction(TestAction::make('moveStage')->arguments(['deal' => $deal->id]), data: ['stage' => DealStage::Proposal->value])
        ->assertHasNoFormErrors();

    expect($deal->refresh()->stage)->toBe(DealStage::Proposal)
        ->and($deal->events()->sole()->to_stage)->toBe(DealStage::Proposal);
});

it('moves a card by drag and drop', function () {
    $deal = Deal::factory()->create(['stage' => DealStage::Proposal]);

    Livewire::test(DealBoard::class)
        ->call('moveDeal', $deal->id, DealStage::Lost->value)
        ->assertNotified();

    expect($deal->refresh()->stage)->toBe(DealStage::Lost)
        ->and($deal->events()->sole()->from_stage)->toBe(DealStage::Proposal);
});

it('serves the deal pages over http', function (string $uri) {
    Deal::factory()->create();

    $this->get($uri)->assertOk();
})->with([
    '/admin/deals',
    '/admin/deals/board',
    '/admin/deals/create',
]);
