<?php

use App\Domain\Finance\TransactionImporter;
use App\Enums\Source;
use App\Mcp\Servers\InfolinkServer;
use App\Mcp\Tools\ImportBankTransactions;
use App\Models\BankAccount;
use App\Models\BankTransaction;

beforeEach(function () {
    $this->account = BankAccount::factory()->create(['name' => '彰銀中壢', 'is_primary' => true]);
    BankAccount::factory()->create(['name' => '備用帳戶']);
});

/**
 * @return list<array<string, mixed>>
 */
function mcpWriteStatementRows(): array
{
    return [
        ['txn_date' => '2026-09-18', 'summary' => '跨行匯入', 'deposit' => 860000, 'balance' => 1200000, 'counterparty' => '我識', 'category' => 'revenue'],
        ['txn_date' => '2026-09-20', 'summary' => '薪資', 'withdrawal' => 161137, 'balance' => 1038863, 'category' => 'salary'],
        ['txn_date' => '2026-09-22', 'summary' => '跨行匯入', 'deposit' => 33913, 'balance' => 1072776],
    ];
}

test('import_bank_transactions imports into the primary account and returns the new cash balance', function () {
    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(ImportBankTransactions::class, ['rows' => mcpWriteStatementRows()])
        ->assertOk()
        ->assertSee(['"account":"彰銀中壢"', '"created":3', '"balance_continuity":"ok"', '"cash_balance":{"as_of":"2026-09-22","balance":1072776}']);

    $transaction = BankTransaction::query()->where('summary', '薪資')->sole();

    expect($transaction->bank_account_id)->toBe($this->account->id)
        ->and($transaction->source)->toBe(Source::Bank)
        ->and($transaction->actor)->toBe('claude-cli');
});

test('import_bank_transactions is idempotent, also against lines already imported by the bank importer', function () {
    app(TransactionImporter::class)->import($this->account, array_slice(mcpWriteStatementRows(), 0, 2), Source::Bank);

    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(ImportBankTransactions::class, ['rows' => mcpWriteStatementRows()])
        ->assertOk()
        ->assertSee(['"created":1', '"skipped":2']);

    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(ImportBankTransactions::class, ['account' => '彰銀中壢', 'rows' => mcpWriteStatementRows()])
        ->assertOk()
        ->assertSee(['"created":0', '"skipped":3']);

    expect(BankTransaction::query()->count())->toBe(3);
});

test('import_bank_transactions explains continuity breaks', function () {
    $rows = mcpWriteStatementRows();
    $rows[2]['balance'] = 1072000;

    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(ImportBankTransactions::class, ['rows' => $rows])
        ->assertOk()
        ->assertSee(['"balance_continuity":"broken"', 'should give 1072776, but the statement shows 1072000', 'between rows 1 and 2']);
});

test('a strict import with a break rolls back', function () {
    $rows = mcpWriteStatementRows();
    $rows[2]['balance'] = 1072000;

    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(ImportBankTransactions::class, ['rows' => $rows, 'strict' => true])
        ->assertHasErrors(['Strict import rolled back', 'difference -776']);

    expect(BankTransaction::query()->count())->toBe(0);
});

test('import_bank_transactions lists the accounts when the name is unknown', function () {
    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(ImportBankTransactions::class, ['account' => '台銀', 'rows' => mcpWriteStatementRows()])
        ->assertHasErrors(['Unknown bank account [台銀]', '彰銀中壢, 備用帳戶']);
});

test('import_bank_transactions rejects malformed rows', function () {
    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(ImportBankTransactions::class, ['rows' => [['txn_date' => '115/09/18', 'deposit' => '860,000', 'balance' => 1, 'category' => 'food']]])
        ->assertHasErrors(['YYYY-MM-DD', 'whole NTD integer', 'must be one of: revenue']);
});
