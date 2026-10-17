<?php

use App\Enums\MaintenanceCycle;
use App\Enums\ReceivableStatus;
use App\Enums\TransactionCategory;
use App\Mcp\Servers\InfolinkServer;
use App\Mcp\Tools\CustomerOverviewSummary;
use App\Models\BankTransaction;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\RedmineIssue;

beforeEach(function () {
    $this->travelTo('2026-10-10 10:00:00');
});

test('customer_overview returns the contract, projects, receivables, deposits and flags of one customer', function () {
    $stone = Customer::factory()->maintenance(100_000, MaintenanceCycle::Monthly, '2026-01-01', '2026-11-15')->create(['name' => '墊腳石圖書股份有限公司', 'short_name' => '墊腳石', 'counterparties' => ['墊腳石國際']]);
    Customer::factory()->create(['short_name' => '長照']);
    $apos = Project::factory()->for($stone)->create(['name' => 'APOS 2.0', 'contract_amount_untaxed' => null, 'redmine_project_id' => 15]);
    Receivable::factory()->for($stone)->for($apos)->create(['item' => '尾款', 'amount_untaxed' => 200_000, 'expected_on' => '2026-10-01', 'status' => ReceivableStatus::Planned]);
    BankTransaction::factory()->create(['txn_date' => '2026-07-31', 'deposit' => 105_000, 'withdrawal' => 0, 'counterparty' => '墊腳石國際', 'category' => TransactionCategory::Revenue]);
    RedmineIssue::factory()->count(2)->create(['project_id' => 15, 'project_identifier' => 'tcsb-apos', 'project_name' => '墊腳石 | APOS']);

    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(CustomerOverviewSummary::class, ['customer' => '墊腳石'])
        ->assertOk()
        ->assertSee([
            '"generated_at":"2026-10-10T10:00:00+08:00"',
            '"id":'.$stone->id.',"name":"墊腳石圖書股份有限公司","short_name":"墊腳石","status":"active","status_label":"往來中","counterparties":["墊腳石國際"]',
            '"maintenance":{"fee_untaxed":100000,"fee_taxed":105000,"cycle":"monthly","cycle_label":"每月","per_label":"月","since":"2026-01-01","until":"2026-11-15","days_until_end":36,"is_ended":false,"is_expiring_soon":true,"annualised_fee_untaxed":1200000}',
            '"name":"APOS 2.0","status":"active","status_label":"進行中","contract_amount_untaxed":null,"target_close_date":null,"redmine_project_id":15,"open_issues":2,"receivables":{"scheduled_untaxed":200000,"received_untaxed":0,"outstanding_untaxed":200000,"overdue_untaxed":200000,"outstanding_taxed":210000}',
            '"item":"尾款","project_id":'.$apos->id.',"project":"APOS 2.0","amount_untaxed":200000,"amount_taxed":210000,"expected_on":"2026-10-01","status":"planned","status_label":"預計","confidence":"high","invoiced_on":null,"is_overdue":true,"days":-9',
            '"outstanding_count":1,"outstanding_untaxed":200000,"outstanding_taxed":210000,"overdue_count":1,"overdue_untaxed":200000,"overdue_taxed":210000',
            '"deposits":{"total_12m":105000,"count_12m":1,"total_all_time":105000,"last_on":"2026-07-31","last_amount":105000,"by_month":{"2025-11":0',
            '"2026-07":105000',
            '"open_issues":2,"redmine":[{"project_id":15,"identifier":"tcsb-apos","name":"墊腳石 | APOS","open":2}]',
            '"flags":["維護合約 36 天後到期","逾期應收 NT$210,000（含稅）","合約金額未填：APOS 2.0"]',
        ]);
});

test('customer_overview also accepts the full name', function () {
    Customer::factory()->create(['name' => '我識出版社有限公司', 'short_name' => '我識']);

    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(CustomerOverviewSummary::class, ['customer' => '我識出版社有限公司'])
        ->assertOk()
        ->assertSee(['"short_name":"我識"', '"maintenance":null', '"flags":[]']);
});

test('customer_overview lists the known customers when the customer is unknown', function () {
    Customer::factory()->create(['short_name' => '我識']);
    Customer::factory()->create(['short_name' => '長照']);

    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(CustomerOverviewSummary::class, ['customer' => '墊腳石'])
        ->assertHasErrors(['Unknown customer [墊腳石]', '我識, 長照']);
});

test('customer_overview needs the customer parameter', function () {
    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(CustomerOverviewSummary::class)
        ->assertHasErrors(['Pass `customer`']);
});

test('customer_overview is refused without the read ability', function () {
    Customer::factory()->create(['short_name' => '我識']);

    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(CustomerOverviewSummary::class, ['customer' => '我識'])
        ->assertHasErrors(['not found']);
});
