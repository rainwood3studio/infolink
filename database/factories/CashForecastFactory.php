<?php

namespace Database\Factories;

use App\Models\CashForecast;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashForecast>
 */
class CashForecastFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'as_of' => today(),
            'opening_balance' => 1_000_000,
            'rows' => [],
            'min_balance' => 1_000_000,
            'min_balance_month' => today()->format('Y-m'),
            'year_end_balance' => 1_000_000,
        ];
    }
}
