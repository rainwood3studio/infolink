<?php

namespace App\Models;

use App\Models\Concerns\HasSource;
use Database\Factories\MetricValueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['metric_key', 'period_start', 'dimension', 'value'])]
class MetricValue extends Model
{
    /** @use HasFactory<MetricValueFactory> */
    use HasFactory, HasSource;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'value' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<MetricDefinition, $this>
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(MetricDefinition::class, 'metric_key');
    }
}
