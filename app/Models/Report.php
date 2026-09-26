<?php

namespace App\Models;

use App\Enums\ReportType;
use App\Models\Concerns\HasSource;
use App\Observers\ReportObserver;
use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A narrative report (Markdown) with the metric values it cited. One per type and period unless ad hoc.
 */
#[ObservedBy(ReportObserver::class)]
#[Fillable(['type', 'period_start', 'period_end', 'title', 'body', 'metrics_snapshot', 'notify', 'notified_at'])]
class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use HasFactory, HasSource;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ReportType::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'metrics_snapshot' => 'array',
            'notify' => 'boolean',
            'notified_at' => 'datetime',
        ];
    }
}
