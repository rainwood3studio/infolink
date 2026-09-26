<?php

use App\Domain\Finance\CashForecaster;
use App\Domain\Finance\TransactionImporter;
use App\Enums\ReceivableStatus;
use App\Enums\Source;
use App\Models\BankAccount;
use App\Models\CostBaseline;
use App\Models\Customer;
use App\Models\PlannedCashFlow;
use App\Models\Receivable;
use Carbon\CarbonImmutable;

/**
 * Reproduces the vault note 《2026 下半年現金推估》 from the real (gitignored) seed data when it is present.
 */
function financeSeedData(string $name): array
{
    return json_decode(file_get_contents(database_path("seeders/data/finance/{$name}.json")), true, flags: JSON_THROW_ON_ERROR);
}

it('reproduces the vault forecast from the real seed data', function () {
    $reference = financeSeedData('forecast_reference');

    foreach (financeSeedData('customers') as $customer) {
        Customer::factory()->create(['name' => $customer['name'], 'short_name' => $customer['short_name']]);
    }

    $customerIds = Customer::query()->pluck('id', 'short_name');

    foreach (financeSeedData('bank_accounts') as $accountData) {
        $account = BankAccount::factory()->create($accountData);
        $rows = collect(financeSeedData('bank_transactions'))
            ->where('account_name', $accountData['name'])
            ->map(fn (array $row): array => collect($row)->except(['account_name', 'receivable_ref'])->all())
            ->values()
            ->all();

        app(TransactionImporter::class)->import($account, $rows);
    }

    foreach (financeSeedData('cost_baselines') as $baseline) {
        CostBaseline::factory()->create(collect($baseline)->only(['effective_from', 'monthly_cost', 'breakdown'])->all());
    }

    foreach (financeSeedData('receivables') as $receivable) {
        Receivable::upsertFromSource(Source::Vault, $receivable['ref'], [
            ...collect($receivable)->except(['ref', 'customer_short_name', 'project_name'])->all(),
            'customer_id' => $customerIds[$receivable['customer_short_name']],
        ]);
    }

    $recurring = ['墊腳石' => 100_000, '我識' => 20_000];

    foreach (['2026-09', '2026-10', '2026-11', '2026-12'] as $month) {
        foreach ($recurring as $shortName => $amount) {
            $received = $month === '2026-09' && $shortName === '我識';

            Receivable::factory()->create([
                'customer_id' => $customerIds[$shortName],
                'item' => '維運費',
                'amount_untaxed' => $amount,
                'tax_rate' => 0,
                'expected_on' => CarbonImmutable::parse("{$month}-01")->endOfMonth()->toDateString(),
                'status' => $received ? ReceivableStatus::Received : ReceivableStatus::Planned,
                'is_recurring' => true,
            ]);
        }
    }

    foreach ($reference['one_offs'] as $oneOff) {
        PlannedCashFlow::factory()->create(['flow_on' => "{$oneOff['month']}-15", 'amount' => $oneOff['amount'], 'description' => $oneOff['label']]);
    }

    $result = app(CashForecaster::class)->calculate();

    expect($result->asOf->toDateString())->toBe($reference['as_of'])
        ->and($result->openingBalance)->toBe($reference['opening_balance'])
        ->and(collect($result->rows)->map(fn (array $row): array => collect($row)->only(['month', 'inflow', 'outflow', 'balance'])->all())->all())
        ->toBe(collect($reference['rows'])->map(fn (array $row): array => collect($row)->only(['month', 'inflow', 'outflow', 'balance'])->all())->all())
        ->and($result->yearEndBalance)->toBe(2_128_778)
        ->and($result->minBalance)->toBe($reference['min_balance'])
        ->and($result->minBalanceMonth)->toBe($reference['min_balance_month']);
})->skip(fn (): bool => ! file_exists(database_path('seeders/data/finance/forecast_reference.json')), 'Real finance seed data is not present (gitignored).');
