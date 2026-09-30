<?php

namespace App\Models;

use Database\Factories\ServerDiskSampleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One filesystem's usage on one server at one collection run (all rows of a run share `collected_at`).
 */
#[Fillable(['server_id', 'collected_at', 'mount', 'filesystem', 'fs_type', 'size_bytes', 'used_bytes', 'available_bytes', 'used_percent'])]
class ServerDiskSample extends Model
{
    /** @use HasFactory<ServerDiskSampleFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'collected_at' => 'datetime',
            'size_bytes' => 'integer',
            'used_bytes' => 'integer',
            'available_bytes' => 'integer',
            'used_percent' => 'float',
        ];
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
