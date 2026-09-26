<?php

namespace App\Domain\Finance;

use App\Domain\Finance\Exceptions\BalanceContinuityException;
use App\Enums\Source;
use App\Enums\TransactionCategory;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Idempotently imports bank statement lines and checks that the printed balances chain.
 *
 * Each line is keyed by sha1(account id|date|summary|withdrawal|deposit|balance). When the same line appears
 * more than once on the same day, the n-th repeat (n ≥ 1) gets `|n` appended before hashing, so re-importing
 * an overlapping statement matches the same records instead of duplicating them.
 */
class TransactionImporter
{
    /**
     * Optional row keys that are only written when present, so re-imports never wipe manual edits.
     */
    private const array OPTIONAL_ATTRIBUTES = ['counterparty', 'category', 'is_one_off', 'receivable_id', 'notes', 'vault_ref'];

    /**
     * @param  list<array{txn_date:string|DateTimeInterface, summary:?string, withdrawal:int|string|null, deposit:int|string|null, balance:int|string, counterparty?:?string, category?:TransactionCategory|string|null, is_one_off?:bool, receivable_id?:?int, notes?:?string, vault_ref?:?string}>  $rows  Statement lines in statement order.
     * @param  bool  $strict  Throw {@see BalanceContinuityException} (and roll back) when any balance does not chain.
     */
    public function import(BankAccount $account, array $rows, Source $source = Source::Bank, bool $strict = false): ImportResult
    {
        $normalized = array_map($this->normalizeRow(...), array_values($rows));
        $keyed = $this->assignExternalKeys($account, $normalized);

        return DB::transaction(function () use ($account, $keyed, $source, $strict): ImportResult {
            $breaks = $this->findContinuityBreaks($account, $keyed, $source);

            if ($strict && $breaks !== []) {
                throw new BalanceContinuityException($breaks);
            }

            $created = 0;
            $updated = 0;
            $skipped = 0;
            $nextSequence = [];

            foreach ($keyed as $row) {
                $existing = BankTransaction::query()
                    ->where('source', $source)
                    ->where('external_key', $row['external_key'])
                    ->first();

                $attributes = $this->attributesFor($account, $row);

                if ($existing === null) {
                    $date = $row['txn_date']->toDateString();
                    $nextSequence[$date] ??= $this->nextSequenceFor($account, $row['txn_date']);
                    $attributes['sequence'] = $nextSequence[$date]++;

                    BankTransaction::upsertFromSource($source, $row['external_key'], $attributes);
                    $created++;

                    continue;
                }

                $existing->fill($attributes);

                if ($existing->isDirty()) {
                    $existing->save();
                    $updated++;
                } else {
                    $skipped++;
                }
            }

            return new ImportResult($created, $updated, $skipped, $breaks);
        });
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{txn_date:CarbonImmutable, summary:string, withdrawal:int, deposit:int, balance:int, optional:array<string, mixed>}
     */
    protected function normalizeRow(array $row): array
    {
        foreach (['txn_date', 'balance'] as $required) {
            if (! isset($row[$required]) || $row[$required] === '') {
                throw new InvalidArgumentException("Bank statement row is missing [{$required}].");
            }
        }

        $optional = array_intersect_key($row, array_flip(self::OPTIONAL_ATTRIBUTES));

        if (isset($optional['category']) && is_string($optional['category'])) {
            $optional['category'] = TransactionCategory::from($optional['category']);
        }

        return [
            'txn_date' => CarbonImmutable::parse($row['txn_date'])->startOfDay(),
            'summary' => trim((string) ($row['summary'] ?? '')),
            'withdrawal' => $this->toAmount($row['withdrawal'] ?? 0),
            'deposit' => $this->toAmount($row['deposit'] ?? 0),
            'balance' => $this->toAmount($row['balance']),
            'optional' => $optional,
        ];
    }

    protected function toAmount(int|float|string|null $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        return (int) round((float) str_replace([',', ' '], '', (string) $value));
    }

    /**
     * @param  list<array{txn_date:CarbonImmutable, summary:string, withdrawal:int, deposit:int, balance:int, optional:array<string, mixed>}>  $rows
     * @return list<array{txn_date:CarbonImmutable, summary:string, withdrawal:int, deposit:int, balance:int, optional:array<string, mixed>, external_key:string}>
     */
    protected function assignExternalKeys(BankAccount $account, array $rows): array
    {
        $occurrences = [];

        return array_map(function (array $row) use ($account, &$occurrences): array {
            $base = implode('|', [
                $account->getKey(),
                $row['txn_date']->toDateString(),
                $row['summary'],
                $row['withdrawal'],
                $row['deposit'],
                $row['balance'],
            ]);

            $occurrence = $occurrences[$base] ?? 0;
            $occurrences[$base] = $occurrence + 1;

            $row['external_key'] = sha1($occurrence === 0 ? $base : $base.'|'.$occurrence);

            return $row;
        }, $rows);
    }

    /**
     * Walk the rows in order starting from the latest stored balance that precedes the batch.
     *
     * @param  list<array{txn_date:CarbonImmutable, summary:string, withdrawal:int, deposit:int, balance:int, optional:array<string, mixed>, external_key:string}>  $rows
     * @return list<ContinuityBreak>
     */
    protected function findContinuityBreaks(BankAccount $account, array $rows, Source $source): array
    {
        if ($rows === []) {
            return [];
        }

        $previousBalance = $this->balanceBefore($account, $rows, $source);
        $breaks = [];

        foreach ($rows as $index => $row) {
            if ($previousBalance !== null) {
                $expected = $previousBalance - $row['withdrawal'] + $row['deposit'];

                if ($expected !== $row['balance']) {
                    $breaks[] = new ContinuityBreak($index, $row['txn_date'], $row['summary'], $previousBalance, $expected, $row['balance']);
                }
            }

            $previousBalance = $row['balance'];
        }

        return $breaks;
    }

    /**
     * Balance of the latest stored transaction on or before the first row's date that is not part of this batch.
     *
     * @param  list<array{txn_date:CarbonImmutable, external_key:string}>  $rows
     */
    protected function balanceBefore(BankAccount $account, array $rows, Source $source): ?int
    {
        $batchKeys = array_column($rows, 'external_key');

        $previous = $account->transactions()
            ->whereDate('txn_date', '<=', $rows[0]['txn_date'])
            ->where(fn ($query) => $query
                ->where('source', '!=', $source)
                ->orWhereNull('external_key')
                ->orWhereNotIn('external_key', $batchKeys))
            ->orderByDesc('txn_date')
            ->orderByDesc('sequence')
            ->orderByDesc('id')
            ->first();

        return $previous?->balance;
    }

    protected function nextSequenceFor(BankAccount $account, CarbonImmutable $date): int
    {
        $max = $account->transactions()->whereDate('txn_date', $date)->max('sequence');

        return $max === null ? 0 : ((int) $max) + 1;
    }

    /**
     * @param  array{txn_date:CarbonImmutable, summary:string, withdrawal:int, deposit:int, balance:int, optional:array<string, mixed>}  $row
     * @return array<string, mixed>
     */
    protected function attributesFor(BankAccount $account, array $row): array
    {
        return [
            'bank_account_id' => $account->getKey(),
            'txn_date' => $row['txn_date'],
            'summary' => $row['summary'],
            'withdrawal' => $row['withdrawal'],
            'deposit' => $row['deposit'],
            'balance' => $row['balance'],
            ...$row['optional'],
        ];
    }
}
