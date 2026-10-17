<?php

use App\Enums\MaintenanceCycle;
use App\Enums\ProjectStatus;
use App\Enums\ReceivableStatus;
use App\Enums\TransactionCategory;
use App\Filament\Pages\BillingProgress;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-17 10:00'));
    $this->actingAs(User::factory()->create(['name' => 'Kenneth']));
});

/**
 * @return array{project: Project, mid: Receivable, final: Receivable, fee: Receivable, deposit: BankTransaction, combined: BankTransaction, stranger: BankTransaction}
 */
function seedBillingPage(): array
{
    $care = Customer::factory()->create(['name' => '瓅瑩資產管理', 'short_name' => '長照']);
    $project = Project::factory()->for($care)->create(['name' => '長照平台', 'contract_amount_untaxed' => 1_000_000, 'status' => ProjectStatus::Closing]);
    Receivable::factory()->for($care)->for($project)->create(['item' => '頭期款', 'amount_untaxed' => 300_000, 'expected_on' => '2026-03-31', 'status' => ReceivableStatus::Received, 'invoiced_on' => '2026-03-20', 'invoice_number' => 'AB-00000001', 'received_on' => '2026-03-31']);
    $mid = Receivable::factory()->for($care)->for($project)->invoiced('2026-09-25', 'AB-00000002')->create(['item' => '期中款', 'amount_untaxed' => 300_000, 'expected_on' => '2026-09-30']);
    $final = Receivable::factory()->for($care)->for($project)->create(['item' => '尾款', 'amount_untaxed' => 200_000, 'expected_on' => '2026-10-22']);

    $stone = Customer::factory()->maintenance(100_000, MaintenanceCycle::Monthly, '2026-08-01')->create(['name' => '墊腳石國際', 'short_name' => '墊腳石']);
    $fee = Receivable::factory()->for($stone)->create(['item' => '維運費', 'amount_untaxed' => 100_000, 'expected_on' => '2026-09-30', 'is_recurring' => true]);

    $account = BankAccount::factory()->create();
    $line = fn (string $date, int $amount, string $counterparty): BankTransaction => BankTransaction::factory()->for($account)->create(['txn_date' => $date, 'deposit' => $amount, 'withdrawal' => 0, 'counterparty' => $counterparty, 'category' => TransactionCategory::Revenue]);
    $deposit = $line('2026-10-01', 315_000, '瓅瑩資產管理');
    $combined = $line('2026-10-02', 525_000, '瓅瑩資產管理');
    $stranger = $line('2026-10-06', 8_990, '磅礡廣媒行銷');

    return ['project' => $project, 'mid' => $mid, 'final' => $final, 'fee' => $fee, 'deposit' => $deposit, 'combined' => $combined, 'stranger' => $stranger];
}

it('shows the tiles, each project bar, the deposits to settle and the maintenance periods', function () {
    ['project' => $project] = seedBillingPage();

    $this->get(BillingProgress::getUrl())->assertOk();

    Livewire::test(BillingProgress::class)
        ->assertSeeInOrder(['合約總額（未稅）', 'NT$1,000,000', '1 個專案都有合約金額'])
        ->assertSeeInOrder(['已收', 'NT$300,000', '佔合約總額 30%'])
        ->assertSeeInOrder(['已開票未收', 'NT$300,000', '逾期 1 筆 NT$300,000'])
        ->assertSeeInOrder(['尚未排入應收', 'NT$200,000', '另有 1 筆 7 天內到期但還沒開發票：NT$200,000'])
        ->assertSeeInOrder(['各專案請款進度', '長照平台', '長照', '結案中', '逾期 1 筆 NT$300,000', '到期未開票 1 筆 NT$200,000'])
        ->assertSeeInOrder(['3 筆應收', '頭期款', 'AB-00000001', '期中款', '逾期 17 天', 'AB-00000002', '標記已收', '尾款', '到期未開票', '標記已開票'])
        ->assertSeeInOrder(['待沖銷入帳', '2026/10/01', '瓅瑩資產管理', '長照', 'NT$315,000', '長照平台｜期中款', '金額相符（含稅）', '沖銷', '選擇應收'])
        ->assertSeeInOrder(['2026/10/02', 'NT$525,000', '長照平台｜期中款 + 長照平台｜尾款', '多筆合計相符'])
        ->assertSeeInOrder(['對不到客戶的入帳', '磅礡廣媒行銷', 'NT$8,990'])
        ->assertSeeInOrder(['經常性收費', '墊腳石', '每月維護 NT$100,000／月', '沒有應收列 4 期', '未開票 1 期', '2026/08', '沒有應收列', '2026/09', '未開票', '2026/10', '2026/11', '2026/12'])
        ->assertSeeHtml('href="'.ProjectResource::getUrl('edit', ['record' => $project->id]).'"');
});

it('marks a receivable invoiced with its invoice number and received from the page', function () {
    ['mid' => $mid, 'final' => $final] = seedBillingPage();

    Livewire::test(BillingProgress::class)
        ->callAction('markInvoiced', data: ['invoiced_on' => '2026-10-17', 'invoice_number' => 'CD-11112222'], arguments: ['receivable' => $final->id])
        ->assertHasNoFormErrors()
        ->assertNotified('已標記開票')
        ->callAction('markReceived', data: ['received_on' => '2026-10-01'], arguments: ['receivable' => $mid->id])
        ->assertHasNoFormErrors()
        ->assertNotified('已標記收款')
        ->assertSee('CD-11112222');

    expect($final->refresh())->status->toBe(ReceivableStatus::Invoiced)->invoice_number->toBe('CD-11112222')->invoiced_on->toDateString()->toBe('2026-10-17')
        ->and($mid->refresh())->status->toBe(ReceivableStatus::Received)->received_on->toDateString()->toBe('2026-10-01');
});

it('settles a deposit with the best suggestion after confirmation', function () {
    ['mid' => $mid, 'final' => $final, 'combined' => $combined] = seedBillingPage();

    Livewire::test(BillingProgress::class)
        ->callAction('settleBest', arguments: ['transaction' => $combined->id])
        ->assertNotified('已沖銷 2 筆應收：期中款、尾款')
        ->assertDontSee('NT$525,000');

    expect($combined->refresh())->receivable_id->toBe($mid->id)->notes->toBe("同筆入帳亦沖銷應收 #{$final->id}")
        ->and($mid->refresh()->status)->toBe(ReceivableStatus::Received)
        ->and($final->refresh())->status->toBe(ReceivableStatus::Received)->received_on->toDateString()->toBe('2026-10-02');
});

it('lets the user pick the receivables for a deposit, pre-selecting the suggestion', function () {
    ['mid' => $mid, 'fee' => $fee, 'deposit' => $deposit, 'stranger' => $stranger] = seedBillingPage();

    Livewire::test(BillingProgress::class)
        ->mountAction('settleDeposit', arguments: ['transaction' => $deposit->id])
        ->assertSchemaStateSet(['receivable_ids' => [$mid->id], 'received_on' => '2026-10-01'])
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertNotified('已沖銷 1 筆應收：期中款');

    Livewire::test(BillingProgress::class)
        ->callAction('settleDeposit', data: ['receivable_ids' => [$fee->id], 'received_on' => '2026-10-06'], arguments: ['transaction' => $stranger->id])
        ->assertHasNoFormErrors()
        ->assertNotified('已沖銷 1 筆應收：維運費');

    expect($deposit->refresh()->receivable_id)->toBe($mid->id)
        ->and($stranger->refresh()->receivable_id)->toBe($fee->id)
        ->and($fee->refresh())->status->toBe(ReceivableStatus::Received)->received_on->toDateString()->toBe('2026-10-06');
});

it('hides the one-click settle when there is no exact suggestion and shows empty states', function () {
    Livewire::test(BillingProgress::class)
        ->assertSee('還沒有專案')
        ->assertSee('每一筆收入都已經對到應收')
        ->assertSee('還沒有客戶設定維護合約');

    $care = Customer::factory()->create(['name' => '瓅瑩資產管理', 'short_name' => '長照']);
    Receivable::factory()->for($care)->create(['item' => '尾款', 'amount_untaxed' => 200_000, 'expected_on' => '2026-10-22']);
    $deposit = BankTransaction::factory()->create(['txn_date' => '2026-10-01', 'deposit' => 100_000, 'withdrawal' => 0, 'counterparty' => '瓅瑩資產管理', 'category' => TransactionCategory::Revenue]);

    Livewire::test(BillingProgress::class)
        ->assertSeeInOrder(['沒有掛專案的應收', '長照', '尾款'])
        ->assertSee('沒有金額相符的應收（1 筆未收）')
        ->assertActionHidden('settleBest', arguments: ['transaction' => $deposit->id])
        ->assertActionVisible('settleDeposit', arguments: ['transaction' => $deposit->id]);
});
