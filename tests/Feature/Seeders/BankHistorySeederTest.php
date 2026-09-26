<?php

use App\Enums\Source;
use App\Enums\TransactionCategory;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use Database\Seeders\BankHistorySeeder;
use Database\Seeders\FinanceDataSeeder;

/**
 * @return list<array{date:string, balance:int, delta:int}>
 */
function bankChain(): array
{
    return BankTransaction::query()
        ->orderBy('txn_date')
        ->orderBy('sequence')
        ->orderBy('id')
        ->get()
        ->map(fn (BankTransaction $txn): array => [
            'date' => $txn->txn_date->toDateString(),
            'balance' => $txn->balance,
            'delta' => $txn->deposit - $txn->withdrawal,
        ])
        ->all();
}

/**
 * @return array<string, mixed>
 */
function bankMonthlySummary(): array
{
    return json_decode(file_get_contents(FinanceDataSeeder::dataPath('bank_monthly_summary.json')), true, flags: JSON_THROW_ON_ERROR);
}

it('seeds the bank history idempotently with an unbroken chain matching the monthly summary', function () {
    $this->seed(BankHistorySeeder::class);
    $this->seed(BankHistorySeeder::class);

    $history = json_decode(file_get_contents(BankHistorySeeder::dataPath()), true, flags: JSON_THROW_ON_ERROR);
    $summary = bankMonthlySummary();

    expect(BankAccount::query()->count())->toBe(1)
        ->and(BankTransaction::query()->count())->toBe(count($history))
        ->and(BankTransaction::query()->count())->toBe(129)
        ->and(BankTransaction::query()->where('source', '!=', Source::Bank)->count())->toBe(0)
        ->and(BankTransaction::query()->where('vault_ref', FinanceDataSeeder::ANALYSIS_NOTE)->count())->toBe(129)
        ->and((int) BankTransaction::query()->where('category', TransactionCategory::Revenue)->sum('deposit'))->toBe(4_830_128);

    $chain = bankChain();
    $previous = $summary['opening_balance_2025_07_01'];

    foreach ($chain as $line) {
        expect($line['balance'])->toBe($previous + $line['delta'], "Break on {$line['date']}");
        $previous = $line['balance'];
    }

    expect($chain[0]['date'])->toBe('2025-07-04')
        ->and(end($chain)['date'])->toBe('2026-06-26')
        ->and($previous)->toBe(87_497);

    foreach ($summary['rows'] as $month) {
        if ($month['month'] > '2026-06') {
            continue;
        }

        $lines = BankTransaction::query()
            ->orderBy('txn_date')
            ->orderBy('sequence')
            ->get()
            ->filter(fn (BankTransaction $txn): bool => $txn->txn_date->format('Y-m') === $month['month']);

        expect([(int) $lines->sum('deposit'), (int) $lines->sum('withdrawal'), $lines->last()->balance])
            ->toBe([$month['deposit'], $month['withdrawal'], $month['closing_balance']], $month['month']);
    }
})->skip(fn (): bool => ! BankHistorySeeder::dataIsAvailable(), 'Real bank history seed data is not present (gitignored).');

it('imports the history into an account that already holds the newer lines and joins them without a break', function () {
    $this->seed(FinanceDataSeeder::class);
    $this->seed(BankHistorySeeder::class);
    $this->seed(FinanceDataSeeder::class);
    $this->seed(BankHistorySeeder::class);

    $chain = bankChain();
    $previous = bankMonthlySummary()['opening_balance_2025_07_01'];

    foreach ($chain as $line) {
        expect($line['balance'])->toBe($previous + $line['delta'], "Break on {$line['date']}");
        $previous = $line['balance'];
    }

    expect(BankTransaction::query()->count())->toBe(129 + 34)
        ->and($previous)->toBe(1_072_776)
        ->and(BankTransaction::query()->whereDate('txn_date', '2025-10-31')->where('deposit', 600_000)->first()->receivable?->external_key)
        ->toBe('changzhao-deposit');
})->skip(fn (): bool => ! BankHistorySeeder::dataIsAvailable(), 'Real bank history seed data is not present (gitignored).');
