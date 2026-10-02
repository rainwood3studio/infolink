<?php

namespace App\Models;

use Database\Factories\DeveloperFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * A person whose GitHub activity is reported. One developer can own several GitHub identities (logins/emails);
 * `redmine_name` is their Redmine display name, used to line commits up with Redmine assignments.
 */
#[Fillable(['name', 'redmine_name', 'is_active', 'notes'])]
class Developer extends Model
{
    /** @use HasFactory<DeveloperFactory> */
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
        ];
    }

    /**
     * @return HasMany<GithubIdentity, $this>
     */
    public function identities(): HasMany
    {
        return $this->hasMany(GithubIdentity::class);
    }

    /**
     * @return HasManyThrough<GithubCommit, GithubIdentity, $this>
     */
    public function commits(): HasManyThrough
    {
        return $this->hasManyThrough(GithubCommit::class, GithubIdentity::class);
    }
}
