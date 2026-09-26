<?php

namespace App\Models;

use App\Enums\Category;
use App\Enums\InsightSeverity;
use Database\Factories\AlertRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A deterministic alert: either a metric compared to a threshold (`metric_key` + `operator` + `threshold`) or a
 * rule class (`query_class`) with `params`. Firing raises an insight keyed by `fingerprint_template`.
 */
#[Fillable(['key', 'name', 'description', 'metric_key', 'query_class', 'operator', 'threshold', 'critical_threshold', 'severity', 'category', 'title_template', 'fingerprint_template', 'params', 'notify_channels', 'is_active', 'last_evaluated_at'])]
class AlertRule extends Model
{
    /** @use HasFactory<AlertRuleFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'threshold' => 'decimal:4',
            'critical_threshold' => 'decimal:4',
            'severity' => InsightSeverity::class,
            'category' => Category::class,
            'params' => 'array',
            'notify_channels' => 'array',
            'is_active' => 'boolean',
            'last_evaluated_at' => 'datetime',
        ];
    }
}
