<?php

namespace App\Models;

use App\Enums\CustomerStatus;
use App\Enums\MaintenanceCycle;
use App\Models\Concerns\HasSource;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company we bill. `counterparties` are the names the bank prints for its deposits (besides `name` / `short_name`);
 * the `maintenance_*` columns describe a recurring maintenance contract, if any.
 */
#[Fillable(['name', 'short_name', 'redmine_project_ids', 'counterparties', 'status', 'maintenance_fee_untaxed', 'maintenance_cycle', 'maintenance_since', 'maintenance_until'])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory, HasSource;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'redmine_project_ids' => 'array',
            'counterparties' => 'array',
            'status' => CustomerStatus::class,
            'maintenance_fee_untaxed' => 'integer',
            'maintenance_cycle' => MaintenanceCycle::class,
            'maintenance_since' => 'date',
            'maintenance_until' => 'date',
        ];
    }

    /**
     * A maintenance contract needs a fee, a cycle and a start date; anything less is treated as none.
     */
    public function hasMaintenanceContract(): bool
    {
        return $this->maintenance_fee_untaxed > 0 && $this->maintenance_cycle !== null && $this->maintenance_since !== null;
    }

    /**
     * Every name a bank line's counterparty may carry for this customer: the explicit aliases, then name and short name.
     *
     * @return list<string>
     */
    public function bankAliases(): array
    {
        $aliases = array_map(trim(...), [...($this->counterparties ?? []), $this->name, $this->short_name]);

        return array_values(array_unique(array_filter($aliases, fn (string $alias): bool => $alias !== '')));
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * @return HasMany<Receivable, $this>
     */
    public function receivables(): HasMany
    {
        return $this->hasMany(Receivable::class);
    }

    /**
     * @return HasMany<Deal, $this>
     */
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    /**
     * Signed days from `$today` to `maintenance_until` (negative = already ended); null without a contract or an end date.
     */
    public function maintenanceDaysLeft(?CarbonInterface $today = null): ?int
    {
        if (! $this->hasMaintenanceContract() || $this->maintenance_until === null) {
            return null;
        }

        $today = CarbonImmutable::instance($today ?? today())->startOfDay();

        return (int) $today->diffInDays(CarbonImmutable::instance($this->maintenance_until)->startOfDay(), false);
    }
}
