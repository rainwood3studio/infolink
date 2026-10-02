<?php

namespace Database\Factories;

use App\Models\GithubIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GithubIdentity>
 */
class GithubIdentityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fn (array $attributes): string => GithubIdentity::keyFor($attributes['login'], $attributes['email']),
            'developer_id' => null,
            'login' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'name' => fake()->name(),
            'first_seen_at' => now()->subMonth(),
            'last_seen_at' => now(),
        ];
    }
}
