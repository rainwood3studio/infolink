<?php

use App\Domain\Finance\Exceptions\BalanceContinuityException;
use App\Domain\Finance\TransactionImporter;
use App\Enums\Source;
use App\Enums\TransactionCategory;
use App\Models\BankAccount;
use App\Models\BankTransaction;

/**
 * @return list<array<string, mixed>>
 */
function statementRows(): array
{
    return [
        ['txn_date' => '2026-09-01', 'summary' => '勞保費', 'withdrawal' => 10_000, 'deposit' => 0, 'balance' => 490_000, 'category' => 'insurance'],
        ['txn_date' => '2026-09-04', 'summary' => '薪水', 'withdrawal' => 150_000, 'deposit' => 0, 'balance' => 340_000, 'category' => 'salary'],
        ['txn_date' => '2026-09-18', 'summary' => '匯款 客戶', 'withdrawal' => 0, 'deposit' => '210,000', 'balance' => '550,000', 'counterparty' => '客戶'],
    ];
}

beforeEach(function () {
    $this->account = BankAccount::factory()->create(['is_primary' => true]);
    $this->importer = app(TransactionImporter::class);
});

it('imports statement lines idempotently', function () {
    $first = $this->importer->import($this->account, statementRows());
    $second = $this->importer->import($this->account, statementRows());

    expect($first->created)->toBe(3)
        ->and($first->continuityBreaks)->toBe([])
        ->and($second->created)->toBe(0)
        ->and($second->skipped)->toBe(3)
        ->and(BankTransaction::count())->toBe(3);

    $deposit = BankTransaction::query()->where('summary', '匯款 客戶')->sole();

    expect($deposit->source)->toBe(Source::Bank)
        ->and($deposit->deposit)->toBe(210_000)
        ->and($deposit->balance)->toBe(550_000)
        ->and($deposit->external_key)->toBe(sha1("{$this->account->id}|2026-09-18|匯款 客戶|0|210000|550000"));
});

it('counts changed optional fields as updates without overwriting absent ones', function () {
    $this->importer->import($this->account, statementRows());

    $rows = statementRows();
    $rows[2]['category'] = TransactionCategory::Revenue;
    unset($rows[0]['category']);

    $result = $this->importer->import($this->account, $rows);

    expect($result->updated)->toBe(1)
        ->and($result->skipped)->toBe(2)
        ->and(BankTransaction::query()->where('summary', '勞保費')->sole()->category)->toBe(TransactionCategory::Insurance)
        ->and(BankTransaction::query()->where('summary', '匯款 客戶')->sole()->category)->toBe(TransactionCategory::Revenue);
});

it('keeps identical lines on the same day as separate transactions with deterministic sequence', function () {
    $rows = [
        ['txn_date' => '2026-09-01', 'summary' => '手續費', 'withdrawal' => 15, 'deposit' => 0, 'balance' => 99_985],
        ['txn_date' => '2026-09-01', 'summary' => '手續費', 'withdrawal' => 15, 'deposit' => 0, 'balance' => 99_985],
    ];

    $result = $this->importer->import($this->account, $rows);
    $again = $this->importer->import($this->account, $rows);

    $transactions = BankTransaction::query()->orderBy('sequence')->get();

    expect($result->created)->toBe(2)
        ->and($again->created)->toBe(0)
        ->and($transactions)->toHaveCount(2)
        ->and($transactions->pluck('sequence')->all())->toBe([0, 1])
        ->and($transactions[0]->external_key)->not->toBe($transactions[1]->external_key);
});

it('appends later same-day lines after the existing sequence', function () {
    $this->importer->import($this->account, [
        ['txn_date' => '2026-09-01', 'summary' => 'A', 'withdrawal' => 100, 'deposit' => 0, 'balance' => 900],
    ]);

    $this->importer->import($this->account, [
        ['txn_date' => '2026-09-01', 'summary' => 'B', 'withdrawal' => 100, 'deposit' => 0, 'balance' => 800],
    ]);

    expect(BankTransaction::query()->where('summary', 'B')->sole()->sequence)->toBe(1);
});

it('reports balance continuity breaks within the batch and against stored history', function () {
    BankTransaction::factory()->for($this->account)->create([
        'txn_date' => '2026-08-31',
        'balance' => 500_000,
    ]);

    $rows = statementRows();
    $rows[0]['balance'] = 489_000;

    $result = $this->importer->import($this->account, $rows);

    expect($result->created)->toBe(3)
        ->and($result->continuityBreaks)->toHaveCount(2)
        ->and($result->continuityBreaks[0]->rowIndex)->toBe(0)
        ->and($result->continuityBreaks[0]->previousBalance)->toBe(500_000)
        ->and($result->continuityBreaks[0]->expectedBalance)->toBe(490_000)
        ->and($result->continuityBreaks[0]->difference())->toBe(-1_000)
        ->and($result->continuityBreaks[1]->rowIndex)->toBe(1)
        ->and($result->continuityBreaks[1]->expectedBalance)->toBe(339_000);
});

it('does not flag breaks when chaining from the stored balance', function () {
    BankTransaction::factory()->for($this->account)->create([
        'txn_date' => '2026-08-31',
        'balance' => 500_000,
    ]);

    expect($this->importer->import($this->account, statementRows())->hasContinuityBreaks())->toBeFalse();
});

it('throws and rolls back in strict mode when balances do not chain', function () {
    $rows = statementRows();
    $rows[2]['balance'] = 1;

    expect(fn () => $this->importer->import($this->account, $rows, strict: true))
        ->toThrow(BalanceContinuityException::class);

    expect(BankTransaction::count())->toBe(0);
});

it('fills blank annotations on re-import but keeps curated ones', function () {
    $rows = statementRows();
    $this->importer->import($this->account, $rows);
    $line = BankTransaction::query()->orderBy('id')->first();
    $line->update(['category' => TransactionCategory::Subscription, 'notes' => '固定服務費（已確認）']);

    $rows[0] = [...$rows[0], 'category' => 'other', 'notes' => '固定服務費', 'counterparty' => '中華電信'];
    $result = $this->importer->import($this->account, $rows);

    expect($line->fresh())
        ->category->toBe(TransactionCategory::Subscription)
        ->notes->toBe('固定服務費（已確認）')
        ->counterparty->toBe('中華電信')
        ->and($result->updated)->toBe(1);
});

it('replaces curated annotations only when asked to', function () {
    $rows = statementRows();
    $this->importer->import($this->account, $rows);
    $line = BankTransaction::query()->orderBy('id')->first();
    $line->update(['notes' => '舊備註']);

    $rows[0] = [...$rows[0], 'notes' => '新備註'];
    $this->importer->import($this->account, $rows, overwriteAnnotations: true);

    expect($line->fresh()->notes)->toBe('新備註');
});
