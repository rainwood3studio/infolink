<?php

namespace Database\Factories;

use App\Enums\SyncJob;
use App\Enums\SyncStatus;
use App\Models\SyncRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SyncRun>
 */
class SyncRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job' => SyncJob::RedmineIssues,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'status' => SyncStatus::Ok,
            'stats' => [],
        ];
    }
}
