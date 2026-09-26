<?php

namespace App\Models;

use App\Enums\Category;
use App\Enums\MetricDirection;
use App\Enums\MetricUnit;
use App\Enums\PeriodType;
use Database\Factories\MetricDefinitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A dashboard metric. `description` is read by Claude, so it must define the metric precisely.
 */
#[Fillable(['key', 'name', 'category', 'unit', 'period_type', 'better', 'target', 'warn_threshold', 'critical_threshold', 'calculator', 'description', 'is_pinned', 'sort'])]
class MetricDefinition extends Model
{
    /** @use HasFactory<MetricDefinitionFactory> */
    use HasFactory;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => Category::class,
            'unit' => MetricUnit::class,
            'period_type' => PeriodType::class,
            'better' => MetricDirection::class,
            'target' => 'decimal:4',
            'warn_threshold' => 'decimal:4',
            'critical_threshold' => 'decimal:4',
            'is_pinned' => 'boolean',
        ];
    }

    /**
     * @return HasMany<MetricValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(MetricValue::class, 'metric_key');
    }
}
