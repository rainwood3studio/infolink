<?php

use App\Domain\Finance\ReceivableService;
use App\Enums\Confidence;
use App\Enums\ReceivableStatus;
use App\Enums\Source;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Customer;
use App\Models\Receivable;

beforeEach(function () {
    $this->travelTo('2026-09-26');
    $this->service = app(ReceivableService::class);
});

it('upserts receivables idempotently by external key', function () {
    $customer = Customer::factory()->create();
    $attributes = ['customer_id' => $customer->id, 'item' => '尾款', 'amount_untaxed' => 100_000, 'expected_on' => '2026-10-31'];

    $first = $this->service->upsert($attributes, Source::Vault, 'r-1');
    $second = $this->service->upsert([...$attributes, 'amount_untaxed' => 200_000], Source::Vault, 'r-1');

    expect($second->id)->toBe($first->id)
        ->and(Receivable::count())->toBe(1)
        ->and($second->amount_taxed)->toBe(210_000)
        ->and($second->source)->toBe(Source::Vault);
});

it('creates a manual receivable without an external key', function () {
    $receivable = $this->service->upsert([
        'customer_id' => Customer::factory()->create()->id,
        'item' => '期中款',
        'amount_untaxed' => 50_000,
        'expected_on' => '2026-10-15',
    ]);

    expect($receivable->source)->toBe(Source::Manual)
        ->and($receivable->external_key)->toBeNull();
});

it('marks a receivable received, links the transaction and drops it from outstanding totals', function () {
    $receivable = Receivable::factory()->create(['amount_untaxed' => 100_000, 'expected_on' => '2026-10-31']);
    Receivable::factory()->create(['amount_untaxed' => 200_000, 'expected_on' => '2026-10-31']);
    $transaction = BankTransaction::factory()->for(BankAccount::factory())->create(['deposit' => 105_000]);

    expect($this->service->outstandingTotals()['high_taxed'])->toBe(315_000);

    $this->service->markReceived($receivable, now()->toImmutable(), $transaction);

    expect($receivable->fresh()->status)->toBe(ReceivableStatus::Received)
        ->and($receivable->fresh()->received_on->toDateString())->toBe('2026-09-26')
        ->and($transaction->fresh()->receivable_id)->toBe($receivable->id)
        ->and($this->service->outstandingTotals()['high_taxed'])->toBe(210_000);
});

it('marks a receivable invoiced', function () {
    $receivable = Receivable::factory()->create();

    $this->service->markInvoiced($receivable, now()->toImmutable());

    expect($receivable->fresh()->status)->toBe(ReceivableStatus::Invoiced)
        ->and($receivable->fresh()->invoiced_on->toDateString())->toBe('2026-09-26');
});

it('splits outstanding totals by confidence, overdue and recurring', function () {
    Receivable::factory()->create(['amount_untaxed' => 100_000, 'expected_on' => '2026-09-20']);
    Receivable::factory()->create(['amount_untaxed' => 200_000, 'expected_on' => '2026-10-31', 'status' => ReceivableStatus::Invoiced]);
    Receivable::factory()->create(['amount_untaxed' => 300_000, 'expected_on' => '2026-08-31', 'confidence' => Confidence::Low]);
    Receivable::factory()->create(['amount_untaxed' => 400_000, 'expected_on' => '2026-08-31', 'status' => ReceivableStatus::Received]);
    Receivable::factory()->create(['amount_untaxed' => 500_000, 'expected_on' => '2026-08-31', 'status' => ReceivableStatus::Cancelled]);
    Receivable::factory()->create(['amount_untaxed' => 20_000, 'tax_rate' => 0, 'expected_on' => '2026-09-01', 'is_recurring' => true]);

    expect($this->service->outstandingTotals())->toBe([
        'high_taxed' => 315_000,
        'low_taxed' => 315_000,
        'overdue_taxed' => 420_000,
        'recurring_taxed' => 20_000,
    ]);
});

it('returns the summed latest balance across accounts', function () {
    expect($this->service->currentCashBalance())->toBeNull();

    $primary = BankAccount::factory()->create(['is_primary' => true]);
    $secondary = BankAccount::factory()->create();

    BankTransaction::factory()->for($primary)->create(['txn_date' => '2026-09-22', 'sequence' => 0, 'balance' => 1_000]);
    BankTransaction::factory()->for($primary)->create(['txn_date' => '2026-09-22', 'sequence' => 1, 'balance' => 900]);
    BankTransaction::factory()->for($primary)->create(['txn_date' => '2026-09-10', 'sequence' => 5, 'balance' => 5_000]);
    BankTransaction::factory()->for($secondary)->create(['txn_date' => '2026-09-15', 'balance' => 100]);

    $cash = $this->service->currentCashBalance();

    expect($cash['balance'])->toBe(1_000)
        ->and($cash['as_of']->toDateString())->toBe('2026-09-22')
        ->and($this->service->currentCashBalance(now()->setDate(2026, 9, 12))['balance'])->toBe(5_000);
});
