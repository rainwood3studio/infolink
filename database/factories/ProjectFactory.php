<?php

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Models\Customer;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'name' => fake()->words(2, true),
            'contract_amount_untaxed' => fake()->numberBetween(100_000, 3_000_000),
            'status' => ProjectStatus::Active,
        ];
    }
}
