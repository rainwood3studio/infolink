<?php

namespace Database\Factories;

use App\Models\CostBaseline;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CostBaseline>
 */
class CostBaselineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'effective_from' => today()->startOfMonth(),
            'monthly_cost' => 229_666,
            'breakdown' => ['薪資' => 161_137],
        ];
    }
}
