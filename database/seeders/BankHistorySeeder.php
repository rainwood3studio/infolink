<?php

namespace Database\Seeders;

use App\Domain\Finance\TransactionImporter;
use App\Enums\Source;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Receivable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Loads the line-level bank history 2025-07-01 .. 2026-07-05 (parsed from the 彰銀 PDF statements in the vault) from the
 * gitignored database/seeders/data/finance/bank_transactions_history.json.
 *
 * Idempotent: lines are keyed by the importer (account|date|summary|amounts|balance), so running it again matches the
 * same records. Strict mode checks the chain inside the batch; since the importer only looks backwards, this seeder
 * also checks that the first stored line after the batch (e.g. 2026-07-06 from FinanceDataSeeder) chains from the
 * batch's last balance, so the order in which the two are seeded does not matter.
 */
class BankHistorySeeder extends Seeder
{
    public const string FILE = 'bank_transactions_history.json';

    public static function dataPath(): string
    {
        return FinanceDataSeeder::dataPath(self::FILE);
    }

    public static function dataIsAvailable(): bool
    {
        return is_file(self::dataPath());
    }

    /**
     * Seed the historical bank lines.
     */
    public function run(TransactionImporter $importer): void
    {
        if (! self::dataIsAvailable()) {
            $this->command?->warn('Skipping BankHistorySeeder: '.self::dataPath().' does not exist (the real finance data is gitignored).');

            return;
        }

        $transactions = collect($this->load(self::dataPath()));
        $accounts = collect($this->load(FinanceDataSeeder::dataPath('bank_accounts.json')))->keyBy('name');

        DB::transaction(function () use ($importer, $transactions, $accounts): void {
            foreach ($transactions->groupBy('account_name') as $accountName => $accountRows) {
                $accountData = $accounts->get($accountName) ?? throw new RuntimeException("Bank account [{$accountName}] is not in bank_accounts.json.");
                $account = BankAccount::query()->updateOrCreate(['name' => $accountName], $accountData);

                $rows = $accountRows
                    ->map(fn (array $row): array => [
                        ...collect($row)->except(['account_name', 'receivable_ref'])->all(),
                        'receivable_id' => $this->receivableId($row['receivable_ref']),
                        'vault_ref' => FinanceDataSeeder::ANALYSIS_NOTE,
                    ])
                    ->values()
                    ->all();

                $importer->import($account, $rows, Source::Bank, strict: true);

                $this->assertChainsIntoLaterLines($account, end($rows));
            }
        });
    }

    protected function receivableId(?string $ref): ?int
    {
        if ($ref === null) {
            return null;
        }

        $id = Receivable::query()->where('source', Source::Vault)->where('external_key', $ref)->value('id');

        if ($id === null) {
            $this->command?->warn("BankHistorySeeder: receivable [{$ref}] is not seeded yet; the line is imported without the link (re-run after FinanceDataSeeder to fill it).");
        }

        return $id;
    }

    /**
     * @param  array{txn_date:string, balance:int}  $lastRow
     */
    protected function assertChainsIntoLaterLines(BankAccount $account, array $lastRow): void
    {
        $next = $account->transactions()
            ->whereDate('txn_date', '>', $lastRow['txn_date'])
            ->orderBy('txn_date')
            ->orderBy('sequence')
            ->orderBy('id')
            ->first();

        if (! $next instanceof BankTransaction) {
            return;
        }

        $expected = $lastRow['balance'] - $next->withdrawal + $next->deposit;

        if ($expected !== $next->balance) {
            throw new RuntimeException(sprintf(
                'Bank history for [%s] ends at %s with balance %d, but the next stored line (%s %s, balance %d) expects %d.',
                $account->name, $lastRow['txn_date'], $lastRow['balance'], $next->txn_date->toDateString(), $next->summary, $next->balance, $expected,
            ));
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function load(string $path): array
    {
        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
}
