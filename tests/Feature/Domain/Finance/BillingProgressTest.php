<?php

use App\Domain\Finance\BillingProgress;
use App\Enums\MaintenanceCycle;
use App\Enums\ProjectStatus;
use App\Enums\ReceivableStatus;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Receivable;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-17 10:00'));
});

/**
 * 長照平台 (contract 1,000,000, closing): 頭期款 300,000 received, 期中款 300,000 invoiced and overdue, 尾款 200,000 planned
 * for next week with no invoice yet, a cancelled 100,000 that must not count — 200,000 of the contract is not scheduled.
 * APOS (contract 500,000, active): 600,000 of receivables, i.e. over-scheduled, all planned far in the future.
 * 小案 has no contract amount and no receivables. 盟立 has a 50,000 receivable tied to no project. 墊腳石 pays 100,000 a
 * month from July: July received (before the shown window), August invoiced, September planned, October on missing.
 *
 * @return array{care: Project, apos: Project, small: Project}
 */
function seedBillingProgress(): array
{
    $care = Customer::factory()->create(['name' => '瓅瑩資產管理', 'short_name' => '長照']);
    $carePlatform = Project::factory()->for($care)->create(['name' => '長照平台', 'contract_amount_untaxed' => 1_000_000, 'status' => ProjectStatus::Closing]);
    Receivable::factory()->for($care)->for($carePlatform)->create(['item' => '頭期款', 'amount_untaxed' => 300_000, 'expected_on' => '2026-03-31', 'status' => ReceivableStatus::Received, 'invoiced_on' => '2026-03-20', 'invoice_number' => 'AB-00000001', 'received_on' => '2026-03-31']);
    Receivable::factory()->for($care)->for($carePlatform)->invoiced('2026-09-25', 'AB-00000002')->create(['item' => '期中款', 'amount_untaxed' => 300_000, 'expected_on' => '2026-09-30']);
    Receivable::factory()->for($care)->for($carePlatform)->create(['item' => '尾款', 'amount_untaxed' => 200_000, 'expected_on' => '2026-10-22']);
    Receivable::factory()->for($care)->for($carePlatform)->create(['item' => '追加', 'amount_untaxed' => 100_000, 'expected_on' => '2026-10-22', 'status' => ReceivableStatus::Cancelled]);

    $apos = Project::factory()->for($care)->create(['name' => 'APOS', 'contract_amount_untaxed' => 500_000, 'status' => ProjectStatus::Active]);
    Receivable::factory()->for($care)->for($apos)->create(['item' => '頭期款', 'amount_untaxed' => 300_000, 'expected_on' => '2026-12-31']);
    Receivable::factory()->for($care)->for($apos)->create(['item' => '尾款', 'amount_untaxed' => 300_000, 'expected_on' => '2027-03-31']);

    $small = Project::factory()->for($care)->create(['name' => '小案', 'contract_amount_untaxed' => null]);

    $mirle = Customer::factory()->create(['name' => '盟立自動化', 'short_name' => '盟立']);
    Receivable::factory()->for($mirle)->create(['item' => '顧問費', 'amount_untaxed' => 50_000, 'expected_on' => '2026-11-30']);

    $stone = Customer::factory()->maintenance(100_000, MaintenanceCycle::Monthly, '2026-07-01')->create(['name' => '墊腳石國際', 'short_name' => '墊腳石']);
    Receivable::factory()->for($stone)->create(['item' => '維運費', 'amount_untaxed' => 100_000, 'expected_on' => '2026-07-31', 'is_recurring' => true, 'status' => ReceivableStatus::Received, 'received_on' => '2026-08-01']);
    Receivable::factory()->for($stone)->invoiced('2026-08-28')->create(['item' => '維運費', 'amount_untaxed' => 100_000, 'expected_on' => '2026-08-31', 'is_recurring' => true]);
    Receivable::factory()->for($stone)->create(['item' => '維運費', 'amount_untaxed' => 100_000, 'expected_on' => '2026-09-30', 'is_recurring' => true]);

    return ['care' => $carePlatform, 'apos' => $apos, 'small' => $small];
}

it('measures each project from contract to receivables, invoices and money received', function () {
    ['care' => $carePlatform] = seedBillingProgress();

    $result = app(BillingProgress::class)->calculate();
    $care = collect($result['projects'])->firstWhere('id', $carePlatform->id);

    expect($result['today'])->toBe('2026-10-17')
        ->and($care)->toMatchArray([
            'name' => '長照平台', 'customer' => '長照', 'status' => 'closing', 'status_label' => '結案中', 'contract_amount_untaxed' => 1_000_000,
            'scheduled' => 800_000, 'received' => 300_000, 'invoiced_outstanding' => 300_000, 'planned' => 200_000, 'unscheduled' => 200_000, 'is_over_scheduled' => false,
            'overdue' => ['amount' => 300_000, 'count' => 1], 'due_uninvoiced' => ['amount' => 200_000, 'count' => 1], 'has_problems' => true,
        ])
        ->and(array_column($care['receivables'], 'item'))->toBe(['頭期款', '期中款', '尾款'])
        ->and($care['receivables'][1])->toMatchArray(['untaxed' => 300_000, 'taxed' => 315_000, 'status' => 'invoiced', 'status_label' => '已開票', 'invoiced_on' => '2026-09-25', 'invoice_number' => 'AB-00000002', 'is_overdue' => true, 'days_overdue' => 17, 'is_due_uninvoiced' => false])
        ->and($care['receivables'][2])->toMatchArray(['status' => 'planned', 'invoice_number' => null, 'is_overdue' => false, 'is_due_uninvoiced' => true]);
});

it('flags over-scheduled projects and those without a contract amount', function () {
    ['apos' => $aposProject, 'small' => $smallProject] = seedBillingProgress();

    $result = app(BillingProgress::class)->calculate();
    $apos = collect($result['projects'])->firstWhere('id', $aposProject->id);
    $small = collect($result['projects'])->firstWhere('id', $smallProject->id);

    expect($apos)->toMatchArray(['scheduled' => 600_000, 'planned' => 600_000, 'unscheduled' => -100_000, 'is_over_scheduled' => true, 'has_problems' => true, 'overdue' => ['amount' => 0, 'count' => 0], 'due_uninvoiced' => ['amount' => 0, 'count' => 0]])
        ->and($small)->toMatchArray(['contract_amount_untaxed' => null, 'scheduled' => 0, 'unscheduled' => null, 'is_over_scheduled' => false, 'has_problems' => false, 'receivables' => []])
        ->and($result['summary'])->toMatchArray([
            'projects' => 3, 'contract_total' => 1_500_000, 'scheduled' => 1_400_000, 'received' => 300_000, 'outstanding' => 1_100_000, 'invoiced_outstanding' => 300_000, 'planned' => 800_000, 'unscheduled' => 100_000,
            'overdue' => ['amount' => 300_000, 'count' => 1], 'due_uninvoiced' => ['amount' => 200_000, 'count' => 1],
            'over_scheduled' => ['APOS'], 'missing_contract' => ['小案'], 'recurring_missing' => 3, 'recurring_planned' => 1,
        ]);
});

it('orders closing projects first, then those with problems, then by contract amount', function () {
    seedBillingProgress();
    $care = Customer::query()->where('short_name', '長照')->sole();
    Project::factory()->for($care)->create(['name' => '大案', 'contract_amount_untaxed' => 9_000_000]);

    expect(array_column(app(BillingProgress::class)->calculate()['projects'], 'name'))->toBe(['長照平台', 'APOS', '大案', '小案']);
});

it('groups receivables without a project by customer', function () {
    seedBillingProgress();

    $without = app(BillingProgress::class)->calculate()['without_project'];

    expect($without)->toHaveCount(1)
        ->and($without[0])->toMatchArray(['customer' => '盟立', 'scheduled' => 50_000, 'received' => 0, 'outstanding' => 50_000])
        ->and($without[0]['receivables'][0])->toMatchArray(['item' => '顧問費', 'untaxed' => 50_000]);
});

it('checks the maintenance periods around today for a receivable row, an invoice and a payment', function () {
    seedBillingProgress();

    $recurring = app(BillingProgress::class)->calculate()['recurring'];

    expect($recurring)->toHaveCount(1)
        ->and($recurring[0])->toMatchArray(['customer' => '墊腳石', 'fee_untaxed' => 100_000, 'cycle' => 'monthly', 'cycle_label' => '每月', 'since' => '2026-07-01', 'until' => null, 'received' => 0, 'invoiced' => 1, 'planned' => 1, 'missing' => 3])
        ->and(array_column($recurring[0]['periods'], 'status', 'label'))->toBe(['2026/08' => 'invoiced', '2026/09' => 'planned', '2026/10' => 'missing', '2026/11' => 'missing', '2026/12' => 'missing'])
        ->and(array_column($recurring[0]['periods'], 'is_current'))->toBe([false, false, true, false, false])
        ->and($recurring[0]['periods'][0]['receivable'])->toMatchArray(['item' => '維運費', 'status' => 'invoiced', 'invoiced_on' => '2026-08-28'])
        ->and($recurring[0]['periods'][2])->toMatchArray(['from' => '2026-10-01', 'to' => '2026-10-31', 'status_label' => '沒有應收列', 'receivable' => null]);
});

it('clips maintenance periods to the contract dates and handles yearly cycles', function () {
    $nanyang = Customer::factory()->maintenance(40_000, MaintenanceCycle::Yearly, '2026-03-01', '2027-02-28')->create(['short_name' => '南陽']);
    Receivable::factory()->for($nanyang)->create(['item' => '年度維護', 'amount_untaxed' => 40_000, 'expected_on' => '2026-03-15', 'is_recurring' => true, 'status' => ReceivableStatus::Received]);
    Customer::factory()->maintenance(20_000, MaintenanceCycle::Monthly, '2026-10-01')->create(['short_name' => '我識']);

    $recurring = collect(app(BillingProgress::class)->calculate()['recurring'])->keyBy('customer');

    expect($recurring['南陽']['periods'])->toHaveCount(1)
        ->and($recurring['南陽']['periods'][0])->toMatchArray(['label' => '2026/03/01 – 2027/02/28', 'is_current' => true, 'status' => 'received'])
        ->and(array_column($recurring['我識']['periods'], 'label'))->toBe(['2026/10', '2026/11', '2026/12']);
});

it('returns empty sections when nothing exists', function () {
    expect(app(BillingProgress::class)->calculate())->toMatchArray([
        'projects' => [], 'without_project' => [], 'recurring' => [],
        'summary' => ['projects' => 0, 'contract_total' => 0, 'scheduled' => 0, 'received' => 0, 'outstanding' => 0, 'invoiced_outstanding' => 0, 'planned' => 0, 'unscheduled' => 0, 'overdue' => ['amount' => 0, 'count' => 0], 'due_uninvoiced' => ['amount' => 0, 'count' => 0], 'over_scheduled' => [], 'missing_contract' => [], 'recurring_missing' => 0, 'recurring_planned' => 0],
    ]);
});
