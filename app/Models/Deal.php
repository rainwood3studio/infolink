<?php

namespace App\Models;

use App\Enums\DealStage;
use App\Models\Concerns\HasSource;
use Carbon\CarbonInterface;
use Database\Factories\DealFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sales opportunity, for an existing customer or a prospect not yet in `customers`. Amounts are untaxed NTD.
 */
#[Fillable(['customer_id', 'prospect_name', 'title', 'stage', 'amount_untaxed', 'probability', 'recurring_monthly', 'expected_close_on', 'next_action', 'next_action_on', 'closed_at'])]
class Deal extends Model
{
    /** @use HasFactory<DealFactory> */
    use HasFactory, HasSource;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => DealStage::class,
            'amount_untaxed' => 'integer',
            'probability' => 'integer',
            'recurring_monthly' => 'integer',
            'expected_close_on' => 'date',
            'next_action_on' => 'date',
            'closed_at' => 'datetime',
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
     * @return HasMany<DealEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(DealEvent::class)->orderByDesc('occurred_on')->orderByDesc('id');
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('stage', DealStage::open());
    }

    /**
     * Open deals whose next action is missing or already past (`next_action_on` null or before `$today`).
     *
     * @param  Builder<static>  $query
     */
    public function scopeWithoutNextAction(Builder $query, ?CarbonInterface $today = null): void
    {
        $today ??= today();

        $query->open()->where(fn (Builder $query) => $query
            ->whereNull('next_action_on')
            ->orWhereDate('next_action_on', '<', $today->toDateString()));
    }

    /**
     * Customer short name, or the prospect name when not yet a customer.
     *
     * @return Attribute<string, never>
     */
    protected function partyName(): Attribute
    {
        return Attribute::get(fn (): string => $this->customer?->short_name ?? (string) $this->prospect_name);
    }

    /**
     * amount × probability, in untaxed NTD.
     *
     * @return Attribute<int, never>
     */
    protected function weightedAmount(): Attribute
    {
        return Attribute::get(fn (): int => (int) round(($this->amount_untaxed ?? 0) * $this->probability / 100));
    }
}
