<?php

use App\Enums\Confidence;
use App\Enums\ReceivableStatus;
use App\Enums\Source;
use App\Mcp\Servers\InfolinkServer;
use App\Mcp\Tools\RecordReceivablePayment;
use App\Mcp\Tools\UpsertReceivable;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Receivable;

beforeEach(function () {
    $this->customer = Customer::factory()->create(['short_name' => '長照']);
    $this->project = Project::factory()->for($this->customer)->create(['name' => '長照平台']);
    Customer::factory()->create(['short_name' => '我識']);
});

test('upsert_receivable creates a receivable and returns the taxed amount', function () {
    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(UpsertReceivable::class, [
            'customer' => '長照',
            'project' => '長照平台',
            'item' => '期中款',
            'amount_untaxed' => 450000,
            'expected_on' => '2026-09-30',
            'notes' => '合約第二期',
        ])
        ->assertOk()
        ->assertSee(['"result":"created"', '"amount_untaxed":450000', '"amount_taxed":472500', '"external_key":"長照|長照平台|期中款"']);

    $receivable = Receivable::query()->sole();

    expect($receivable->source)->toBe(Source::Claude)
        ->and($receivable->project_id)->toBe($this->project->id)
        ->and($receivable->confidence)->toBe(Confidence::High)
        ->and($receivable->notes)->toBe('合約第二期');
});

test('upsert_receivable is idempotent and updates the existing receivable', function () {
    $arguments = ['customer' => '長照', 'project' => '長照平台', 'item' => '期中款', 'amount_untaxed' => 450000, 'expected_on' => '2026-09-30'];

    InfolinkServer::actingAs(mcpUser(['write']))->tool(UpsertReceivable::class, $arguments)->assertOk();
    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(UpsertReceivable::class, [...$arguments, 'expected_on' => '2026-10-31', 'confidence' => 'low'])
        ->assertOk()
        ->assertSee(['"result":"updated"', '"expected_on":"2026-10-31"', '"confidence":"low"']);

    expect(Receivable::query()->count())->toBe(1);
});

test('upsert_receivable matches a receivable entered from another source by customer, project and item', function () {
    $seeded = Receivable::factory()->for($this->customer)->for($this->project)->create([
        'item' => '尾款', 'amount_untaxed' => 100000, 'source' => Source::Vault, 'external_key' => 'lc-final',
    ]);

    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(UpsertReceivable::class, ['customer' => '長照', 'project' => '長照平台', 'item' => '尾款', 'amount_untaxed' => 120000])
        ->assertOk()
        ->assertSee(['"result":"updated"', '"amount_taxed":126000']);

    expect(Receivable::query()->count())->toBe(1)
        ->and($seeded->refresh()->source)->toBe(Source::Vault);
});

test('recurring receivables are one per month', function () {
    foreach (['2026-10-31', '2026-11-30', '2026-11-30'] as $expectedOn) {
        InfolinkServer::actingAs(mcpUser(['write']))
            ->tool(UpsertReceivable::class, ['customer' => '我識', 'item' => '維運費', 'amount_untaxed' => 30000, 'tax_rate' => 0, 'expected_on' => $expectedOn, 'is_recurring' => true])
            ->assertOk();
    }

    expect(Receivable::query()->count())->toBe(2)
        ->and(Receivable::query()->pluck('external_key')->all())->toEqualCanonicalizing(['我識||維運費|2026-10', '我識||維運費|2026-11']);
});

test('upsert_receivable lists customers when the customer is unknown', function () {
    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(UpsertReceivable::class, ['customer' => '墊腳石', 'item' => '尾款', 'amount_untaxed' => 1, 'expected_on' => '2026-10-01'])
        ->assertHasErrors(['Unknown customer [墊腳石]', '我識, 長照']);
});

test('a new receivable needs an amount and an expected date', function () {
    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(UpsertReceivable::class, ['customer' => '長照', 'item' => '尾款'])
        ->assertHasErrors(['needs amount_untaxed']);
});

test('record_receivable_payment marks received, links the matching deposit and returns outstanding totals', function () {
    $receivable = Receivable::factory()->for($this->customer)->create(['amount_untaxed' => 450000, 'expected_on' => '2026-09-30']);
    Receivable::factory()->for($this->customer)->create(['amount_untaxed' => 100000, 'expected_on' => '2026-12-31']);
    $account = BankAccount::factory()->create();
    $deposit = BankTransaction::factory()->for($account)->create(['txn_date' => '2026-09-30', 'deposit' => 472500]);
    BankTransaction::factory()->for($account)->create(['txn_date' => '2026-09-30', 'deposit' => 1000]);

    $call = fn () => InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(RecordReceivablePayment::class, ['id' => $receivable->id, 'received_on' => '2026-09-30', 'transaction_date' => '2026-09-30']);

    $call()->assertOk()->assertSee(['"status":"received"', '"linked_transaction":{"id":'.$deposit->id, '"high_taxed":105000', '"warnings":[]']);
    $call()->assertOk()->assertSee('"status":"received"');

    expect($receivable->refresh()->status)->toBe(ReceivableStatus::Received)
        ->and($receivable->received_on->toDateString())->toBe('2026-09-30')
        ->and($deposit->refresh()->receivable_id)->toBe($receivable->id)
        ->and(BankTransaction::query()->whereNotNull('receivable_id')->count())->toBe(1);
});

test('record_receivable_payment does not steal a deposit linked to another receivable', function () {
    $first = Receivable::factory()->for($this->customer)->create(['amount_untaxed' => 100000]);
    $second = Receivable::factory()->for($this->customer)->create(['amount_untaxed' => 100000]);
    $deposit = BankTransaction::factory()->create(['deposit' => 210000, 'receivable_id' => $first->id]);

    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(RecordReceivablePayment::class, ['id' => $second->id, 'received_on' => '2026-09-30', 'transaction_id' => $deposit->id])
        ->assertOk()
        ->assertSee('already linked to receivable '.$first->id);

    expect($deposit->refresh()->receivable_id)->toBe($first->id)
        ->and($second->refresh()->status)->toBe(ReceivableStatus::Received);
});

test('record_receivable_payment explains when no deposit matches', function () {
    $receivable = Receivable::factory()->for($this->customer)->create(['amount_untaxed' => 450000, 'external_key' => 'lc-mid', 'source' => Source::Vault]);
    BankTransaction::factory()->create(['txn_date' => '2026-09-30', 'summary' => '跨行匯入', 'deposit' => 400000]);

    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(RecordReceivablePayment::class, ['external_key' => 'lc-mid', 'received_on' => '2026-09-30', 'transaction_date' => '2026-09-30'])
        ->assertHasErrors(['No deposit of 472500 on 2026-09-30', '跨行匯入 400000']);

    expect($receivable->refresh()->status)->toBe(ReceivableStatus::Planned);
});
