<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Model events stay enabled: HasSource fills `source`/`actor` and Receivable computes `amount_taxed` on save.
     */
    public function run(): void
    {
        $this->call(MetricDefinitionSeeder::class);
        $this->call(AlertRuleSeeder::class);

        if (! FinanceDataSeeder::dataIsAvailable()) {
            $message = 'Skipping FinanceDataSeeder: '.FinanceDataSeeder::dataPath().' does not exist (the real finance data is gitignored).';

            Log::warning($message);
            $this->command?->warn($message);

            return;
        }

        $this->call(FinanceDataSeeder::class);
    }
}
