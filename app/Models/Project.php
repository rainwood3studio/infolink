<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Models\Concerns\HasSource;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company-level engagement (not the same thing as a Redmine project).
 */
#[Fillable(['customer_id', 'name', 'contract_amount_untaxed', 'status', 'redmine_project_id', 'target_close_date'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, HasSource;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'contract_amount_untaxed' => 'integer',
            'status' => ProjectStatus::class,
            'target_close_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<Receivable, $this>
     */
    public function receivables(): HasMany
    {
        return $this->hasMany(Receivable::class);
    }
}
