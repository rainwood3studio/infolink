<?php

namespace App\Domain\Finance;

use App\Enums\Confidence;
use App\Enums\ReceivableStatus;
use App\Enums\Source;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Receivable;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Writes and aggregates receivables, plus the current cash position they are measured against.
 */
class ReceivableService
{
    /**
     * Create or update a receivable. With an external key the record is matched by source+key (idempotent);
     * without one, an `id` attribute updates that record and anything else creates a new one.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function upsert(array $attributes, Source $source = Source::Manual, ?string $externalKey = null): Receivable
    {
        if ($externalKey !== null) {
            return Receivable::upsertFromSource($source, $externalKey, $attributes);
        }

        if (isset($attributes['id'])) {
            $receivable = Receivable::query()->findOrFail($attributes['id']);
            $receivable->update($attributes);

            return $receivable;
        }

        return Receivable::query()->create([...$attributes, 'source' => $source]);
    }

    /**
     * Mark as received and, when given, link the bank transaction that paid it.
     */
    public function markReceived(Receivable $receivable, CarbonInterface $receivedOn, ?BankTransaction $transaction = null): Receivable
    {
        return DB::transaction(function () use ($receivable, $receivedOn, $transaction): Receivable {
            $receivable->update([
                'status' => ReceivableStatus::Received,
                'received_on' => $receivedOn,
            ]);

            if ($transaction !== null) {
                $transaction->receivable()->associate($receivable);
                $transaction->save();
            }

            return $receivable;
        });
    }

    /**
     * Record the invoice date. A receivable that is already received keeps its status.
     */
    public function markInvoiced(Receivable $receivable, CarbonInterface $invoicedOn): Receivable
    {
        $receivable->invoiced_on = $invoicedOn;

        if ($receivable->status === ReceivableStatus::Planned) {
            $receivable->status = ReceivableStatus::Invoiced;
        }

        $receivable->save();

        return $receivable;
    }

    /**
     * Tax-inclusive sums of outstanding (planned or invoiced) receivables.
     *
     * Recurring receivables (monthly maintenance fees) are excluded from the high/low/overdue AR figures and
     * reported separately under `recurring`, so the AR card only shows project money still to come in.
     *
     * @return array{high_taxed:int, low_taxed:int, overdue_taxed:int, recurring_taxed:int}
     */
    public function outstandingTotals(): array
    {
        $nonRecurring = fn () => Receivable::query()->outstanding()->where('is_recurring', false);

        return [
            'high_taxed' => (int) $nonRecurring()->where('confidence', Confidence::High)->sum('amount_taxed'),
            'low_taxed' => (int) $nonRecurring()->where('confidence', Confidence::Low)->sum('amount_taxed'),
            'overdue_taxed' => (int) $nonRecurring()->whereDate('expected_on', '<', today())->sum('amount_taxed'),
            'recurring_taxed' => (int) Receivable::query()->outstanding()->where('is_recurring', true)->sum('amount_taxed'),
        ];
    }

    /**
     * Total cash across all bank accounts: the sum of each account's latest balance (by txn_date, sequence, id)
     * on or before `$asOf`. Summing rather than taking only the primary account keeps the forecast correct
     * if a second account is ever added; with one account it is simply that account's balance.
     *
     * @return array{balance:int, as_of:CarbonImmutable}|null `as_of` is the latest transaction date used; null when there are no transactions.
     */
    public function currentCashBalance(?CarbonInterface $asOf = null): ?array
    {
        $balance = 0;
        $latestDate = null;

        foreach (BankAccount::query()->pluck('id') as $accountId) {
            $latest = BankTransaction::query()
                ->where('bank_account_id', $accountId)
                ->when($asOf !== null, fn ($query) => $query->whereDate('txn_date', '<=', $asOf))
                ->orderByDesc('txn_date')
                ->orderByDesc('sequence')
                ->orderByDesc('id')
                ->first();

            if ($latest === null) {
                continue;
            }

            $balance += $latest->balance;
            $date = CarbonImmutable::parse($latest->txn_date)->startOfDay();

            if ($latestDate === null || $date->gt($latestDate)) {
                $latestDate = $date;
            }
        }

        if ($latestDate === null) {
            return null;
        }

        return ['balance' => $balance, 'as_of' => $latestDate];
    }
}
