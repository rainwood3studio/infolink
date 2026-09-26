<?php

namespace App\Mcp\Tools;

use App\Domain\Finance\ContinuityBreak;
use App\Domain\Finance\Exceptions\BalanceContinuityException;
use App\Domain\Finance\ReceivableService;
use App\Domain\Finance\TransactionImporter;
use App\Enums\Source;
use App\Enums\TransactionCategory;
use App\Mcp\Tools\Concerns\WriteToolHelpers;
use App\Models\BankAccount;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('import_bank_transactions')]
#[Description('Import bank statement lines (parsed from a PDF or statement) in statement order. Idempotent: each line is keyed by a hash of account+date+summary+amounts+balance, so re-importing an overlapping statement only adds the new lines and never overwrites existing annotations (category/notes/one-off) unless overwrite_annotations=true. Checks that printed balances chain (previous balance − withdrawal + deposit = balance) and explains every break. strict=true rolls back the whole import on any break. Returns created/updated/skipped and the new cash balance. Amounts are whole NTD integers.')]
class ImportBankTransactions extends WriteTool
{
    use WriteToolHelpers;

    /**
     * Statement lines are facts from the bank, so they are keyed in the `bank` namespace no matter who transcribes
     * them: re-importing lines that a seeder or an earlier import already stored matches them instead of duplicating.
     * The `actor` column still records the token (e.g. claude-cli).
     */
    protected const Source IMPORT_SOURCE = Source::Bank;

    public function handle(Request $request, TransactionImporter $importer, ReceivableService $receivables): Response
    {
        if ($denied = $this->forbidden($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'account' => ['nullable', 'string'],
            'strict' => ['nullable', 'boolean'],
            'overwrite_annotations' => ['nullable', 'boolean'],
            'rows' => ['required', 'array', 'min:1', 'max:2000'],
            'rows.*' => ['array'],
            'rows.*.txn_date' => ['required', 'date_format:Y-m-d'],
            'rows.*.summary' => ['nullable', 'string', 'max:255'],
            'rows.*.withdrawal' => ['nullable', 'integer', 'min:0'],
            'rows.*.deposit' => ['nullable', 'integer', 'min:0'],
            'rows.*.balance' => ['required', 'integer'],
            'rows.*.counterparty' => ['nullable', 'string', 'max:255'],
            'rows.*.category' => ['nullable', Rule::in(self::enumValues(TransactionCategory::class))],
            'rows.*.is_one_off' => ['nullable', 'boolean'],
            'rows.*.notes' => ['nullable', 'string'],
        ], [
            'rows.required' => 'Pass `rows`: the statement lines in statement order.',
            'rows.*.txn_date.required' => 'Every row needs `txn_date` (YYYY-MM-DD).',
            'rows.*.txn_date.date_format' => ':attribute must be YYYY-MM-DD (convert ROC years: 115 → 2026).',
            'rows.*.balance.required' => 'Every row needs the printed `balance`.',
            'rows.*.*.integer' => ':attribute must be a whole NTD integer without thousands separators.',
            'rows.*.category.in' => ':attribute must be one of: '.implode(', ', self::enumValues(TransactionCategory::class)).'.',
        ]);

        $account = $this->resolveAccount($validated['account'] ?? null);

        if (is_string($account)) {
            return Response::error($account);
        }

        $rows = array_map(fn (array $row): array => array_filter(
            $row,
            fn (mixed $value, string $key): bool => $value !== null || in_array($key, ['summary', 'withdrawal', 'deposit'], true),
            ARRAY_FILTER_USE_BOTH,
        ), $validated['rows']);

        try {
            $result = $importer->import($account, $rows, self::IMPORT_SOURCE, (bool) ($validated['strict'] ?? false), (bool) ($validated['overwrite_annotations'] ?? false));
        } catch (BalanceContinuityException $exception) {
            return Response::error("Strict import rolled back; nothing was imported.\n".implode("\n", array_map($this->explain(...), $exception->breaks)));
        } catch (InvalidArgumentException $exception) {
            return Response::error($exception->getMessage());
        }

        $accountBalance = $account->transactions()->orderByDesc('txn_date')->orderByDesc('sequence')->orderByDesc('id')->first();
        $cash = $receivables->currentCashBalance();

        return Response::json([
            'account' => $account->name,
            'created' => $result->created,
            'updated' => $result->updated,
            'skipped' => $result->skipped,
            'balance_continuity' => $result->hasContinuityBreaks() ? 'broken' : 'ok',
            'continuity_breaks' => array_map(fn (ContinuityBreak $break): array => [
                ...$break->toArray(),
                'explanation' => $this->explain($break),
            ], $result->continuityBreaks),
            'account_balance' => $accountBalance === null ? null : [
                'as_of' => $accountBalance->txn_date->toDateString(),
                'balance' => $accountBalance->balance,
            ],
            'cash_balance' => $cash === null ? null : [
                'as_of' => $cash['as_of']->toDateString(),
                'balance' => $cash['balance'],
            ],
            'next_steps' => 'Link customer payments with record_receivable_payment, then save_cash_forecast.',
        ]);
    }

    /**
     * The account by name, or the primary one (or the only one) when no name is given. A string is an error message.
     */
    protected function resolveAccount(?string $name): BankAccount|string
    {
        $accounts = BankAccount::query()->orderByDesc('is_primary')->orderBy('id')->get();

        if ($accounts->isEmpty()) {
            return 'No bank account exists yet; create one in the admin panel (財務 → 銀行帳戶) first.';
        }

        if ($name === null || $name === '') {
            $account = $accounts->firstWhere('is_primary', true) ?? ($accounts->count() === 1 ? $accounts->first() : null);

            return $account ?? 'No primary bank account is set; pass `account` as one of: '.$accounts->pluck('name')->implode(', ').'.';
        }

        return $accounts->firstWhere('name', $name)
            ?? "Unknown bank account [{$name}]. Known accounts: ".$accounts->pluck('name')->implode(', ').'.';
    }

    protected function explain(ContinuityBreak $break): string
    {
        $position = $break->rowIndex === 0
            ? 'between the latest stored transaction and the first row (lines may be missing before this statement)'
            : 'between rows '.($break->rowIndex - 1).' and '.$break->rowIndex.' (a line may be missing, duplicated or mis-read)';

        return sprintf(
            'Row %d (%s %s): previous balance %d with this row\'s amounts should give %d, but the statement shows %d (difference %+d) — gap %s.',
            $break->rowIndex,
            $break->txnDate->toDateString(),
            $break->summary,
            $break->previousBalance,
            $break->expectedBalance,
            $break->actualBalance,
            $break->difference(),
            $position,
        );
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'account' => $schema->string()->description('Bank account name (e.g. 彰銀中壢). Defaults to the primary account.'),
            'strict' => $schema->boolean()->description('Roll back the whole import if any balance does not chain. Default false (import and report breaks).')->default(false),
            'overwrite_annotations' => $schema->boolean()->description('Lines already imported keep their existing counterparty/category/is_one_off/receivable/notes; your values only fill blanks. Set true ONLY when the user explicitly asks to re-classify existing lines.')->default(false),
            'rows' => $schema->array()
                ->items($schema->object([
                    'txn_date' => $schema->string()->format('date')->description('YYYY-MM-DD (Gregorian).')->required(),
                    'summary' => $schema->string()->description('Statement summary/memo text exactly as printed.'),
                    'withdrawal' => $schema->integer()->description('Withdrawal in NTD (0 or omitted if none).'),
                    'deposit' => $schema->integer()->description('Deposit in NTD (0 or omitted if none).'),
                    'balance' => $schema->integer()->description('Balance printed on the statement after this line.')->required(),
                    'counterparty' => $schema->string()->description('Counterparty name if known.'),
                    'category' => $schema->string()->enum(self::enumValues(TransactionCategory::class)),
                    'is_one_off' => $schema->boolean()->description('One-off spend (e.g. festival bonus); excluded from the recurring monthly cost.'),
                    'notes' => $schema->string(),
                ]))
                ->min(1)
                ->description('Statement lines in statement order (oldest first).')
                ->required(),
        ];
    }
}
