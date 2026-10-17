<?php

namespace Database\Factories;

use App\Enums\ExpenseCadence;
use App\Enums\ExpenseCategory;
use App\Models\RecurringExpense;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringExpense>
 */
class RecurringExpenseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['勞健保', '租金', '雲端訂閱']),
            'amount' => fake()->numberBetween(5, 60) * 1_000,
            'cadence' => ExpenseCadence::Monthly,
            'due_day' => fake()->numberBetween(1, 28),
            'due_months' => null,
            'category' => ExpenseCategory::Other,
            'in_baseline' => false,
            'starts_on' => today()->startOfYear(),
            'ends_on' => null,
            'is_active' => true,
        ];
    }

    /**
     * 營業稅: filed by the 15th of odd months.
     */
    public function vat(int $amount = 60_000): static
    {
        return $this->state([
            'name' => '營業稅',
            'amount' => $amount,
            'cadence' => ExpenseCadence::Bimonthly,
            'due_day' => 15,
            'due_months' => [1, 3, 5, 7, 9, 11],
            'category' => ExpenseCategory::Tax,
        ]);
    }

    /**
     * 營所稅: once a year, at the end of May.
     */
    public function yearly(int $month = 5, int $day = 31): static
    {
        return $this->state([
            'cadence' => ExpenseCadence::Yearly,
            'due_day' => $day,
            'due_months' => [$month],
        ]);
    }

    public function inBaseline(): static
    {
        return $this->state(['in_baseline' => true]);
    }
}
