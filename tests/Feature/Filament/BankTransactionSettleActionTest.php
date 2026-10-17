<?php

use App\Enums\ReceivableStatus;
use App\Enums\TransactionCategory;
use App\Filament\Resources\BankTransactions\Pages\ListBankTransactions;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Customer;
use App\Models\Receivable;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-10-17 10:00:00');
    $this->actingAs(User::factory()->create());

    $this->customer = Customer::factory()->create(['name' => '瓅瑩資產管理', 'short_name' => '長照']);
    $this->mid = Receivable::factory()->for($this->customer)->invoiced('2026-09-25')->create(['item' => '期中款', 'amount_untaxed' => 300_000, 'expected_on' => '2026-09-30']);
    $this->final = Receivable::factory()->for($this->customer)->create(['item' => '尾款', 'amount_untaxed' => 200_000, 'expected_on' => '2026-10-31']);
    $this->account = BankAccount::factory()->create();
});

it('settles a revenue deposit from the table, pre-selecting the matcher suggestion', function () {
    $deposit = BankTransaction::factory()->for($this->account)->create(['txn_date' => '2026-10-01', 'deposit' => 315_000, 'withdrawal' => 0, 'counterparty' => '瓅瑩資產管理', 'category' => TransactionCategory::Revenue]);

    Livewire::test(ListBankTransactions::class)
        ->mountAction(TestAction::make('settleDeposit')->table($deposit))
        ->assertSchemaStateSet(['receivable_ids' => [$this->mid->id], 'received_on' => '2026-10-01'])
        ->fillForm(['receivable_ids' => [$this->mid->id, $this->final->id]])
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertNotified('已沖銷 2 筆應收：期中款、尾款');

    expect($deposit->refresh())->receivable_id->toBe($this->mid->id)->notes->toBe("同筆入帳亦沖銷應收 #{$this->final->id}")
        ->and($this->mid->refresh()->status)->toBe(ReceivableStatus::Received)
        ->and($this->final->refresh())->status->toBe(ReceivableStatus::Received)->received_on->toDateString()->toBe('2026-10-01');
});

it('only offers the action on unsettled revenue deposits', function () {
    $settled = BankTransaction::factory()->for($this->account)->create(['deposit' => 315_000, 'category' => TransactionCategory::Revenue, 'receivable_id' => $this->mid->id]);
    $salary = BankTransaction::factory()->for($this->account)->create(['deposit' => 0, 'withdrawal' => 50_000, 'category' => TransactionCategory::Salary]);
    $unknown = BankTransaction::factory()->for($this->account)->create(['deposit' => 8_990, 'counterparty' => '磅礡廣媒行銷', 'category' => TransactionCategory::Revenue]);

    Livewire::test(ListBankTransactions::class)
        ->assertActionHidden(TestAction::make('settleDeposit')->table($settled))
        ->assertActionHidden(TestAction::make('settleDeposit')->table($salary))
        ->assertActionVisible(TestAction::make('settleDeposit')->table($unknown))
        ->mountAction(TestAction::make('settleDeposit')->table($unknown))
        ->assertSchemaStateSet(['receivable_ids' => [], 'received_on' => $unknown->txn_date->toDateString()]);
});
