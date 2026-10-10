<?php

use App\Enums\MaintenanceCycle;
use App\Enums\TransactionCategory;
use App\Filament\Pages\CustomerCollections;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Customer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 10:00'));
    $this->actingAs(User::factory()->create());
});

function seedCustomerCollectionsPage(): Customer
{
    $account = BankAccount::factory()->create();
    $stone = Customer::factory()->maintenance(100_000, MaintenanceCycle::Monthly, '2026-07-01')->create(['name' => '墊腳石國際', 'short_name' => '墊腳石']);
    Customer::factory()->create(['name' => '盟立自動化', 'short_name' => '盟立']);
    Customer::factory()->create(['name' => '湛盧實業', 'short_name' => '湛盧']);

    foreach ([['2026-07-31', 105_000, '墊腳石國際'], ['2026-10-01', 105_000, '墊腳石國際'], ['2026-03-16', 50_000, '盟立自動化'], ['2026-08-25', 8_990, '磅礡廣媒行銷']] as [$date, $amount, $counterparty]) {
        BankTransaction::factory()->for($account)->create(['txn_date' => $date, 'deposit' => $amount, 'withdrawal' => 0, 'counterparty' => $counterparty, 'category' => TransactionCategory::Revenue]);
    }

    return $stone;
}

it('shows every customer with its deposits and checks the maintenance periods', function () {
    $stone = seedCustomerCollectionsPage();

    $this->get(CustomerCollections::getUrl())->assertOk();

    Livewire::test(CustomerCollections::class)
        ->assertSee('2025/05 – 2026/10，銀行明細到 2026/10/01')
        ->assertSeeInOrder(['有維護合約的客戶', '1 家', '1 期未入帳、0 期金額不足'])
        ->assertSeeInOrder(['這段期間的客戶入帳', 'NT$260,000', '2 家客戶有入帳'])
        ->assertSeeInOrder(['對不到客戶的入帳', 'NT$8,990', '1 個戶名對不到客戶'])
        ->assertSeeInOrder(['有維護合約的客戶', '墊腳石', '每月維護 NT$100,000／月', '含稅 NT$105,000，2026/07/01 起', '期間入帳', 'NT$210,000', '2 筆', '最近入帳', '2026/10/01'])
        ->assertSeeInOrder(['2026/07', '10.5 萬', '2026/08', '未入帳', '2026/09', '已入帳 2 期', '未入帳 1 期（2026/08）', '下一期 2026/11/01 起'])
        ->assertSeeInOrder(['其他客戶', '盟立', '盟立自動化', 'NT$50,000'])
        ->assertSee('這段期間沒有入帳：湛盧')
        ->assertSeeInOrder(['對不到客戶的入帳', '磅礡廣媒行銷', 'NT$8,990', '2026/08/25'])
        ->assertSeeHtml('href="'.CustomerResource::getUrl('edit', ['record' => $stone->id]).'"');
});

it('switches the window and reads it from the query string', function () {
    seedCustomerCollectionsPage();

    Livewire::test(CustomerCollections::class)
        ->assertSet('window', '18')
        ->call('setWindow', 'all')
        ->assertSet('window', 'all')
        ->assertSee('2026/03 – 2026/10')
        ->call('setWindow', 'bogus')
        ->assertSet('window', '18');

    Livewire::withQueryParams(['window' => '24'])
        ->test(CustomerCollections::class)
        ->assertSet('window', '24')
        ->assertSee('2024/11 – 2026/10');
});

it('shows an empty state without bank data', function () {
    Customer::factory()->create(['short_name' => '墊腳石']);

    Livewire::test(CustomerCollections::class)
        ->assertSee('還沒有銀行明細')
        ->assertDontSee('其他客戶');
});
