<?php

namespace App\Models;

use Database\Factories\GithubIdentityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A commit/PR author as GitHub reports it: a login when the email is linked to an account, otherwise just the
 * git email. `key` is `login:<login>` or `email:<lowercased email>`. Mapped to a Developer by hand.
 */
#[Fillable(['key', 'developer_id', 'login', 'email', 'name', 'first_seen_at', 'last_seen_at'])]
class GithubIdentity extends Model
{
    /** @use HasFactory<GithubIdentityFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public static function keyFor(?string $login, ?string $email): string
    {
        return filled($login) ? 'login:'.$login : 'email:'.mb_strtolower((string) $email);
    }

    /**
     * @return BelongsTo<Developer, $this>
     */
    public function developer(): BelongsTo
    {
        return $this->belongsTo(Developer::class);
    }

    /**
     * @return HasMany<GithubCommit, $this>
     */
    public function commits(): HasMany
    {
        return $this->hasMany(GithubCommit::class);
    }

    /**
     * The login, else the git name, else the email.
     */
    public function label(): string
    {
        return $this->login ?: ($this->name ?: (string) $this->email);
    }
}
