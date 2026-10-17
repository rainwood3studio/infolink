<?php

use App\Domain\Sales\CustomerOverview;
use App\Enums\DealStage;
use App\Enums\MaintenanceCycle;
use App\Enums\ProjectStatus;
use App\Enums\ReceivableStatus;
use App\Enums\TransactionCategory;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\RedmineIssue;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 10:00'));
    $this->overview = fn (Customer $customer): array => app(CustomerOverview::class)->calculate($customer);
});

/**
 * 墊腳石: a monthly maintenance contract ending in 36 days, two projects (one without a contract amount), one overdue
 * final payment, a recurring fee for this month still unpaid, deposits matched by alias and by linked receivable.
 */
function seedCustomerOverview(): Customer
{
    $account = BankAccount::factory()->create();
    $stone = Customer::factory()->maintenance(100_000, MaintenanceCycle::Monthly, '2026-01-01', '2026-11-15')
        ->create(['name' => '墊腳石圖書股份有限公司', 'short_name' => '墊腳石', 'counterparties' => ['墊腳石國際'], 'redmine_project_ids' => [15, 17]]);
    $other = Customer::factory()->create(['name' => '盟立自動化', 'short_name' => '盟立']);

    $apos = Project::factory()->for($stone)->create(['name' => 'APOS 2.0', 'contract_amount_untaxed' => null, 'status' => ProjectStatus::Active, 'redmine_project_id' => 15]);
    $b2c = Project::factory()->for($stone)->create(['name' => '5F B2C 網站', 'contract_amount_untaxed' => 1_200_000, 'status' => ProjectStatus::Closed, 'redmine_project_id' => 16, 'target_close_date' => '2026-06-30']);

    Receivable::factory()->for($stone)->for($apos)->create(['item' => '期中款', 'amount_untaxed' => 400_000, 'expected_on' => '2026-03-01', 'status' => ReceivableStatus::Received, 'received_on' => '2026-03-10']);
    Receivable::factory()->for($stone)->for($apos)->create(['item' => '尾款', 'amount_untaxed' => 200_000, 'expected_on' => '2026-10-01', 'status' => ReceivableStatus::Planned]);
    Receivable::factory()->for($stone)->for($b2c)->create(['item' => '尾款', 'amount_untaxed' => 300_000, 'expected_on' => '2026-11-01', 'status' => ReceivableStatus::Invoiced, 'invoiced_on' => '2026-10-05']);
    Receivable::factory()->for($stone)->create(['item' => '作廢', 'amount_untaxed' => 50_000, 'expected_on' => '2026-10-01', 'status' => ReceivableStatus::Cancelled]);
    $september = Receivable::factory()->for($stone)->create(['item' => '維運費', 'amount_untaxed' => 100_000, 'expected_on' => '2026-09-01', 'status' => ReceivableStatus::Received, 'received_on' => '2026-10-01', 'is_recurring' => true]);
    Receivable::factory()->for($stone)->create(['item' => '維運費', 'amount_untaxed' => 100_000, 'expected_on' => '2026-10-01', 'status' => ReceivableStatus::Planned, 'is_recurring' => true]);
    Receivable::factory()->for($other)->create(['amount_untaxed' => 999_000, 'expected_on' => '2026-01-01']);

    $line = fn (string $date, int $amount, ?string $counterparty, array $extra = []) => BankTransaction::factory()->for($account)->create([
        'txn_date' => $date, 'deposit' => $amount, 'withdrawal' => 0, 'counterparty' => $counterparty, 'category' => TransactionCategory::Revenue, ...$extra,
    ]);
    $line('2026-07-31', 105_000, '墊腳石國際');
    $line('2026-10-01', 105_000, '墊腳石圖書股份有限公司代付', ['receivable_id' => $september->id]);
    $line('2025-09-15', 50_000, '墊腳石國際');
    $line('2026-08-25', 8_990, '磅礡廣媒行銷');
    $line('2026-03-16', 50_000, '盟立自動化');
    $line('2026-09-01', 1_000, '墊腳石國際', ['category' => TransactionCategory::Other]);

    Deal::factory()->for($stone)->create(['title' => '門市 POS 第二期', 'stage' => DealStage::Proposal, 'amount_untaxed' => 800_000, 'probability' => 60, 'next_action' => '寄報價', 'next_action_on' => '2026-10-20']);
    Deal::factory()->for($stone)->create(['title' => '已成交', 'stage' => DealStage::Won]);
    Deal::factory()->for($other)->create(['title' => '別人的']);

    RedmineIssue::factory()->count(2)->create(['project_id' => 15, 'project_identifier' => 'tcsb-apos', 'project_name' => '墊腳石 | APOS']);
    RedmineIssue::factory()->create(['project_id' => 15, 'project_identifier' => 'tcsb-apos', 'project_name' => '墊腳石 | APOS', 'is_closed' => true]);
    RedmineIssue::factory()->create(['project_id' => 16, 'project_identifier' => 'tcsb-5f-b2c', 'project_name' => '墊腳石 | 5F | B2C']);
    RedmineIssue::factory()->create(['project_id' => 17, 'project_identifier' => 'tcsb-erp', 'project_name' => '墊腳石 | ERP']);
    RedmineIssue::factory()->create(['project_id' => 99]);

    return $stone;
}

test('describes the maintenance contract and flags one that ends within 45 days', function () {
    $overview = ($this->overview)(seedCustomerOverview());

    expect($overview)
        ->short_name->toBe('墊腳石')
        ->status->toBe('active')
        ->counterparties->toBe(['墊腳石國際'])
        ->bank_aliases->toBe(['墊腳石國際', '墊腳石圖書股份有限公司', '墊腳石'])
        ->redmine_project_ids->toBe([15, 17])
        ->as_of->toBe('2026-10-10')
        ->and($overview['maintenance'])->toBe([
            'fee_untaxed' => 100_000,
            'fee_taxed' => 105_000,
            'cycle' => 'monthly',
            'cycle_label' => '每月',
            'per_label' => '月',
            'since' => '2026-01-01',
            'until' => '2026-11-15',
            'days_until_end' => 36,
            'is_ended' => false,
            'is_expiring_soon' => true,
            'annualised_fee_untaxed' => 1_200_000,
        ])
        ->and($overview['flags'])->toBe(['維護合約 36 天後到期', '逾期應收 NT$315,000（含稅）', '合約金額未填：APOS 2.0']);
});

test('rolls receivables up per project, active projects first, with the open Redmine issues of the linked project', function () {
    $overview = ($this->overview)(seedCustomerOverview());

    expect(array_column($overview['projects'], 'name'))->toBe(['APOS 2.0', '5F B2C 網站'])
        ->and($overview['projects'][0])->toMatchArray([
            'status' => 'active',
            'status_label' => '進行中',
            'contract_amount_untaxed' => null,
            'target_close_date' => null,
            'redmine_project_id' => 15,
            'open_issues' => 2,
            'receivables' => ['scheduled_untaxed' => 600_000, 'received_untaxed' => 400_000, 'outstanding_untaxed' => 200_000, 'overdue_untaxed' => 200_000, 'outstanding_taxed' => 210_000],
        ])
        ->and($overview['projects'][1])->toMatchArray([
            'status' => 'closed',
            'contract_amount_untaxed' => 1_200_000,
            'target_close_date' => '2026-06-30',
            'open_issues' => 1,
            'receivables' => ['scheduled_untaxed' => 300_000, 'received_untaxed' => 0, 'outstanding_untaxed' => 300_000, 'overdue_untaxed' => 0, 'outstanding_taxed' => 315_000],
        ]);
});

test('lists outstanding project receivables with overdue days and totals that include the recurring fee', function () {
    $overview = ($this->overview)(seedCustomerOverview());
    $receivables = $overview['receivables'];

    expect($receivables)->toMatchArray([
        'outstanding_count' => 3,
        'outstanding_untaxed' => 600_000,
        'outstanding_taxed' => 630_000,
        'overdue_count' => 2,
        'overdue_untaxed' => 300_000,
        'overdue_taxed' => 315_000,
    ])
        ->and($receivables['items'])->toHaveCount(2)
        ->and($receivables['items'][0])->toMatchArray(['item' => '尾款', 'project' => 'APOS 2.0', 'amount_untaxed' => 200_000, 'amount_taxed' => 210_000, 'expected_on' => '2026-10-01', 'status' => 'planned', 'status_label' => '預計', 'is_overdue' => true, 'days' => -9])
        ->and($receivables['items'][1])->toMatchArray(['item' => '尾款', 'project' => '5F B2C 網站', 'expected_on' => '2026-11-01', 'status' => 'invoiced', 'invoiced_on' => '2026-10-05', 'is_overdue' => false, 'days' => 22])
        ->and($overview['recurring'])->toHaveCount(2)
        ->and($overview['recurring'][0])->toMatchArray(['expected_on' => '2026-10-01', 'status' => 'planned', 'received_on' => null, 'is_overdue' => true, 'amount_taxed' => 105_000])
        ->and($overview['recurring'][1])->toMatchArray(['expected_on' => '2026-09-01', 'status' => 'received', 'received_on' => '2026-10-01', 'is_overdue' => false]);
});

test('matches bank deposits by linked receivable or bank alias and lays the last 12 months out by month', function () {
    $deposits = ($this->overview)(seedCustomerOverview())['deposits'];

    expect($deposits)->toMatchArray([
        'total_12m' => 210_000,
        'count_12m' => 2,
        'total_all_time' => 260_000,
        'last_on' => '2026-10-01',
        'last_amount' => 105_000,
        'matched_counterparties' => ['墊腳石國際', '墊腳石圖書股份有限公司代付'],
    ])
        ->and(array_keys($deposits['by_month']))->toHaveCount(12)
        ->and(array_key_first($deposits['by_month']))->toBe('2025-11')
        ->and(array_key_last($deposits['by_month']))->toBe('2026-10')
        ->and($deposits['by_month']['2026-07'])->toBe(105_000)
        ->and($deposits['by_month']['2026-10'])->toBe(105_000)
        ->and($deposits['by_month']['2026-08'])->toBe(0);
});

test('lists open deals and open Redmine issues across the customer ids and its projects, de-duplicated', function () {
    $overview = ($this->overview)(seedCustomerOverview());

    expect($overview['deals'])->toHaveCount(1)
        ->and($overview['deals'][0])->toMatchArray(['title' => '門市 POS 第二期', 'stage' => 'proposal', 'stage_label' => '提案', 'amount_untaxed' => 800_000, 'probability' => 60, 'weighted_amount' => 480_000, 'next_action' => '寄報價', 'next_action_on' => '2026-10-20'])
        ->and($overview['open_issues'])->toBe(4)
        ->and($overview['redmine'])->toBe([
            ['project_id' => 15, 'identifier' => 'tcsb-apos', 'name' => '墊腳石 | APOS', 'open' => 2],
            ['project_id' => 16, 'identifier' => 'tcsb-5f-b2c', 'name' => '墊腳石 | 5F | B2C', 'open' => 1],
            ['project_id' => 17, 'identifier' => 'tcsb-erp', 'name' => '墊腳石 | ERP', 'open' => 1],
        ]);
});

test('flags an ended contract and money received without any matching bank deposit', function () {
    $customer = Customer::factory()->maintenance(240_000, MaintenanceCycle::Yearly, '2025-10-01', '2026-09-30')->create(['name' => '長照股份有限公司', 'short_name' => '長照']);
    Receivable::factory()->for($customer)->create(['amount_untaxed' => 100_000, 'expected_on' => '2026-08-01', 'status' => ReceivableStatus::Received, 'received_on' => '2026-08-15']);

    $overview = ($this->overview)($customer);

    expect($overview['maintenance'])->toMatchArray(['days_until_end' => -10, 'is_ended' => true, 'is_expiring_soon' => false, 'annualised_fee_untaxed' => 240_000])
        ->and($overview['flags'])->toBe(['維護合約已到期 10 天']);

    BankTransaction::factory()->create(['txn_date' => '2026-08-20', 'deposit' => 105_000, 'withdrawal' => 0, 'counterparty' => '台灣長期照顧公司', 'category' => TransactionCategory::Revenue]);

    expect(($this->overview)($customer)['flags'])->toBe(['維護合約已到期 10 天', '入帳對不到戶名']);
});

test('a counterparty goes to the customer with the longest matching alias, prefix either way', function () {
    $short = Customer::factory()->create(['name' => '我識', 'short_name' => '我識']);
    $long = Customer::factory()->create(['name' => '我識出版社', 'short_name' => '出版社']);
    $line = fn (string $date, string $counterparty) => BankTransaction::factory()->create(['txn_date' => $date, 'deposit' => 10_000, 'withdrawal' => 0, 'counterparty' => $counterparty, 'category' => TransactionCategory::Revenue]);
    $line('2026-09-01', '我識出版社有限公司');
    $line('2026-09-02', '我識');
    $line('2026-09-03', '我');

    expect(($this->overview)($long)['deposits'])->toMatchArray(['total_12m' => 10_000, 'count_12m' => 1])
        ->and(($this->overview)($short)['deposits'])->toMatchArray(['total_12m' => 10_000, 'count_12m' => 1, 'last_on' => '2026-09-02']);
});

test('a customer with nothing attached comes back empty but complete', function () {
    $overview = ($this->overview)(Customer::factory()->create(['short_name' => '湛盧']));

    expect($overview)
        ->maintenance->toBeNull()
        ->projects->toBe([])
        ->recurring->toBe([])
        ->deals->toBe([])
        ->open_issues->toBe(0)
        ->redmine->toBe([])
        ->flags->toBe([])
        ->and($overview['receivables'])->toMatchArray(['items' => [], 'outstanding_count' => 0, 'outstanding_taxed' => 0, 'overdue_taxed' => 0])
        ->and($overview['deposits'])->toMatchArray(['total_12m' => 0, 'total_all_time' => 0, 'last_on' => null, 'last_amount' => null, 'matched_counterparties' => []])
        ->and($overview['deposits']['by_month'])->toHaveCount(12);
});
