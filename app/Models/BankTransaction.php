<?php

namespace App\Models;

use App\Enums\TransactionCategory;
use App\Models\Concerns\HasSource;
use Database\Factories\BankTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a bank statement. `balance` is as printed by the bank and is used to check continuity on import.
 */
#[Fillable(['bank_account_id', 'txn_date', 'sequence', 'summary', 'counterparty', 'withdrawal', 'deposit', 'balance', 'category', 'is_one_off', 'receivable_id'])]
class BankTransaction extends Model
{
    /** @use HasFactory<BankTransactionFactory> */
    use HasFactory, HasSource;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'txn_date' => 'date',
            'withdrawal' => 'integer',
            'deposit' => 'integer',
            'balance' => 'integer',
            'category' => TransactionCategory::class,
            'is_one_off' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<BankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /**
     * @return BelongsTo<Receivable, $this>
     */
    public function receivable(): BelongsTo
    {
        return $this->belongsTo(Receivable::class);
    }
}
