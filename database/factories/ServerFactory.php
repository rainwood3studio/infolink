<?php

namespace Database\Factories;

use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Server>
 */
class ServerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'instance_id' => 'i-'.fake()->unique()->regexify('[0-9a-f]{17}'),
            'account' => 'default',
            'region' => 'ap-northeast-1',
            'name' => fake()->unique()->domainWord().'-web',
            'computer_name' => 'ip-10-0-0-'.fake()->numberBetween(1, 254),
            'platform' => 'Linux',
            'platform_name' => 'Ubuntu',
            'instance_type' => 't3.medium',
            'ping_status' => 'Online',
            'is_active' => true,
            'last_seen_at' => now(),
            'last_collected_at' => now()->startOfSecond(),
        ];
    }
}
