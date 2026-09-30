<?php

namespace Database\Factories;

use App\Models\Server;
use App\Models\ServerDiskSample;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServerDiskSample>
 */
class ServerDiskSampleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $size = 50 * 1024 ** 3;
        $used = (int) ($size * fake()->randomFloat(2, 0.1, 0.7));

        return [
            'server_id' => Server::factory(),
            'collected_at' => now()->startOfSecond(),
            'mount' => '/',
            'filesystem' => '/dev/root',
            'fs_type' => 'ext4',
            'size_bytes' => $size,
            'used_bytes' => $used,
            'available_bytes' => $size - $used,
            'used_percent' => round($used / $size * 100, 2),
        ];
    }
}
