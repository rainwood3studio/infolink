<?php

use App\Domain\Alerts\Rules\MaintenanceRenewalRule;
use App\Enums\InsightKind;
use App\Enums\MaintenanceCycle;
use App\Models\AlertRule;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Database\Seeders\AlertRuleSeeder;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 09:00'));
    $this->rule = fn (array $overrides = []): AlertRule => new AlertRule([
        ...collect(AlertRuleSeeder::rules())->firstWhere('key', 'maintenance-renewal'),
        ...$overrides,
    ]);
    $this->evaluate = fn (array $overrides = []): array => (new MaintenanceRenewalRule)->evaluate(($this->rule)($overrides), CarbonImmutable::now());
});

test('fires for a maintenance contract ending within 45 days, with the renewal suggestion', function () {
    Customer::factory()->maintenance(100_000, MaintenanceCycle::Monthly, '2026-01-01', '2026-11-15')->create(['name' => '墊腳石圖書股份有限公司', 'short_name' => '墊腳石']);
    Customer::factory()->maintenance(240_000, MaintenanceCycle::Yearly, '2026-01-01', '2027-01-01')->create(['short_name' => '長照']);
    Customer::factory()->maintenance(20_000, MaintenanceCycle::Monthly, '2026-01-01')->create(['short_name' => '我識']);
    Customer::factory()->create(['short_name' => '盟立', 'maintenance_until' => '2026-10-20']);

    $firings = ($this->evaluate)();

    expect($firings)->toHaveCount(1)
        ->and($firings[0]->vars)->toBe([
            'customer' => '墊腳石',
            'name' => '墊腳石圖書股份有限公司',
            'until' => '2026-11-15',
            'days' => 36,
            'days_text' => '36 天後到期',
            'fee' => '100,000',
            'fee_taxed' => '105,000',
            'cycle' => '每月',
        ])
        ->and($firings[0]->body)->toContain('未稅 NT$100,000／月（含稅 NT$105,000），每月收費', '2026-01-01 起，2026-11-15 到期（**36 天後到期**）', '與客戶確認續約並建立新一期維護費應收；續約後把維護合約起訖日往後延')
        ->and($firings[0]->body)->not->toContain('清掉維護合約欄位')
        ->and($firings[0]->evidence)->toMatchArray(['maintenance_until' => '2026-11-15', 'days_until_end' => 36, 'fee_untaxed' => 100_000, 'fee_taxed' => 105_000, 'cycle' => 'monthly', 'threshold_days' => 45])
        ->and($firings[0]->kind)->toBe(InsightKind::Reminder)
        ->and($firings[0]->severity)->toBeNull()
        ->and($firings[0]->fingerprint)->toBeNull();
});

test('a contract whose end date has passed keeps firing as 已到期', function () {
    Customer::factory()->maintenance(50_000, MaintenanceCycle::Monthly, '2025-10-01', '2026-09-30')->create(['short_name' => '南陽']);
    Customer::factory()->maintenance(50_000, MaintenanceCycle::Monthly, '2025-10-01', '2026-10-10')->create(['short_name' => '今天']);

    $firings = ($this->evaluate)();

    expect(array_column(array_map(fn ($firing) => $firing->vars, $firings), 'days_text', 'customer'))->toBe(['南陽' => '已到期 10 天', '今天' => '今天到期'])
        ->and($firings[0]->vars['days'])->toBe(-10)
        ->and($firings[0]->body)->toContain('已到期 10 天', '清掉維護合約欄位');
});

test('the threshold comes from params, else the threshold column', function () {
    Customer::factory()->maintenance(100_000, MaintenanceCycle::Monthly, '2026-01-01', '2026-11-15')->create(['short_name' => '墊腳石']);

    expect(($this->evaluate)(['params' => ['threshold' => 30]]))->toBe([])
        ->and(($this->evaluate)(['threshold' => 30, 'params' => null]))->toBe([])
        ->and(($this->evaluate)(['threshold' => 36, 'params' => null]))->toHaveCount(1)
        ->and(($this->evaluate)(['threshold' => null, 'params' => null]))->toHaveCount(1);
});

test('the seeded rule renders its title and fingerprint from the firing vars', function () {
    $rule = ($this->rule)();

    expect($rule->title_template)->toBe('{customer} 維護合約{days_text}（{until}，{cycle} NT${fee}）')
        ->and($rule->fingerprint_template)->toBe('maintenance-renewal:{customer}')
        ->and((new MaintenanceRenewalRule)->ownedFingerprints($rule))->toBe(['maintenance-renewal:*'])
        ->and(MaintenanceRenewalRule::label())->toBe('維護合約到期');
});
