<?php

namespace Database\Seeders;

use App\Domain\Finance\ReceivableService;
use App\Domain\Finance\TransactionImporter;
use App\Enums\Confidence;
use App\Enums\ReceivableStatus;
use App\Enums\Source;
use App\Models\BankAccount;
use App\Models\CostBaseline;
use App\Models\Customer;
use App\Models\PlannedCashFlow;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Loads the real (gitignored) finance data exported from the vault in database/seeders/data/finance/.
 *
 * Mirrors tests/Feature/Domain/Finance/ForecastReferenceTest.php, so the seeded database reproduces the vault
 * note 《2026 下半年現金推估》 (balance 1,072,776 on 2026-09-22, year-end 2,128,778). Idempotent: every record
 * is matched by a stable source key, so running it again updates instead of duplicating.
 */
class FinanceDataSeeder extends Seeder
{
    public const string FORECAST_NOTE = '03.Business/Finance/2026 下半年現金推估.md';

    public const string ANALYSIS_NOTE = '03.Business/Finance/帳戶流水分析 2025-07~2026-08.md';

    /**
     * Statement notes per transaction month (Y-m).
     *
     * @var array<string, string>
     */
    public const array STATEMENT_NOTES = [
        '2026-07' => '03.Business/Finance/帳戶流水 2026-07~08（彰銀中壢）.md',
        '2026-08' => '03.Business/Finance/帳戶流水 2026-07~08（彰銀中壢）.md',
        '2026-09' => '03.Business/Finance/帳戶流水 2026-09（彰銀中壢）.md',
    ];

    /**
     * Monthly recurring fees (untaxed) per customer short name, with the project they belong to.
     *
     * @var array<string, array{amount:int, project:string}>
     */
    public const array RECURRING = [
        '墊腳石' => ['amount' => 100_000, 'project' => '整體維運（APOS2.0/ERP/POS/SCM/EC）'],
        '我識' => ['amount' => 20_000, 'project' => 'APP 維護'],
    ];

    /**
     * @var list<string>
     */
    public const array RECURRING_MONTHS = ['2026-09', '2026-10', '2026-11', '2026-12'];

    public static function dataPath(string $file = ''): string
    {
        return database_path('seeders/data/finance'.($file === '' ? '' : "/{$file}"));
    }

    public static function dataIsAvailable(): bool
    {
        return is_dir(self::dataPath());
    }

    /**
     * Seed the finance data.
     */
    public function run(ReceivableService $receivables, TransactionImporter $importer): void
    {
        DB::transaction(function () use ($receivables, $importer): void {
            $customerIds = $this->seedCustomers();
            $projectIds = $this->seedProjects($customerIds);
            $receivableIds = $this->seedReceivables($receivables, $customerIds, $projectIds);
            $this->seedRecurringReceivables($receivables, $customerIds, $projectIds);
            $this->seedTransactions($importer, $receivableIds);
            $this->seedCostBaselines();
            $this->seedPlannedCashFlows();
        });
    }

    /**
     * @return array<string, int> Customer id by short name.
     */
    protected function seedCustomers(): array
    {
        $ids = [];

        foreach ($this->load('customers') as $row) {
            $customer = Customer::upsertFromSource(Source::Vault, $row['short_name'], [
                'name' => $row['name'],
                'short_name' => $row['short_name'],
                'status' => $row['status'],
                'notes' => $row['notes'] ?? null,
                'vault_ref' => self::ANALYSIS_NOTE,
            ]);

            $ids[$row['short_name']] = $customer->getKey();
        }

        return $ids;
    }

    /**
     * @param  array<string, int>  $customerIds
     * @return array<string, int> Project id by "short name|project name".
     */
    protected function seedProjects(array $customerIds): array
    {
        $ids = [];

        foreach ($this->load('projects') as $row) {
            $key = "{$row['customer_short_name']}|{$row['name']}";

            $project = Project::upsertFromSource(Source::Vault, $key, [
                'customer_id' => $customerIds[$row['customer_short_name']],
                'name' => $row['name'],
                'contract_amount_untaxed' => $row['contract_amount_untaxed'],
                'status' => $row['status'],
                'target_close_date' => $row['target_close_date'],
                'notes' => $row['notes'] ?? null,
                'vault_ref' => self::FORECAST_NOTE,
            ]);

            $ids[$key] = $project->getKey();
        }

        return $ids;
    }

    /**
     * @param  array<string, int>  $customerIds
     * @param  array<string, int>  $projectIds
     * @return array<string, int> Receivable id by JSON `ref`.
     */
    protected function seedReceivables(ReceivableService $receivables, array $customerIds, array $projectIds): array
    {
        $ids = [];

        foreach ($this->load('receivables') as $row) {
            $receivable = $receivables->upsert([
                'customer_id' => $customerIds[$row['customer_short_name']],
                'project_id' => $projectIds["{$row['customer_short_name']}|{$row['project_name']}"] ?? null,
                'item' => $row['item'],
                'amount_untaxed' => $row['amount_untaxed'],
                'tax_rate' => $row['tax_rate'],
                'expected_on' => $row['expected_on'],
                'confidence' => $row['confidence'],
                'status' => $row['status'],
                'invoiced_on' => $row['invoiced_on'],
                'received_on' => $row['received_on'],
                'is_recurring' => $row['is_recurring'],
                'notes' => $row['notes'] ?? null,
                'vault_ref' => self::FORECAST_NOTE,
            ], Source::Vault, $row['ref']);

            $ids[$row['ref']] = $receivable->getKey();
        }

        return $ids;
    }

    /**
     * Monthly maintenance fees (not in the JSON; the vault note models them as flat monthly income).
     * 我識 September was already received inside the 09/18 payment.
     *
     * @param  array<string, int>  $customerIds
     * @param  array<string, int>  $projectIds
     */
    protected function seedRecurringReceivables(ReceivableService $receivables, array $customerIds, array $projectIds): void
    {
        foreach (self::RECURRING_MONTHS as $month) {
            foreach (self::RECURRING as $shortName => $fee) {
                $received = $month === '2026-09' && $shortName === '我識';

                $receivables->upsert([
                    'customer_id' => $customerIds[$shortName],
                    'project_id' => $projectIds["{$shortName}|{$fee['project']}"] ?? null,
                    'item' => '維運費',
                    'amount_untaxed' => $fee['amount'],
                    'tax_rate' => 0,
                    'expected_on' => CarbonImmutable::parse("{$month}-01")->endOfMonth()->toDateString(),
                    'confidence' => Confidence::High,
                    'status' => $received ? ReceivableStatus::Received : ReceivableStatus::Planned,
                    'received_on' => $received ? '2026-09-18' : null,
                    'is_recurring' => true,
                    'notes' => $received ? '已併入 2026-09-18 我識 860,000 入帳' : null,
                    'vault_ref' => self::FORECAST_NOTE,
                ], Source::Vault, "recurring:{$shortName}:{$month}");
            }
        }
    }

    /**
     * @param  array<string, int>  $receivableIds
     */
    protected function seedTransactions(TransactionImporter $importer, array $receivableIds): void
    {
        $transactions = collect($this->load('bank_transactions'));

        foreach ($this->load('bank_accounts') as $accountData) {
            $account = BankAccount::query()->updateOrCreate(['name' => $accountData['name']], $accountData);

            $rows = $transactions
                ->where('account_name', $accountData['name'])
                ->map(fn (array $row): array => [
                    ...collect($row)->except(['account_name', 'receivable_ref'])->all(),
                    'receivable_id' => $row['receivable_ref'] === null ? null : $receivableIds[$row['receivable_ref']],
                    'vault_ref' => self::STATEMENT_NOTES[substr($row['txn_date'], 0, 7)] ?? null,
                ])
                ->values()
                ->all();

            $importer->import($account, $rows, Source::Bank, strict: true);
        }
    }

    protected function seedCostBaselines(): void
    {
        foreach ($this->load('cost_baselines') as $row) {
            $baseline = CostBaseline::query()->whereDate('effective_from', $row['effective_from'])->first() ?? new CostBaseline;

            $baseline->fill([
                'effective_from' => $row['effective_from'],
                'monthly_cost' => $row['monthly_cost'],
                'breakdown' => $row['breakdown'],
                'source' => Source::Vault,
                'external_key' => "cost-baseline:{$row['effective_from']}",
                'notes' => $row['notes'] ?? null,
                'vault_ref' => self::ANALYSIS_NOTE,
            ])->save();
        }
    }

    protected function seedPlannedCashFlows(): void
    {
        foreach ($this->load('forecast_reference')['one_offs'] as $index => $oneOff) {
            PlannedCashFlow::upsertFromSource(Source::Vault, "forecast-one-off:{$oneOff['month']}:{$index}", [
                'flow_on' => "{$oneOff['month']}-15",
                'amount' => $oneOff['amount'],
                'description' => $oneOff['label'],
                'vault_ref' => self::FORECAST_NOTE,
            ]);
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function load(string $name): array
    {
        return json_decode(file_get_contents(self::dataPath("{$name}.json")), true, flags: JSON_THROW_ON_ERROR);
    }
}
