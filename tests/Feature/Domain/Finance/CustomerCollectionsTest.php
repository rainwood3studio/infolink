<?php

use App\Domain\Finance\CustomerCollections;
use App\Enums\MaintenanceCycle;
use App\Enums\TransactionCategory;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Customer;
use App\Models\Receivable;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 10:00'));
});

/**
 * @param  array<string, mixed>  $attributes
 */
function depositLine(BankAccount $account, string $date, int $amount, ?string $counterparty, array $attributes = []): BankTransaction
{
    return BankTransaction::factory()->for($account)->create([
        'txn_date' => $date,
        'deposit' => $amount,
        'withdrawal' => 0,
        'counterparty' => $counterparty,
        'category' => TransactionCategory::Revenue,
        ...$attributes,
    ]);
}

/**
 * 墊腳石 (monthly 100,000 from July) pays July on time, August on 09/02 (grace) and September bundled with 300,000 of
 * project money on 10/01. 我識 (monthly 20,000 from July) has nothing in July, 21,000 in August from itself and 10,000
 * in September from its related company 我視整合傳播; October is still open. 南陽 pays its yearly 40,000 in March, less a
 * 20-dollar remittance fee. 長照's
 * only deposit has no counterparty but is linked to a receivable. 盟立 has no contract. 磅礡 matches nobody.
 *
 * @return array{stone: Customer, press: Customer, nanyang: Customer, care: Customer, mirle: Customer}
 */
function seedCustomerCollections(): array
{
    $account = BankAccount::factory()->create();

    $stone = Customer::factory()->maintenance(100_000, MaintenanceCycle::Monthly, '2026-07-01')->create(['name' => '墊腳石國際', 'short_name' => '墊腳石']);
    $press = Customer::factory()->maintenance(20_000, MaintenanceCycle::Monthly, '2026-07-01')->create(['name' => '我識出版社有限公司', 'short_name' => '我識', 'counterparties' => ['我視整合傳播']]);
    $nanyang = Customer::factory()->maintenance(40_000, MaintenanceCycle::Yearly, '2026-03-01')->create(['name' => '南陽實業', 'short_name' => '南陽']);
    $care = Customer::factory()->create(['name' => '瓅瑩資產管理', 'short_name' => '長照']);
    $mirle = Customer::factory()->create(['name' => '盟立自動化', 'short_name' => '盟立']);

    depositLine($account, '2026-07-31', 105_000, '墊腳石國際');
    depositLine($account, '2026-09-02', 105_000, '墊腳石國際');
    depositLine($account, '2026-10-01', 400_000, '墊腳石國際');
    depositLine($account, '2026-10-05', 999, '墊腳石國際', ['category' => TransactionCategory::Reimbursement]);
    BankTransaction::factory()->for($account)->create(['txn_date' => '2026-10-06', 'deposit' => 0, 'withdrawal' => 5_000, 'counterparty' => '墊腳石國際', 'category' => TransactionCategory::Revenue]);

    depositLine($account, '2026-08-15', 21_000, '我識出版社');
    depositLine($account, '2026-09-10', 10_000, '我視整合傳播');

    depositLine($account, '2026-03-27', 39_980, '南陽實業');
    depositLine($account, '2026-03-16', 50_000, '盟立自動化');
    depositLine($account, '2026-08-25', 8_990, '磅礡廣媒行銷');

    $receivable = Receivable::factory()->for($care)->create(['item' => '頭期款', 'amount_untaxed' => 600_000, 'expected_on' => '2025-10-31']);
    depositLine($account, '2025-10-31', 630_000, null, ['receivable_id' => $receivable->id]);

    return ['stone' => $stone, 'press' => $press, 'nanyang' => $nanyang, 'care' => $care, 'mirle' => $mirle];
}

it('matches deposits to customers by linked receivable, exact name, prefix and explicit alias', function () {
    seedCustomerCollections();

    $result = app(CustomerCollections::class)->calculate('all');
    $byName = collect($result['customers'])->keyBy('short_name');

    expect($result['has_bank_data'])->toBeTrue()
        ->and($result['as_of'])->toBe('2026-10-06')
        ->and($result['months'])->toBe(['2025-10', '2025-11', '2025-12', '2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10'])
        ->and($byName['墊腳石']['total'])->toBe(610_000)
        ->and($byName['墊腳石']['count'])->toBe(3)
        ->and($byName['墊腳石']['by_month']['2026-10'])->toBe(400_000)
        ->and($byName['墊腳石']['by_month']['2026-08'])->toBe(0)
        ->and($byName['我識']['total'])->toBe(31_000)
        ->and($byName['我識']['matched_counterparties'])->toBe(['我識出版社', '我視整合傳播'])
        ->and($byName['長照']['total'])->toBe(630_000)
        ->and($byName['長照']['first_on'])->toBe('2025-10-31')
        ->and($byName['盟立']['total'])->toBe(50_000)
        ->and($byName['盟立']['maintenance'])->toBeNull()
        ->and($result['unmatched'])->toBe([['counterparty' => '磅礡廣媒行銷', 'total' => 8_990, 'count' => 1, 'first_on' => '2026-08-25', 'last_on' => '2026-08-25']])
        ->and($result['summary'])->toMatchArray(['customers' => 5, 'with_deposits' => 5, 'maintenance_customers' => 3, 'missing_periods' => 1, 'short_periods' => 1, 'matched_total' => 1_360_980, 'unmatched_total' => 8_990]);
});

it('checks each monthly period, letting a deposit in the first days settle the previous month', function () {
    seedCustomerCollections();

    $byName = collect(app(CustomerCollections::class)->calculate()['customers'])->keyBy('short_name');
    $stone = $byName['墊腳石']['maintenance'];
    $press = $byName['我識']['maintenance'];

    expect($stone)->toMatchArray(['fee_untaxed' => 100_000, 'fee_taxed' => 105_000, 'cycle' => 'monthly', 'per_label' => '月', 'since' => '2026-07-01', 'until' => null, 'is_ended' => false, 'paid' => 4, 'short' => 0, 'missing' => 0, 'pending' => 0, 'next_due' => '2026-11-01'])
        ->and(array_column($stone['periods'], 'received', 'label'))->toBe(['2026/07' => 105_000, '2026/08' => 105_000, '2026/09' => 105_000, '2026/10' => 295_000])
        ->and(array_column($stone['periods'], 'status'))->toBe(['paid', 'paid', 'paid', 'paid'])
        ->and($stone['periods'][0])->toMatchArray(['key' => '2026-07-01', 'from' => '2026-07-01', 'to' => '2026-07-31', 'expected_untaxed' => 100_000, 'expected_taxed' => 105_000, 'status_label' => '已入帳'])
        ->and(array_column($press['periods'], 'status', 'label'))->toBe(['2026/07' => 'missing', '2026/08' => 'paid', '2026/09' => 'short', '2026/10' => 'pending'])
        ->and(array_column($press['periods'], 'received'))->toBe([0, 21_000, 10_000, 0])
        ->and($press)->toMatchArray(['paid' => 1, 'short' => 1, 'missing' => 1, 'pending' => 1]);
});

it('checks yearly periods from the anniversary, tolerating a remittance fee, and stops at the contract end', function () {
    ['nanyang' => $nanyang] = seedCustomerCollections();

    $maintenance = collect(app(CustomerCollections::class)->calculate()['customers'])->firstWhere('id', $nanyang->id)['maintenance'];

    expect($maintenance)->toMatchArray(['cycle' => 'yearly', 'per_label' => '年', 'paid' => 1, 'missing' => 0, 'next_due' => '2027-03-01'])
        ->and($maintenance['periods'])->toHaveCount(1)
        ->and($maintenance['periods'][0])->toMatchArray(['label' => '2026/03/01 – 2027/02/28', 'from' => '2026-03-01', 'to' => '2027-02-28', 'received' => 39_980, 'status' => 'paid']);

    $nanyang->update(['maintenance_since' => '2024-03-01', 'maintenance_until' => '2026-02-28']);
    $ended = collect(app(CustomerCollections::class)->calculate()['customers'])->firstWhere('id', $nanyang->id)['maintenance'];

    expect($ended)->toMatchArray(['is_ended' => true, 'next_due' => null, 'missing' => 2, 'paid' => 0])
        ->and(array_column($ended['periods'], 'to'))->toBe(['2025-02-28', '2026-02-28']);
});

it('puts maintenance customers first, the ones with problems on top, then the others by deposits', function () {
    seedCustomerCollections();

    expect(array_column(app(CustomerCollections::class)->calculate()['customers'], 'short_name'))->toBe(['我識', '墊腳石', '南陽', '長照', '盟立'])
        ->and(array_column(app(CustomerCollections::class)->calculate('12')['customers'], 'short_name'))->toBe(['我識', '墊腳石', '南陽', '盟立', '長照']);
});

it('zero-fills the window months and falls back to eighteen months for an unknown window', function () {
    seedCustomerCollections();

    $eighteen = app(CustomerCollections::class)->calculate('nonsense');
    $twelve = app(CustomerCollections::class)->calculate('12');
    $twentyFour = app(CustomerCollections::class)->calculate('24');

    expect($eighteen['window'])->toBe('18')
        ->and($eighteen['months'])->toHaveCount(18)
        ->and($eighteen['months'][0])->toBe('2025-05')
        ->and(collect($eighteen['customers'])->firstWhere('short_name', '長照'))->toMatchArray(['total' => 630_000, 'count' => 1])
        ->and($twelve['months'])->toHaveCount(12)
        ->and($twelve['months'][0])->toBe('2025-11')
        ->and(collect($twelve['customers'])->firstWhere('short_name', '長照'))->toMatchArray(['total' => 0, 'count' => 0, 'total_all_time' => 630_000, 'last_on' => '2025-10-31'])
        ->and($twentyFour['months'])->toHaveCount(24)
        ->and($twentyFour['months'][0])->toBe('2024-11');
});

it('reports no bank data when there are no revenue deposits', function () {
    Customer::factory()->maintenance(100_000, MaintenanceCycle::Monthly, '2026-09-01')->create(['short_name' => '墊腳石']);

    $result = app(CustomerCollections::class)->calculate();

    expect($result['has_bank_data'])->toBeFalse()
        ->and($result['as_of'])->toBeNull()
        ->and($result['customers'][0]['maintenance'])->toMatchArray(['missing' => 1, 'pending' => 1])
        ->and($result['summary']['missing_periods'])->toBe(1);
});
