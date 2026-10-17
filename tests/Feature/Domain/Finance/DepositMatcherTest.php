<?php

use App\Domain\Finance\DepositMatcher;
use App\Enums\ReceivableStatus;
use App\Enums\TransactionCategory;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Receivable;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-17 10:00'));
});

/**
 * @param  array<string, mixed>  $attributes
 */
function unsettledDeposit(string $date, int $amount, ?string $counterparty, array $attributes = []): BankTransaction
{
    return BankTransaction::factory()->for(BankAccount::query()->first() ?? BankAccount::factory()->create())->create([
        'txn_date' => $date,
        'deposit' => $amount,
        'withdrawal' => 0,
        'counterparty' => $counterparty,
        'category' => TransactionCategory::Revenue,
        ...$attributes,
    ]);
}

/**
 * 長照 owes 期中款 300,000 (315,000 taxed), 尾款 200,000 (210,000) and 顧問費 10,000 (10,500). 墊腳石 owes two monthly fees of
 * 100,000 (105,000 each). Deposits: 315,000 from 長照 (exact), 525,000 from 長照 (期中款 + 尾款), 314,980 from 長照 (near,
 * remittance fee), 200,000 from 長照 (untaxed 尾款), 105,000 from 墊腳石, 8,990 from 磅礡 (nobody), plus a settled line, a
 * non-revenue line and a two-year-old line that must all be ignored.
 *
 * @return array{care: Customer, stone: Customer, mid: Receivable, final: Receivable, consulting: Receivable, fees: list<Receivable>}
 */
function seedDeposits(): array
{
    $care = Customer::factory()->create(['name' => '瓅瑩資產管理', 'short_name' => '長照']);
    $project = Project::factory()->for($care)->create(['name' => '長照平台']);
    $mid = Receivable::factory()->for($care)->for($project)->invoiced('2026-09-25')->create(['item' => '期中款', 'amount_untaxed' => 300_000, 'expected_on' => '2026-09-30']);
    $final = Receivable::factory()->for($care)->for($project)->create(['item' => '尾款', 'amount_untaxed' => 200_000, 'expected_on' => '2026-10-31']);
    $consulting = Receivable::factory()->for($care)->create(['item' => '顧問費', 'amount_untaxed' => 10_000, 'expected_on' => '2026-10-15']);
    Receivable::factory()->for($care)->create(['item' => '已收的', 'amount_untaxed' => 300_000, 'expected_on' => '2026-08-31', 'status' => ReceivableStatus::Received]);

    $stone = Customer::factory()->create(['name' => '墊腳石國際', 'short_name' => '墊腳石']);
    $fees = [
        Receivable::factory()->for($stone)->create(['item' => '維運費', 'amount_untaxed' => 100_000, 'expected_on' => '2026-08-31', 'is_recurring' => true]),
        Receivable::factory()->for($stone)->create(['item' => '維運費', 'amount_untaxed' => 100_000, 'expected_on' => '2026-09-30', 'is_recurring' => true]),
    ];

    unsettledDeposit('2026-10-01', 315_000, '瓅瑩資產管理');
    unsettledDeposit('2026-10-02', 525_000, '瓅瑩資產管理');
    unsettledDeposit('2026-10-03', 314_980, '瓅瑩資產');
    unsettledDeposit('2026-10-04', 200_000, '長照');
    unsettledDeposit('2026-10-05', 105_000, '墊腳石國際');
    unsettledDeposit('2026-10-06', 8_990, '磅礡廣媒行銷');
    unsettledDeposit('2026-10-07', 315_000, '瓅瑩資產管理', ['receivable_id' => $mid->id]);
    unsettledDeposit('2026-10-08', 315_000, '瓅瑩資產管理', ['category' => TransactionCategory::Reimbursement]);
    unsettledDeposit('2024-10-08', 315_000, '瓅瑩資產管理');

    return ['care' => $care, 'stone' => $stone, 'mid' => $mid, 'final' => $final, 'consulting' => $consulting, 'fees' => $fees];
}

it('suggests exact, combination and near matches for unsettled revenue deposits of the last 6 months', function () {
    ['mid' => $mid, 'final' => $final, 'fees' => $fees] = seedDeposits();

    $result = app(DepositMatcher::class)->suggest();
    $byAmount = collect($result['deposits'])->keyBy(fn (array $deposit): int => $deposit['transaction']['deposit']);

    expect($result['window_from'])->toBe('2026-04-01')
        ->and($result['summary'])->toBe(['deposits' => 6, 'with_best' => 4, 'with_suggestions' => 5, 'unmatched' => 1, 'total' => 1_468_970])
        ->and(array_column(array_column($result['deposits'], 'transaction'), 'deposit'))->toBe([105_000, 200_000, 314_980, 525_000, 315_000])
        ->and($byAmount[315_000]['customer']['short_name'])->toBe('長照')
        ->and($byAmount[315_000]['best'])->toMatchArray(['kind' => 'exact_taxed', 'kind_label' => '金額相符（含稅）', 'receivable_ids' => [$mid->id], 'receivable_id' => $mid->id, 'label' => '長照平台｜期中款', 'amount_taxed' => 315_000, 'difference' => 0, 'expected_on' => '2026-09-30', 'days_from_deposit' => 1])
        ->and($byAmount[525_000]['best'])->toMatchArray(['kind' => 'combination', 'receivable_ids' => [$mid->id, $final->id], 'label' => '長照平台｜期中款 + 長照平台｜尾款', 'amount_taxed' => 525_000])
        ->and($byAmount[314_980]['best'])->toBeNull()
        ->and($byAmount[314_980]['suggestions'][0])->toMatchArray(['kind' => 'near', 'receivable_id' => $mid->id, 'difference' => -20])
        ->and($byAmount[200_000]['best'])->toMatchArray(['kind' => 'exact_untaxed', 'receivable_id' => $final->id, 'difference' => -10_000])
        ->and($byAmount[105_000]['customer']['short_name'])->toBe('墊腳石')
        ->and(array_column($byAmount[105_000]['suggestions'], 'receivable_id'))->toBe([$fees[1]->id, $fees[0]->id])
        ->and($byAmount[105_000]['best']['receivable_id'])->toBe($fees[1]->id)
        ->and($result['unmatched_counterparties'])->toHaveCount(1)
        ->and($result['unmatched_counterparties'][0]['transaction'])->toMatchArray(['counterparty' => '磅礡廣媒行銷', 'deposit' => 8_990, 'date' => '2026-10-06']);
});

it('describes a single deposit and declines lines that are not unsettled revenue', function () {
    ['mid' => $mid] = seedDeposits();
    $matcher = app(DepositMatcher::class);

    $old = BankTransaction::query()->whereDate('txn_date', '2024-10-08')->sole();
    $settled = BankTransaction::query()->whereNotNull('receivable_id')->sole();
    $reimbursement = BankTransaction::query()->where('category', TransactionCategory::Reimbursement)->sole();
    $stranger = BankTransaction::query()->where('counterparty', '磅礡廣媒行銷')->sole();

    expect($matcher->suggestFor($old)['suggestions'][0])->toMatchArray(['receivable_id' => $mid->id, 'far' => true])
        ->and($matcher->suggestFor($old)['best'])->toBeNull()
        ->and($matcher->suggestFor($settled))->toBeNull()
        ->and($matcher->suggestFor($reimbursement))->toBeNull()
        ->and($matcher->suggestFor($stranger))->toBeNull()
        ->and($matcher->outstandingOptions($mid->customer_id))->toHaveCount(3)
        ->and($matcher->outstandingOptions($mid->customer_id)[$mid->id])->toBe('長照｜長照平台｜期中款　NT$315,000（預計 2026/09/30）')
        ->and($matcher->outstandingOptions())->toHaveCount(5);
});

it('settles several receivables with one deposit, linking the first and noting the rest', function () {
    ['mid' => $mid, 'final' => $final] = seedDeposits();
    $deposit = BankTransaction::query()->where('deposit', 525_000)->sole();

    $settled = app(DepositMatcher::class)->settle($deposit, [$mid->id, $final->id], CarbonImmutable::parse('2026-10-02'));

    expect($settled->pluck('id')->all())->toBe([$mid->id, $final->id])
        ->and($mid->refresh())->status->toBe(ReceivableStatus::Received)->received_on->toDateString()->toBe('2026-10-02')
        ->and($final->refresh())->status->toBe(ReceivableStatus::Received)
        ->and($deposit->refresh())->receivable_id->toBe($mid->id)->notes->toBe("同筆入帳亦沖銷應收 #{$final->id}")
        ->and(collect(app(DepositMatcher::class)->suggest()['deposits'])->pluck('transaction.deposit')->all())->not->toContain(525_000);
});

it('refuses to settle nothing, a settled deposit or a receivable that is not outstanding', function () {
    ['mid' => $mid, 'final' => $final] = seedDeposits();
    $matcher = app(DepositMatcher::class);
    $deposit = BankTransaction::query()->where('deposit', 315_000)->whereNull('receivable_id')->whereDate('txn_date', '2026-10-01')->sole();
    $received = Receivable::query()->where('item', '已收的')->sole();

    expect(fn () => $matcher->settle($deposit, [], today()))->toThrow(InvalidArgumentException::class, '至少要選一筆應收')
        ->and(fn () => $matcher->settle($deposit, [$received->id], today()))->toThrow(InvalidArgumentException::class, '不是未收狀態')
        ->and(fn () => $matcher->settle($deposit, [999_999], today()))->toThrow(InvalidArgumentException::class, '找不到應收 #999999');

    $matcher->settle($deposit, [$mid->id], today());

    expect(fn () => $matcher->settle($deposit->refresh(), [$final->id], today()))->toThrow(InvalidArgumentException::class, '已經沖銷過')
        ->and($final->refresh()->status)->toBe(ReceivableStatus::Planned);
});

it('offers but never pre-selects an exact match whose expected date is months away from the deposit', function () {
    $stone = Customer::factory()->create(['name' => '墊腳石國際', 'short_name' => '墊腳石']);
    $octoberFee = Receivable::factory()->for($stone)->create(['item' => '維運費', 'amount_untaxed' => 100_000, 'expected_on' => '2026-10-31', 'is_recurring' => true]);
    $mayDeposit = unsettledDeposit('2026-05-29', 105_000, '墊腳石國際');
    $septemberDeposit = unsettledDeposit('2026-09-28', 105_000, '墊腳石國際');

    $matcher = app(DepositMatcher::class);
    $far = $matcher->suggestFor($mayDeposit);
    $close = $matcher->suggestFor($septemberDeposit);

    expect($far['best'])->toBeNull()
        ->and($far['suggestions'][0])->toMatchArray(['kind' => 'exact_taxed', 'receivable_id' => $octoberFee->id, 'far' => true])
        ->and($far['suggestions'][0]['days_from_deposit'])->toBeGreaterThan(DepositMatcher::MAX_BEST_DAYS)
        ->and($close['best'])->toMatchArray(['kind' => 'exact_taxed', 'receivable_id' => $octoberFee->id, 'far' => false]);
});
