<?php

namespace Database\Factories;

use App\Enums\ActionItemPriority;
use App\Enums\ActionItemStatus;
use App\Models\ActionItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActionItem>
 */
class ActionItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'priority' => ActionItemPriority::P2,
            'status' => ActionItemStatus::Todo,
            'due_on' => fake()->optional()->dateTimeBetween('now', '+1 month'),
        ];
    }
}
