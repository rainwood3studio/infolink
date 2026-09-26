<?php

namespace App\Models;

use App\Enums\DealEventType;
use App\Enums\DealStage;
use App\Models\Concerns\HasSource;
use Database\Factories\DealEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in a deal's history: a meeting, a proposal sent, a stage change or a note.
 */
#[Fillable(['deal_id', 'occurred_on', 'type', 'content', 'from_stage', 'to_stage'])]
class DealEvent extends Model
{
    /** @use HasFactory<DealEventFactory> */
    use HasFactory, HasSource;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_on' => 'date',
            'type' => DealEventType::class,
            'from_stage' => DealStage::class,
            'to_stage' => DealStage::class,
        ];
    }

    /**
     * @return BelongsTo<Deal, $this>
     */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }
}
