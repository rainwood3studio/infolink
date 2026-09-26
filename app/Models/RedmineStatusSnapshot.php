<?php

namespace App\Models;

use Database\Factories\RedmineStatusSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Daily count of open issues per project / status / assignee. Redmine has no historical state query, so this table
 * is the only source of trends. `is_reconstructed` rows were approximated from created_on / closed_on.
 */
#[Fillable(['snapshot_date', 'project_identifier', 'status', 'assignee_name', 'count', 'stalled_30d', 'stalled_90d', 'overdue', 'is_reconstructed'])]
class RedmineStatusSnapshot extends Model
{
    /** @use HasFactory<RedmineStatusSnapshotFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'count' => 'integer',
            'stalled_30d' => 'integer',
            'stalled_90d' => 'integer',
            'overdue' => 'integer',
            'is_reconstructed' => 'boolean',
        ];
    }
}
