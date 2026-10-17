<?php

use App\Enums\MaintenanceCycle;
use App\Enums\ProjectStatus;
use App\Enums\ReceivableStatus;
use App\Enums\TransactionCategory;
use App\Mcp\Servers\InfolinkServer;
use App\Mcp\Tools\BillingProgressSummary;
use App\Models\BankTransaction;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Receivable;

beforeEach(function () {
    $this->travelTo('2026-10-17 10:00:00');
});

test('billing_progress returns the summary, each project, maintenance periods and unsettled deposits', function () {
    $care = Customer::factory()->create(['name' => '瓅瑩資產管理', 'short_name' => '長照']);
    $project = Project::factory()->for($care)->create(['name' => '長照平台', 'contract_amount_untaxed' => 1_000_000, 'status' => ProjectStatus::Closing]);
    Receivable::factory()->for($care)->for($project)->create(['item' => '頭期款', 'amount_untaxed' => 300_000, 'expected_on' => '2026-03-31', 'status' => ReceivableStatus::Received, 'received_on' => '2026-03-31']);
    $mid = Receivable::factory()->for($care)->for($project)->invoiced('2026-09-25', 'AB-00000002')->create(['item' => '期中款', 'amount_untaxed' => 300_000, 'expected_on' => '2026-09-30']);
    Receivable::factory()->for($care)->for($project)->create(['item' => '尾款', 'amount_untaxed' => 200_000, 'expected_on' => '2026-10-22']);
    Customer::factory()->maintenance(100_000, MaintenanceCycle::Monthly, '2026-09-01')->create(['name' => '墊腳石國際', 'short_name' => '墊腳石']);
    $deposit = BankTransaction::factory()->create(['txn_date' => '2026-10-01', 'deposit' => 315_000, 'withdrawal' => 0, 'counterparty' => '瓅瑩資產管理', 'category' => TransactionCategory::Revenue]);

    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(BillingProgressSummary::class)
        ->assertOk()
        ->assertSee([
            '"generated_at":"2026-10-17T10:00:00+08:00"',
            '"today":"2026-10-17"',
            '"name":"長照平台","customer":"長照"',
            '"status":"closing","status_label":"結案中","contract_amount_untaxed":1000000,"scheduled":800000,"received":300000,"invoiced_outstanding":300000,"planned":200000',
            '"overdue":{"amount":300000,"count":1},"due_uninvoiced":{"amount":200000,"count":1},"unscheduled":200000,"is_over_scheduled":false,"has_problems":true',
            '"item":"期中款","untaxed":300000,"taxed":315000,"expected_on":"2026-09-30","status":"invoiced","status_label":"已開票","confidence":"high","invoiced_on":"2026-09-25","invoice_number":"AB-00000002","received_on":null,"is_overdue":true,"is_due_uninvoiced":false,"days_overdue":17',
            '"without_project":[]',
            '"customer":"墊腳石","fee_untaxed":100000,"cycle":"monthly"',
            '"label":"2026/10","from":"2026-10-01","to":"2026-10-31","is_current":true,"is_future":false,"status":"missing","status_label":"沒有應收列","receivable":null',
            '"summary":{"projects":1,"contract_total":1000000,"scheduled":800000,"received":300000,"outstanding":500000,"invoiced_outstanding":300000,"planned":200000,"unscheduled":200000,"overdue":{"amount":300000,"count":1},"due_uninvoiced":{"amount":200000,"count":1},"over_scheduled":[],"missing_contract":[],"recurring_missing":4,"recurring_planned":0}',
            '"unsettled_deposits":{"window_from":"2026-04-01","deposits":[{"transaction":{"id":'.$deposit->id.',"date":"2026-10-01","counterparty":"瓅瑩資產管理"',
            '"best":{"kind":"exact_taxed","kind_label":"金額相符（含稅）","receivable_ids":['.$mid->id.'],"receivable_id":'.$mid->id.',"label":"長照平台｜期中款","amount_taxed":315000,"difference":0,"expected_on":"2026-09-30","days_from_deposit":1',
            '"unmatched_counterparties":[]',
            '"summary":{"deposits":1,"with_best":1,"with_suggestions":1,"unmatched":0,"total":315000}',
        ]);
});

test('billing_progress returns empty sections when nothing exists', function () {
    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(BillingProgressSummary::class)
        ->assertOk()
        ->assertSee(['"projects":[]', '"without_project":[]', '"recurring":[]', '"contract_total":0', '"deposits":[]']);
});

test('billing_progress needs the read ability', function () {
    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(BillingProgressSummary::class)
        ->assertHasErrors(['not found']);
});
