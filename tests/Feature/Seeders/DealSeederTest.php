<?php

use App\Enums\Source;
use App\Models\Deal;
use App\Models\DealEvent;
use Database\Seeders\DealSeeder;
use Database\Seeders\FinanceDataSeeder;

it('seeds the vault sales pipeline idempotently', function () {
    if (FinanceDataSeeder::dataIsAvailable()) {
        $this->seed(FinanceDataSeeder::class);
    }

    $rows = json_decode(file_get_contents(DealSeeder::dataPath()), true, flags: JSON_THROW_ON_ERROR);
    $eventCount = array_sum(array_map(fn (array $row): int => count($row['events'] ?? []), $rows));

    $this->seed(DealSeeder::class);
    $this->seed(DealSeeder::class);

    expect(Deal::query()->count())->toBe(count($rows))
        ->and(DealEvent::query()->count())->toBe($eventCount)
        ->and(Deal::query()->where('source', '!=', Source::Vault)->count())->toBe(0)
        ->and(Deal::query()->whereNull('customer_id')->whereNull('prospect_name')->count())->toBe(0);

    foreach ($rows as $row) {
        $deal = Deal::query()->where('external_key', "deal:{$row['ref']}")->sole();

        expect($deal->stage->value)->toBe($row['stage'])
            ->and($deal->probability)->toBe($row['probability'])
            ->and($deal->events()->count())->toBe(count($row['events'] ?? []));

        if ($row['customer_short_name'] !== null && FinanceDataSeeder::dataIsAvailable()) {
            expect($deal->customer?->short_name)->toBe($row['customer_short_name']);
        }
    }
})->skip(fn (): bool => ! DealSeeder::dataIsAvailable(), 'Vault sales seed data is not present (gitignored).');
