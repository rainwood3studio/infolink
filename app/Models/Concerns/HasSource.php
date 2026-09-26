<?php

namespace App\Models\Concerns;

use App\Enums\Source;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Write-traceability columns shared by every business table (see docs/02-data-model.md).
 *
 * Pair with the `$table->sourceColumns()` Blueprint macro in migrations.
 *
 * @property Source $source
 * @property string|null $external_key
 * @property string $actor
 * @property string|null $vault_ref
 * @property string|null $notes
 */
trait HasSource
{
    public const string SYSTEM_ACTOR = 'system';

    public static function bootHasSource(): void
    {
        static::creating(function (Model $model): void {
            $model->source ??= Source::Manual;
        });

        static::saving(function (Model $model): void {
            if (! $model->isDirty('actor')) {
                $model->actor = static::resolveActor();
            }
        });
    }

    public function initializeHasSource(): void
    {
        $this->mergeFillable(['source', 'external_key', 'actor', 'vault_ref', 'notes']);
        $this->mergeCasts(['source' => Source::class]);
    }

    /**
     * Create or update the record identified by its origin, so re-running an import never duplicates rows.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function upsertFromSource(Source $source, string $externalKey, array $attributes): static
    {
        return static::query()->updateOrCreate(
            ['source' => $source, 'external_key' => $externalKey],
            $attributes,
        );
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeFromSource(Builder $query, Source $source): void
    {
        $query->where('source', $source);
    }

    /**
     * The API token name (e.g. `claude-cli`) when called with a token, the user's name for a panel session,
     * otherwise `system` for scheduled jobs and console commands.
     */
    public static function resolveActor(): string
    {
        $user = auth()->user();

        if ($user === null) {
            return self::SYSTEM_ACTOR;
        }

        $token = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;

        return $token instanceof PersonalAccessToken ? $token->name : $user->name;
    }
}
