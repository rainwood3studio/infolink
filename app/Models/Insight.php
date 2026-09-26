<?php

namespace App\Models;

use App\Enums\Category;
use App\Enums\InsightKind;
use App\Enums\InsightSeverity;
use App\Enums\InsightStatus;
use App\Models\Concerns\HasSource;
use App\Observers\InsightObserver;
use Database\Factories\InsightFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Something worth knowing (risk, anomaly, reminder...). Deduplicated by `fingerprint` while unresolved.
 */
#[ObservedBy(InsightObserver::class)]
#[Fillable(['kind', 'severity', 'category', 'title', 'body', 'evidence', 'fingerprint', 'status', 'first_seen_at', 'last_seen_at', 'resolved_at', 'notified_at', 'expires_at'])]
class Insight extends Model
{
    /** @use HasFactory<InsightFactory> */
    use HasFactory, HasSource;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => InsightKind::class,
            'severity' => InsightSeverity::class,
            'category' => Category::class,
            'evidence' => 'array',
            'status' => InsightStatus::class,
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
            'notified_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return MorphMany<ActionItem, $this>
     */
    public function actionItems(): MorphMany
    {
        return $this->morphMany(ActionItem::class, 'related');
    }

    /**
     * Open or acknowledged, i.e. not yet dealt with.
     *
     * @param  Builder<static>  $query
     */
    public function scopeUnresolved(Builder $query): void
    {
        $query->whereIn('status', [InsightStatus::Open, InsightStatus::Acknowledged]);
    }
}
