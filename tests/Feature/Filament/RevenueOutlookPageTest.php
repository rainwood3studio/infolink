<?php

use App\Enums\Confidence;
use App\Enums\ProjectStatus;
use App\Filament\Pages\ClosingProjects;
use App\Filament\Pages\RevenueOutlook;
use App\Filament\Resources\Deals\DealResource;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\CostBaseline;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\PlannedCashFlow;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\RedmineIssue;
use App\Models\RedmineStatusSnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    config(['services.redmine.acceptor_name' => '文豪']);
    $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00'));
    $this->actingAs(User::factory()->create());
});

/**
 * Bank balance 1,000,000 on 2026-09-22, monthly cost 200,000 (150,000 of September's already paid), tax rate 0.
 * Booked: overdue 期中款 300,000, 尾款 600,000 (10/31), 維運費 100,000 for October–December, a low-confidence 二期
 * 400,000 (12/20) and a planned −80,000 營業稅 (11/15). So cash peaks at 1,750,000 at the end of October.
 */
function seedRevenueOutlookPage(): Receivable
{
    CostBaseline::factory()->create(['effective_from' => '2026-01-01', 'monthly_cost' => 200_000]);

    $account = BankAccount::factory()->create(['is_primary' => true]);
    BankTransaction::factory()->for($account)->create(['txn_date' => '2026-09-05', 'withdrawal' => 150_000, 'deposit' => 0, 'balance' => 990_000, 'sequence' => 1]);
    BankTransaction::factory()->for($account)->create(['txn_date' => '2026-09-22', 'withdrawal' => 0, 'deposit' => 10_000, 'balance' => 1_000_000, 'sequence' => 2]);

    $customer = Customer::factory()->create(['short_name' => '墊腳石']);
    $receivable = fn (array $attributes): Receivable => Receivable::factory()->for($customer)->create(['tax_rate' => 0, ...$attributes]);

    $receivable(['item' => '期中款', 'amount_untaxed' => 300_000, 'expected_on' => '2026-09-15']);
    $final = $receivable(['item' => '尾款', 'amount_untaxed' => 600_000, 'expected_on' => '2026-10-31']);
    $receivable(['item' => '二期', 'amount_untaxed' => 400_000, 'expected_on' => '2026-12-20', 'confidence' => Confidence::Low]);

    foreach (['2026-10-05', '2026-11-05', '2026-12-05'] as $date) {
        $receivable(['item' => '維運費', 'amount_untaxed' => 100_000, 'expected_on' => $date, 'is_recurring' => true]);
    }

    PlannedCashFlow::factory()->create(['flow_on' => '2026-11-15', 'amount' => -80_000, 'description' => '營業稅']);

    return $final;
}

it('shows the gap, the peak, when cash starts falling and when it reaches zero, with the monthly table', function () {
    seedRevenueOutlookPage();

    $this->get(RevenueOutlook::getUrl())->assertOk();

    Livewire::test(RevenueOutlook::class)
        ->assertSeeInOrder(['每月缺口', 'NT$100,000', '月成本 NT$200,000 − 經常性收入 NT$100,000'])
        ->assertSeeInOrder(['一年要補的新生意', 'NT$1,200,000'])
        ->assertSeeInOrder(['現金高點', 'NT$1,750,000', '2026 年 10 月底'])
        ->assertSeeInOrder(['現金開始往下掉', '2026 年 11 月'])
        ->assertSeeInOrder(['照這樣現金歸零', '2028 年 3 月', '往後推 7 個月', '只算已確定的：2027 年 8 月'])
        ->assertSeeInOrder(['每月收入與支出', '專案應收', '經常性收入', '假設續約的經常性收入', '月成本', '已排定支出', '月淨額'])
        ->assertSeeInOrder(['逐月明細', '2026 年 11 月', '−180,000', '1,570,000', '2027 年 1 月', '起為假設', '−100,000', '1,370,000', '1,270,000'])
        ->assertSee('低確定性（未計入）')
        ->assertSee('目前沒有掛在結案中專案上的未收款')
        ->assertSee('目前沒有進行中的業務機會');
});

it('recalculates when the final payments are delayed or low-confidence receivables are counted', function () {
    seedRevenueOutlookPage();

    Livewire::test(RevenueOutlook::class)
        ->assertSee('NT$1,750,000')
        ->set('delayMonths', 2)
        ->assertSee(['NT$1,470,000', '2026 年 12 月底', '專案應收全部往後 2 個月'])
        ->assertDontSee('NT$1,750,000')
        ->set('delayMonths', 0)
        ->set('includeLowConfidence', true)
        ->assertSee(['NT$1,870,000', '2026 年 12 月底', '低確定性應收'])
        ->assertDontSee('低確定性（未計入）');
});

it('reads the scenario from the query string and ignores a delay it does not offer', function () {
    seedRevenueOutlookPage();

    Livewire::withQueryParams(['delay' => '2', 'low' => 'true'])
        ->test(RevenueOutlook::class)
        ->assertSet('delayMonths', 2)
        ->assertSet('includeLowConfidence', true)
        ->assertSeeInOrder(['現金高點', 'NT$1,870,000']);

    Livewire::withQueryParams(['delay' => '4'])
        ->test(RevenueOutlook::class)
        ->assertSet('delayMonths', 0)
        ->assertSeeInOrder(['現金高點', 'NT$1,750,000']);
});

it('names what each open deal is missing and plots the ones it can count', function () {
    seedRevenueOutlookPage();
    $blank = Deal::factory()->create(['prospect_name' => '新客戶', 'title' => '官網改版', 'amount_untaxed' => null, 'expected_close_on' => null, 'next_action' => null, 'next_action_on' => null]);
    Deal::factory()->create(['prospect_name' => '長照', 'title' => 'HR 二期', 'amount_untaxed' => 1_000_000, 'probability' => 50, 'expected_close_on' => '2026-11-20']);

    Livewire::test(RevenueOutlook::class)
        ->assertSeeInOrder(['加權業務機會（未稅）', '月成本'])
        ->assertSeeInOrder(['2 個進行中的機會，1 個算得進去', 'NT$500,000', '另外 1 個算不進去'])
        ->assertSeeInOrder(['HR 二期', '長照', 'NT$1,000,000', '50%', 'NT$500,000', '11/20', '已計入'])
        ->assertSeeInOrder(['官網改版', '新客戶', '缺金額', '缺預計成交日', '缺下一步日期'])
        ->assertSeeHtml('href="'.DealResource::getUrl('edit', ['record' => $blank->id]).'"')
        ->assertSeeInOrder(['月底餘額（含業務機會）', '2026 年 11 月', '2,070,000']);
});

it('warns about final payments that hang on a closing project and links to its battle page', function () {
    $final = seedRevenueOutlookPage();
    $project = Project::factory()->create(['name' => '商城 APP', 'status' => ProjectStatus::Closing, 'redmine_project_id' => 21, 'target_close_date' => '2026-10-31']);
    $final->update(['project_id' => $project->id]);
    RedmineIssue::factory()->count(3)->create(['project_id' => 21, 'project_identifier' => 'mall-app']);
    RedmineStatusSnapshot::factory()->create(['snapshot_date' => '2026-09-26', 'project_identifier' => 'mall-app', 'count' => 3]);

    Livewire::test(RevenueOutlook::class)
        ->assertSeeInOrder(['這些尾款掛在還沒結案的專案上', '共 1 筆', 'NT$600,000', '其中 1 筆'])
        ->assertSeeInOrder(['墊腳石 尾款', 'NT$600,000', '10/31', '商城 APP', '離目標日 28 天', '未結 3 張', '近 7 天未結議題沒有減少，照這樣結不完'])
        ->assertSeeHtml('href="'.e(ClosingProjects::getUrl(['project' => $project->id])).'"');
});
