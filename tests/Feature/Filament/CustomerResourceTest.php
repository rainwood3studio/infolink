<?php

use App\Enums\CustomerStatus;
use App\Enums\ProjectStatus;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\RelationManagers\ProjectsRelationManager;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('creates a customer', function () {
    Livewire::test(CreateCustomer::class)
        ->fillForm([
            'name' => '墊腳石圖書股份有限公司',
            'short_name' => '墊腳石',
            'status' => CustomerStatus::Active,
            'redmine_project_ids' => ['12', '34'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Customer::sole())
        ->short_name->toBe('墊腳石')
        ->redmine_project_ids->toBe([12, 34]);
});

it('rejects a duplicate short name', function () {
    Customer::factory()->create(['short_name' => '長照']);

    Livewire::test(CreateCustomer::class)
        ->fillForm(['name' => '另一家', 'short_name' => '長照', 'status' => CustomerStatus::Active])
        ->call('create')
        ->assertHasFormErrors(['short_name' => 'unique']);
});

it('edits a customer', function () {
    $customer = Customer::factory()->create();

    Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])
        ->fillForm(['status' => CustomerStatus::Inactive])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($customer->refresh()->status)->toBe(CustomerStatus::Inactive);
});

it('lists and creates projects under a customer', function () {
    $customer = Customer::factory()->create();
    $project = Project::factory()->for($customer)->create();
    $otherProject = Project::factory()->create();

    Livewire::test(ProjectsRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => EditCustomer::class])
        ->assertOk()
        ->assertCanSeeTableRecords([$project])
        ->assertCanNotSeeTableRecords([$otherProject])
        ->callAction(TestAction::make('create')->table(), data: [
            'name' => '5F B2C 網站',
            'contract_amount_untaxed' => 1200000,
            'status' => ProjectStatus::Closing,
            'target_close_date' => '2026-12-31',
        ])
        ->assertHasNoFormErrors();

    expect($customer->projects()->where('name', '5F B2C 網站')->sole())
        ->contract_amount_untaxed->toBe(1200000)
        ->status->toBe(ProjectStatus::Closing);
});
