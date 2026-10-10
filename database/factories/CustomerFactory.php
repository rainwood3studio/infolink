<?php

namespace Database\Factories;

use App\Enums\CustomerStatus;
use App\Enums\MaintenanceCycle;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'short_name' => fake()->unique()->word(),
            'status' => CustomerStatus::Active,
        ];
    }

    /**
     * A maintenance contract billed every month (or year) from the given day.
     */
    public function maintenance(int $feeUntaxed, MaintenanceCycle $cycle, string $since, ?string $until = null): static
    {
        return $this->state([
            'maintenance_fee_untaxed' => $feeUntaxed,
            'maintenance_cycle' => $cycle,
            'maintenance_since' => $since,
            'maintenance_until' => $until,
        ]);
    }
}
