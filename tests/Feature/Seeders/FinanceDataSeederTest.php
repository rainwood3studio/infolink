<?php

use App\Domain\Finance\CashForecaster;
use App\Domain\Finance\ReceivableService;
use App\Enums\ProjectStatus;
use App\Enums\ReceivableStatus;
use App\Enums\Source;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\CostBaseline;
use App\Models\Customer;
use App\Models\PlannedCashFlow;
use App\Models\Project;
use App\Models\Receivable;
use Carbon\CarbonImmutable;
use Database\Seeders\FinanceDataSeeder;

/**
 * @return array<string, int>
 */
function financeRecordCounts(): array
{
    return [
        'customers' => Customer::query()->count(),
        'projects' => Project::query()->count(),
        'receivables' => Receivable::query()->count(),
        'bank_accounts' => BankAccount::query()->count(),
        'bank_transactions' => BankTransaction::query()->count(),
        'cost_baselines' => CostBaseline::query()->count(),
        'planned_cash_flows' => PlannedCashFlow::query()->count(),
    ];
}

it('seeds the real finance data idempotently and reproduces the vault forecast', function () {
    $this->seed(FinanceDataSeeder::class);
    $firstRun = financeRecordCounts();

    $this->seed(FinanceDataSeeder::class);

    expect(financeRecordCounts())->toBe($firstRun)
        ->and($firstRun)->toMatchArray(['customers' => 11, 'projects' => 12, 'receivables' => 17, 'bank_accounts' => 1, 'bank_transactions' => 34, 'cost_baselines' => 1, 'planned_cash_flows' => 1])
        ->and(Project::query()->where('status', ProjectStatus::Closing)->count())->toBe(4)
        ->and(Receivable::query()->where('source', '!=', Source::Vault)->count())->toBe(0)
        ->and(Receivable::query()->where('source', Source::Vault)->where('external_key', 'woshi-midterm')->first())
        ->status->toBe(ReceivableStatus::Received)
        ->received_on->toDateString()->toBe('2026-09-18')
        ->and(BankTransaction::query()->whereNotNull('receivable_id')->count())->toBe(2);

    $service = app(ReceivableService::class);
    $totals = $service->outstandingTotals();

    expect($service->currentCashBalance()['balance'])->toBe(1_072_776)
        ->and($totals['high_taxed'])->toBe(1_365_000)
        ->and($totals['low_taxed'])->toBe(577_500)
        ->and(app(CashForecaster::class)->calculate(CarbonImmutable::parse('2026-09-22'))->yearEndBalance)->toBe(2_128_778);
})->skip(fn (): bool => ! FinanceDataSeeder::dataIsAvailable(), 'Real finance seed data is not present (gitignored).');
