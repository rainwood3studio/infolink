<?php

namespace Database\Factories;

use App\Enums\DealEventType;
use App\Models\Deal;
use App\Models\DealEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DealEvent>
 */
class DealEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'deal_id' => Deal::factory(),
            'occurred_on' => today(),
            'type' => DealEventType::Note,
            'content' => fake()->sentence(),
        ];
    }
}
