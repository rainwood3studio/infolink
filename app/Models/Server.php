<?php

namespace App\Models;

use App\Domain\Infra\ServerDiskCollector;
use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A machine managed by AWS Systems Manager, mirrored by {@see ServerDiskCollector}.
 * `is_active` turns false once SSM no longer lists it; `last_collected_at` marks its newest disk samples.
 */
#[Fillable([
    'instance_id', 'account', 'region', 'name', 'computer_name', 'platform', 'platform_name', 'instance_type',
    'ping_status', 'is_active', 'last_seen_at', 'last_collected_at', 'last_error',
])]
class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
            'last_collected_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<ServerDiskSample, $this>
     */
    public function diskSamples(): HasMany
    {
        return $this->hasMany(ServerDiskSample::class);
    }

    /**
     * The Name tag, else the host name, else the instance id.
     */
    public function label(): string
    {
        return $this->name ?: ($this->computer_name ?: $this->instance_id);
    }
}
