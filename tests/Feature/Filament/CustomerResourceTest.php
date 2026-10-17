<?php

use App\Enums\CustomerStatus;
use App\Enums\MaintenanceCycle;
use App\Enums\ProjectStatus;
use App\Enums\ReceivableStatus;
use App\Enums\TransactionCategory;
use App\Filament\Pages\CustomerCollections;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\ProjectsRelationManager;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\BankTransaction;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\RedmineIssue;
use App\Models\User;
use Carbon\CarbonImmutable;
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

it('saves the bank aliases and the maintenance contract', function () {
    Livewire::test(CreateCustomer::class)
        ->fillForm([
            'name' => '我識出版社有限公司',
            'short_name' => '我識',
            'status' => CustomerStatus::Active,
            'counterparties' => [' 我視整合傳播 '],
            'maintenance_fee_untaxed' => 20_000,
            'maintenance_cycle' => MaintenanceCycle::Monthly,
            'maintenance_since' => '2026-01-01',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $customer = Customer::sole();

    expect($customer->counterparties)->toBe(['我視整合傳播'])
        ->and($customer->bankAliases())->toBe(['我視整合傳播', '我識出版社有限公司', '我識'])
        ->and($customer->hasMaintenanceContract())->toBeTrue()
        ->and($customer->maintenance_cycle)->toBe(MaintenanceCycle::Monthly)
        ->and($customer->maintenance_since->toDateString())->toBe('2026-01-01');
});

it('needs the fee, the cycle and the start date together for a maintenance contract', function () {
    Livewire::test(CreateCustomer::class)
        ->fillForm(['name' => '南陽實業', 'short_name' => '南陽', 'status' => CustomerStatus::Active, 'maintenance_fee_untaxed' => 40_000])
        ->call('create')
        ->assertHasFormErrors(['maintenance_cycle', 'maintenance_since']);
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

describe('overview', function () {
    beforeEach(function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-10 10:00'));
    });

    it('renders the customer overview with its flags, projects, receivables, deposits, deals and Redmine issues', function () {
        $stone = Customer::factory()->maintenance(100_000, MaintenanceCycle::Monthly, '2026-01-01', '2026-11-15')->create(['name' => '墊腳石圖書股份有限公司', 'short_name' => '墊腳石', 'counterparties' => ['墊腳石國際']]);
        $apos = Project::factory()->for($stone)->create(['name' => 'APOS 2.0', 'contract_amount_untaxed' => null, 'redmine_project_id' => 15]);
        Receivable::factory()->for($stone)->for($apos)->create(['item' => '尾款', 'amount_untaxed' => 200_000, 'expected_on' => '2026-10-01', 'status' => ReceivableStatus::Planned]);
        Receivable::factory()->for($stone)->create(['item' => '維運費', 'amount_untaxed' => 100_000, 'expected_on' => '2026-09-01', 'status' => ReceivableStatus::Received, 'received_on' => '2026-09-02', 'is_recurring' => true]);
        BankTransaction::factory()->create(['txn_date' => '2026-07-31', 'deposit' => 105_000, 'withdrawal' => 0, 'counterparty' => '墊腳石國際', 'category' => TransactionCategory::Revenue]);
        Deal::factory()->for($stone)->create(['title' => '門市 POS 第二期', 'amount_untaxed' => 800_000, 'probability' => 60, 'next_action' => '寄報價']);
        RedmineIssue::factory()->count(2)->create(['project_id' => 15, 'project_identifier' => 'tcsb-apos', 'project_name' => '墊腳石 | APOS']);

        $this->get(CustomerResource::getUrl('view', ['record' => $stone->id]))->assertOk();

        Livewire::test(ViewCustomer::class, ['record' => $stone->getRouteKey()])
            ->assertOk()
            ->assertSee('墊腳石 總覽')
            ->assertSee(['維護合約 36 天後到期', '逾期應收 NT$210,000（含稅）', '合約金額未填：APOS 2.0'])
            ->assertSeeInOrder(['12 個月入帳', 'NT$105,000', '未收應收（含稅）', 'NT$210,000', '逾期（含稅）', 'NT$210,000', '維護合約', 'NT$100,000／月', '36 天後到期（2026/11/15）'])
            ->assertSeeInOrder(['專案', 'APOS 2.0', '進行中', '未填', 'NT$200,000', '2 筆'])
            ->assertSeeInOrder(['未收應收', '尾款', 'APOS 2.0', 'NT$200,000', 'NT$210,000', '2026/10/01', '逾期 9 天', '預計'])
            ->assertSeeInOrder(['近 12 個月入帳', '2025/11 – 2026/10 共 NT$105,000，1 筆', '2026/07 入帳 NT$105,000'])
            ->assertSeeInOrder(['經常性應收（維護費）', '2026/09/01', '維運費', 'NT$105,000', '已收款'])
            ->assertSeeInOrder(['業務機會', '門市 POS 第二期', '提案', 'NT$800,000', '60%', '寄報價'])
            ->assertSeeInOrder(['Redmine 未結', '2', '筆未結議題', '墊腳石 | APOS', '15', '2 筆'])
            ->assertSeeHtml('href="'.ProjectResource::getUrl('edit', ['record' => $apos->id]).'"')
            ->assertActionExists('edit')
            ->assertActionExists('collections')
            ->assertActionHasUrl('collections', CustomerCollections::getUrl());
    });

    it('renders an empty overview for a customer with nothing attached', function () {
        $customer = Customer::factory()->create(['short_name' => '湛盧']);

        Livewire::test(ViewCustomer::class, ['record' => $customer->getRouteKey()])
            ->assertOk()
            ->assertSee(['湛盧 總覽', '沒有要處理的事', '沒有維護合約', '還沒有專案', '沒有進行中的業務機會', '沒有連結 Redmine 專案']);
    });

    it('shows the maintenance end date in the list and opens the overview from a row', function () {
        $soon = Customer::factory()->maintenance(100_000, MaintenanceCycle::Monthly, '2026-01-01', '2026-11-15')->create(['short_name' => '墊腳石']);
        $later = Customer::factory()->maintenance(240_000, MaintenanceCycle::Yearly, '2026-01-01', '2027-06-30')->create(['short_name' => '長照']);
        $none = Customer::factory()->create(['short_name' => '湛盧']);

        Livewire::test(ListCustomers::class)
            ->assertOk()
            ->assertCanRenderTableColumn('maintenance_until')
            ->assertTableColumnFormattedStateSet('maintenance_until', '2026/11/15', $soon)
            ->assertTableColumnFormattedStateSet('maintenance_until', '2027/06/30', $later)
            ->assertSee(['36 天後', '263 天後'])
            ->assertActionExists(TestAction::make('view')->table($soon))
            ->assertActionHasUrl(TestAction::make('view')->table($none), CustomerResource::getUrl('view', ['record' => $none->id]))
            ->assertSee('總覽');
    });
});
