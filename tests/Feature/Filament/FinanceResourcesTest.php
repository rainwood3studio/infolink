<?php

use App\Enums\TransactionCategory;
use App\Filament\Resources\BankTransactions\Pages\EditBankTransaction;
use App\Filament\Resources\BankTransactions\Pages\ListBankTransactions;
use App\Filament\Resources\CostBaselines\Pages\CreateCostBaseline;
use App\Models\BankTransaction;
use App\Models\CostBaseline;
use App\Models\Receivable;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('filters bank transactions by month and category', function () {
    $september = BankTransaction::factory()->create(['txn_date' => '2026-09-05', 'category' => TransactionCategory::Salary]);
    $august = BankTransaction::factory()->create(['txn_date' => '2026-08-05', 'category' => TransactionCategory::Salary]);
    $rent = BankTransaction::factory()->create(['txn_date' => '2026-09-10', 'category' => TransactionCategory::Rent]);

    Livewire::test(ListBankTransactions::class)
        ->filterTable('month', '2026-09')
        ->assertCanSeeTableRecords([$september, $rent])
        ->assertCanNotSeeTableRecords([$august])
        ->filterTable('category', [TransactionCategory::Salary->value])
        ->assertCanSeeTableRecords([$september])
        ->assertCanNotSeeTableRecords([$august, $rent]);
});

it('edits only the classification of a bank transaction, never the amounts', function () {
    $transaction = BankTransaction::factory()->create(['deposit' => 472500, 'withdrawal' => 0, 'balance' => 1_000_000]);
    $receivable = Receivable::factory()->create();

    Livewire::test(EditBankTransaction::class, ['record' => $transaction->getRouteKey()])
        ->assertSchemaComponentExists('deposit')
        ->fillForm([
            'deposit' => 1,
            'balance' => 1,
            'counterparty' => '長照中心',
            'category' => TransactionCategory::Revenue,
            'is_one_off' => true,
            'receivable_id' => $receivable->id,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($transaction->refresh())
        ->deposit->toBe(472500)
        ->balance->toBe(1_000_000)
        ->counterparty->toBe('長照中心')
        ->category->toBe(TransactionCategory::Revenue)
        ->is_one_off->toBeTrue()
        ->receivable_id->toBe($receivable->id);
});

it('stores a cost baseline breakdown as integers', function () {
    Livewire::test(CreateCostBaseline::class)
        ->fillForm([
            'effective_from' => '2026-10-01',
            'monthly_cost' => 230000,
            'breakdown' => ['薪資' => '180,000', '租金' => '25000'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(CostBaseline::sole()->breakdown)->toBe(['薪資' => 180000, '租金' => 25000]);
});
