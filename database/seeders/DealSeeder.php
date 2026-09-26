<?php

namespace Database\Seeders;

use App\Enums\DealStage;
use App\Enums\Source;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\DealEvent;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Loads the sales pipeline extracted from the vault (gitignored) in database/seeders/data/sales/deals.json.
 *
 * Idempotent: deals are keyed `deal:<ref>` and their events `deal:<ref>:event:<n>` under Source::Vault, so running
 * it again updates instead of duplicating. Deals for an existing customer link by `short_name`; if that customer has
 * not been seeded yet, the short name is kept as `prospect_name` so the deal still has a party.
 */
class DealSeeder extends Seeder
{
    public static function dataPath(): string
    {
        return database_path('seeders/data/sales/deals.json');
    }

    public static function dataIsAvailable(): bool
    {
        return is_file(self::dataPath());
    }

    /**
     * Seed the deals and their events.
     */
    public function run(): void
    {
        if (! self::dataIsAvailable()) {
            $this->command?->warn('Skipping DealSeeder: '.self::dataPath().' does not exist (the vault sales data is gitignored).');

            return;
        }

        $rows = json_decode(file_get_contents(self::dataPath()), true, flags: JSON_THROW_ON_ERROR);

        DB::transaction(function () use ($rows): void {
            foreach ($rows as $row) {
                $this->seedDeal($row);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function seedDeal(array $row): void
    {
        $customerId = $row['customer_short_name'] === null
            ? null
            : Customer::query()->where('short_name', $row['customer_short_name'])->value('id');

        $stage = DealStage::from($row['stage']);
        $key = "deal:{$row['ref']}";
        $existing = Deal::query()->where('source', Source::Vault)->where('external_key', $key)->first();

        $deal = Deal::upsertFromSource(Source::Vault, $key, [
            'customer_id' => $customerId,
            'prospect_name' => $customerId === null ? ($row['prospect_name'] ?? $row['customer_short_name']) : $row['prospect_name'],
            'title' => $row['title'],
            'stage' => $stage,
            'amount_untaxed' => $row['amount_untaxed'],
            'probability' => $row['probability'],
            'recurring_monthly' => $row['recurring_monthly'],
            'expected_close_on' => $row['expected_close_on'],
            'next_action' => $row['next_action'],
            'next_action_on' => $row['next_action_on'],
            'closed_at' => $stage->isClosed() ? ($existing?->closed_at ?? now()) : null,
            'vault_ref' => $row['vault_ref'],
            'notes' => $row['notes'] ?? null,
        ]);

        foreach ($row['events'] ?? [] as $index => $event) {
            DealEvent::upsertFromSource(Source::Vault, "{$key}:event:".($index + 1), [
                'deal_id' => $deal->getKey(),
                'occurred_on' => $event['occurred_on'],
                'type' => $event['type'],
                'content' => $event['content'],
                'from_stage' => $event['from_stage'] ?? null,
                'to_stage' => $event['to_stage'] ?? null,
                'vault_ref' => $row['vault_ref'],
            ]);
        }
    }
}
