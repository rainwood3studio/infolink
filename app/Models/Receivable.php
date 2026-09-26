<?php

namespace App\Models;

use App\Enums\Confidence;
use App\Enums\ReceivableStatus;
use App\Models\Concerns\HasSource;
use Database\Factories\ReceivableFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An expected payment from a customer. Overdue is derived from `expected_on`, never stored.
 */
#[Fillable(['customer_id', 'project_id', 'item', 'amount_untaxed', 'tax_rate', 'expected_on', 'confidence', 'status', 'invoiced_on', 'received_on', 'is_recurring'])]
class Receivable extends Model
{
    /** @use HasFactory<ReceivableFactory> */
    use HasFactory, HasSource;

    protected static function booted(): void
    {
        static::saving(function (Receivable $receivable): void {
            $receivable->amount_taxed = (int) round($receivable->amount_untaxed * (1 + (float) $receivable->tax_rate));
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_untaxed' => 'integer',
            'tax_rate' => 'decimal:4',
            'amount_taxed' => 'integer',
            'expected_on' => 'date',
            'confidence' => Confidence::class,
            'status' => ReceivableStatus::class,
            'invoiced_on' => 'date',
            'received_on' => 'date',
            'is_recurring' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<BankTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }

    /**
     * Planned or invoiced, i.e. money still to come in.
     *
     * @param  Builder<static>  $query
     */
    public function scopeOutstanding(Builder $query): void
    {
        $query->whereIn('status', [ReceivableStatus::Planned, ReceivableStatus::Invoiced]);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeOverdue(Builder $query): void
    {
        $query->outstanding()->whereDate('expected_on', '<', today());
    }

    /**
     * @return Attribute<bool, never>
     */
    protected function isOverdue(): Attribute
    {
        return Attribute::get(fn (): bool => in_array($this->status, [ReceivableStatus::Planned, ReceivableStatus::Invoiced], true)
            && $this->expected_on->lt(today()));
    }
}
