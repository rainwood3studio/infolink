<?php

use App\Enums\Confidence;
use App\Enums\ReceivableStatus;
use App\Enums\Source;
use App\Filament\Resources\Receivables\Pages\CreateReceivable;
use App\Filament\Resources\Receivables\Pages\EditReceivable;
use App\Filament\Resources\Receivables\Pages\ListReceivables;
use App\Models\Customer;
use App\Models\Receivable;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['name' => 'Kenneth']));
});

it('lists receivables', function () {
    $receivables = Receivable::factory()->count(3)->create();

    Livewire::test(ListReceivables::class)
        ->assertOk()
        ->assertCanSeeTableRecords($receivables);
});

it('filters to overdue receivables', function () {
    $overdue = Receivable::factory()->create(['expected_on' => today()->subDays(3)]);
    $upcoming = Receivable::factory()->create(['expected_on' => today()->addDays(3)]);
    $received = Receivable::factory()->create(['expected_on' => today()->subDays(3), 'status' => ReceivableStatus::Received]);

    Livewire::test(ListReceivables::class)
        ->filterTable('overdue')
        ->assertCanSeeTableRecords([$overdue])
        ->assertCanNotSeeTableRecords([$upcoming, $received]);
});

it('creates a receivable as a manual entry', function () {
    $customer = Customer::factory()->create();

    Livewire::test(CreateReceivable::class)
        ->fillForm([
            'customer_id' => $customer->id,
            'item' => '期中款',
            'amount_untaxed' => 450000,
            'tax_rate' => 0.05,
            'expected_on' => '2026-10-15',
            'confidence' => Confidence::Low,
            'status' => ReceivableStatus::Planned,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $receivable = Receivable::sole();

    expect($receivable->item)->toBe('期中款')
        ->and($receivable->amount_taxed)->toBe(472500)
        ->and($receivable->confidence)->toBe(Confidence::Low)
        ->and($receivable->source)->toBe(Source::Manual)
        ->and($receivable->actor)->toBe('Kenneth');
});

it('edits a receivable', function () {
    $receivable = Receivable::factory()->create(['amount_untaxed' => 100000]);

    Livewire::test(EditReceivable::class, ['record' => $receivable->getRouteKey()])
        ->assertSchemaStateSet(['item' => $receivable->item])
        ->fillForm(['amount_untaxed' => 200000, 'item' => '尾款'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($receivable->refresh())
        ->item->toBe('尾款')
        ->amount_taxed->toBe(210000);
});

it('marks a receivable as received through the finance service', function () {
    $receivable = Receivable::factory()->create(['status' => ReceivableStatus::Invoiced]);

    Livewire::test(ListReceivables::class)
        ->callAction(TestAction::make('markReceived')->table($receivable), data: ['received_on' => '2026-09-20'])
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect($receivable->refresh())
        ->status->toBe(ReceivableStatus::Received)
        ->received_on->toDateString()->toBe('2026-09-20');
});

it('marks a receivable as invoiced through the finance service', function () {
    $receivable = Receivable::factory()->create(['status' => ReceivableStatus::Planned]);

    Livewire::test(ListReceivables::class)
        ->callAction(TestAction::make('markInvoiced')->table($receivable), data: ['invoiced_on' => '2026-09-21'])
        ->assertHasNoFormErrors();

    expect($receivable->refresh())
        ->status->toBe(ReceivableStatus::Invoiced)
        ->invoiced_on->toDateString()->toBe('2026-09-21');
});

it('hides the received action for settled receivables', function () {
    $receivable = Receivable::factory()->create(['status' => ReceivableStatus::Received]);

    Livewire::test(ListReceivables::class)
        ->assertActionHidden(TestAction::make('markReceived')->table($receivable));
});
