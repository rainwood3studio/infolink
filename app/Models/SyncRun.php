<?php

namespace App\Models;

use App\Enums\SyncJob;
use App\Enums\SyncStatus;
use Database\Factories\SyncRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One execution of a background sync/snapshot job; the dashboard's data-freshness bar reads the latest per job.
 */
#[Fillable(['job', 'started_at', 'finished_at', 'status', 'stats', 'error'])]
class SyncRun extends Model
{
    /** @use HasFactory<SyncRunFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'job' => SyncJob::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'status' => SyncStatus::class,
            'stats' => 'array',
        ];
    }

    public static function latestFor(SyncJob $job, ?SyncStatus $status = null): ?self
    {
        return static::query()
            ->where('job', $job)
            ->when($status, fn ($query) => $query->where('status', $status))
            ->latest('started_at')
            ->latest('id')
            ->first();
    }
}
