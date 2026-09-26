<?php

namespace App\Mcp\Tools;

use App\Domain\Finance\ReceivableService;
use App\Enums\ReceivableStatus;
use App\Mcp\Tools\Concerns\WriteToolHelpers;
use App\Models\BankTransaction;
use App\Models\Receivable;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('record_receivable_payment')]
#[Description('Mark a receivable as received and link the bank deposit that paid it. Identify the receivable by `id` or `external_key` (see list_receivables). The deposit is found by `transaction_id`, or by `transaction_date` + `transaction_deposit` (the deposit defaults to the receivable\'s TAXED amount). Idempotent: repeating the call changes nothing. Returns the receivable and the updated outstanding totals (taxed).')]
class RecordReceivablePayment extends WriteTool
{
    use WriteToolHelpers;

    public function handle(Request $request, ReceivableService $receivables): Response
    {
        if ($denied = $this->forbidden($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'id' => ['nullable', 'integer', 'required_without:external_key'],
            'external_key' => ['nullable', 'string', 'required_without:id'],
            'received_on' => ['required', 'date_format:Y-m-d'],
            'transaction_id' => ['nullable', 'integer'],
            'transaction_date' => ['nullable', 'date_format:Y-m-d'],
            'transaction_deposit' => ['nullable', 'integer', 'min:1'],
        ], [
            'id.required_without' => 'Identify the receivable with `id` or `external_key` (see list_receivables).',
            'external_key.required_without' => 'Identify the receivable with `id` or `external_key` (see list_receivables).',
            'received_on.required' => 'Pass `received_on` (YYYY-MM-DD), normally the deposit date.',
        ]);

        $receivable = $this->findReceivable($validated);

        if (is_string($receivable)) {
            return Response::error($receivable);
        }

        if ($receivable->status === ReceivableStatus::Cancelled) {
            return Response::error("Receivable {$receivable->id} is cancelled; reinstate it with upsert_receivable (status planned) first if it was actually paid.");
        }

        $transaction = $this->findTransaction($validated, $receivable);

        if (is_string($transaction)) {
            return Response::error($transaction);
        }

        $warnings = [];

        if ($transaction !== null && $transaction->receivable_id !== null && $transaction->receivable_id !== $receivable->id) {
            $warnings[] = "Transaction {$transaction->id} is already linked to receivable {$transaction->receivable_id}; kept that link (one deposit can pay several receivables). Marked received without relinking.";
            $transaction = null;
        }

        if ($transaction !== null && $transaction->deposit !== $receivable->amount_taxed) {
            $warnings[] = "Deposit {$transaction->deposit} differs from the receivable's taxed amount {$receivable->amount_taxed} (difference ".($transaction->deposit - $receivable->amount_taxed).'). Check for withholding, bank fees or a combined payment.';
        }

        $receivables->markReceived($receivable, CarbonImmutable::parse($validated['received_on']), $transaction);

        return Response::json([
            'receivable' => self::presentReceivable($receivable->refresh()),
            'linked_transaction' => $transaction === null ? null : [
                'id' => $transaction->id,
                'txn_date' => $transaction->txn_date->toDateString(),
                'summary' => $transaction->summary,
                'deposit' => $transaction->deposit,
            ],
            'outstanding_totals_taxed' => $receivables->outstandingTotals(),
            'warnings' => $warnings,
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    protected function findReceivable(array $validated): Receivable|string
    {
        if (isset($validated['id'])) {
            return Receivable::query()->find($validated['id']) ?? "No receivable with id {$validated['id']}.";
        }

        $matches = Receivable::query()->where('external_key', $validated['external_key'])->get();

        return match ($matches->count()) {
            1 => $matches->first(),
            0 => "No receivable with external_key [{$validated['external_key']}]; use list_receivables to find its id.",
            default => "external_key [{$validated['external_key']}] matches several receivables (ids ".$matches->pluck('id')->implode(', ').'); pass `id`.',
        };
    }

    /**
     * The bank transaction to link, null when none was asked for, or an error message.
     *
     * @param  array<string, mixed>  $validated
     */
    protected function findTransaction(array $validated, Receivable $receivable): BankTransaction|string|null
    {
        if (isset($validated['transaction_id'])) {
            return BankTransaction::query()->find($validated['transaction_id']) ?? "No bank transaction with id {$validated['transaction_id']}.";
        }

        if (! isset($validated['transaction_date'])) {
            return null;
        }

        $deposit = $validated['transaction_deposit'] ?? $receivable->amount_taxed;

        $candidates = BankTransaction::query()
            ->whereDate('txn_date', $validated['transaction_date'])
            ->where('deposit', $deposit)
            ->orderBy('sequence')
            ->get();

        $preferred = $candidates->filter(fn (BankTransaction $transaction): bool => in_array($transaction->receivable_id, [null, $receivable->id], true));

        if ($preferred->count() === 1 || ($preferred->isEmpty() && $candidates->count() === 1)) {
            return $preferred->first() ?? $candidates->first();
        }

        if ($candidates->count() > 1) {
            return "Several deposits of {$deposit} on {$validated['transaction_date']} (ids ".$candidates->pluck('id')->implode(', ').'); pass `transaction_id`.';
        }

        $deposits = BankTransaction::query()
            ->whereDate('txn_date', $validated['transaction_date'])
            ->where('deposit', '>', 0)
            ->get()
            ->map(fn (BankTransaction $transaction): string => "#{$transaction->id} {$transaction->summary} {$transaction->deposit}")
            ->implode('; ');

        return "No deposit of {$deposit} on {$validated['transaction_date']}. Deposits that day: ".($deposits ?: 'none (is the statement imported? use import_bank_transactions)').'. Pass transaction_deposit or transaction_id.';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Receivable id (from list_receivables).'),
            'external_key' => $schema->string()->description('Receivable external_key, instead of id.'),
            'received_on' => $schema->string()->format('date')->description('Date the money arrived, YYYY-MM-DD.')->required(),
            'transaction_id' => $schema->integer()->description('Bank transaction id to link.'),
            'transaction_date' => $schema->string()->format('date')->description('Find the deposit on this date instead of transaction_id.'),
            'transaction_deposit' => $schema->integer()->description('Deposit amount to match (NTD); defaults to the receivable\'s taxed amount.'),
        ];
    }
}
