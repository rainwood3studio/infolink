<?php

use App\Domain\Alerts\RuleEvaluator;
use App\Domain\Alerts\Rules\VatReserveRule;
use App\Domain\Metrics\MetricRecorder;
use App\Enums\Confidence;
use App\Enums\InsightSeverity;
use App\Enums\InsightStatus;
use App\Enums\ReceivableStatus;
use App\Models\AlertRule;
use App\Models\BankTransaction;
use App\Models\CostBaseline;
use App\Models\Customer;
use App\Models\Insight;
use App\Models\Receivable;
use Carbon\CarbonImmutable;
use Database\Seeders\AlertRuleSeeder;
use Database\Seeders\MetricDefinitionSeeder;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 09:00'));
    $this->seed([MetricDefinitionSeeder::class, AlertRuleSeeder::class]);
    $this->evaluate = fn (string $key) => app(RuleEvaluator::class)->evaluate($key);
    $this->balance = fn (int $balance, ?string $date = null) => BankTransaction::factory()->create([
        'txn_date' => $date ?? today(),
        'balance' => $balance,
        'withdrawal' => 0,
        'deposit' => 0,
    ]);
});

describe('cash-low', function () {
    test('fires critical when the 90-day low is below 500,000, keyed by the month of the low', function () {
        ($this->balance)(600_000);
        CostBaseline::factory()->create(['effective_from' => '2026-01-01', 'monthly_cost' => 229_666]);

        ($this->evaluate)('cash-low');

        expect(Insight::sole())
            ->fingerprint->toBe('cash-low:2026-12')
            ->severity->toBe(InsightSeverity::Critical)
            ->title->toBe('90 天內現金低點 -31.87 萬（2026-12）');
    });

    test('fires on the as-of month when the current balance is the low point', function () {
        ($this->balance)(499_999);

        ($this->evaluate)('cash-low');

        expect(Insight::sole()->fingerprint)->toBe('cash-low:2026-09');
    });

    test('does not fire at exactly the threshold or without bank data', function () {
        ($this->evaluate)('cash-low');
        expect(Insight::count())->toBe(0);

        ($this->balance)(500_000);
        ($this->evaluate)('cash-low');
        expect(Insight::count())->toBe(0);
    });
});

describe('cash-runway', function () {
    test('fires warning below 3 months, critical below 2, and resolves when back above', function () {
        $recorder = app(MetricRecorder::class);

        $recorder->record('cash.runway_months', 3);
        ($this->evaluate)('cash-runway');
        expect(Insight::count())->toBe(0);

        $recorder->record('cash.runway_months', 2.5);
        ($this->evaluate)('cash-runway');
        expect(Insight::sole())
            ->fingerprint->toBe('cash-runway')
            ->severity->toBe(InsightSeverity::Warning)
            ->title->toBe('現金只夠撐 2.5 個月');

        $recorder->record('cash.runway_months', 1.9);
        ($this->evaluate)('cash-runway');
        expect(Insight::sole()->severity)->toBe(InsightSeverity::Critical);

        $recorder->record('cash.runway_months', 3.2);
        ($this->evaluate)('cash-runway');
        expect(Insight::sole()->status)->toBe(InsightStatus::Resolved);
    });

    test('uses the latest period only', function () {
        $recorder = app(MetricRecorder::class);
        $recorder->record('cash.runway_months', 1, today()->subDay());
        $recorder->record('cash.runway_months', 4, today());

        ($this->evaluate)('cash-runway');

        expect(Insight::count())->toBe(0);
    });
});

describe('receivable-due', function () {
    test('fires info for a high-confidence, not-invoiced receivable due within 7 days', function () {
        $receivable = Receivable::factory()->for(Customer::factory()->state(['short_name' => '我識']))->create([
            'item' => '尾款', 'amount_untaxed' => 400_000, 'expected_on' => today()->addDays(7),
        ]);

        ($this->evaluate)('receivable-due');

        expect(Insight::sole())
            ->fingerprint->toBe("receivable-due:{$receivable->id}")
            ->severity->toBe(InsightSeverity::Info)
            ->title->toBe('我識尾款 42.00 萬將於 10/1 到期，尚未開發票');
    });

    test('does not fire beyond 7 days, when invoiced, low-confidence, already overdue or received', function (array $attributes) {
        Receivable::factory()->create(['expected_on' => today()->addDays(3), ...$attributes]);

        ($this->evaluate)('receivable-due');

        expect(Insight::count())->toBe(0);
    })->with([
        '8 days out' => [['expected_on' => '2026-10-02']],
        'invoiced' => [['status' => ReceivableStatus::Invoiced, 'invoiced_on' => '2026-09-20']],
        'low confidence' => [['confidence' => Confidence::Low]],
        'overdue' => [['expected_on' => '2026-09-23']],
        'received' => [['status' => ReceivableStatus::Received, 'received_on' => '2026-09-20']],
    ]);

    test('recurring fees can be excluded by param', function () {
        Receivable::factory()->create(['expected_on' => today()->addDays(3), 'is_recurring' => true]);
        AlertRule::where('key', 'receivable-due')->update(['params' => ['include_recurring' => 'false']]);

        ($this->evaluate)('receivable-due');

        expect(Insight::count())->toBe(0);
    });
});

describe('receivable-overdue', function () {
    test('fires after more than 3 days, critical after more than 30', function (int $days, ?InsightSeverity $severity) {
        Receivable::factory()->create(['expected_on' => today()->subDays($days), 'confidence' => Confidence::Low]);

        ($this->evaluate)('receivable-overdue');

        expect(Insight::first()?->severity)->toBe($severity);
    })->with([
        '3 days' => [3, null],
        '4 days' => [4, InsightSeverity::Warning],
        '30 days' => [30, InsightSeverity::Warning],
        '31 days' => [31, InsightSeverity::Critical],
    ]);

    test('includes recurring fees and invoiced receivables', function () {
        Receivable::factory()->create(['expected_on' => today()->subDays(10), 'is_recurring' => true, 'status' => ReceivableStatus::Invoiced]);

        ($this->evaluate)('receivable-overdue');

        expect(Insight::count())->toBe(1);
    });
});

describe('vat-reserve', function () {
    beforeEach(function () {
        CostBaseline::factory()->create([
            'effective_from' => '2026-08-01',
            'monthly_cost' => 229_666,
            'breakdown' => ['薪資' => 161_137, '營業稅（14 個月月均）' => 17_866],
        ]);
        Receivable::factory()->create(['amount_untaxed' => 2_340_000, 'status' => ReceivableStatus::Invoiced, 'invoiced_on' => '2026-09-15', 'expected_on' => '2026-10-15']);
    });

    test('fires in the 14 days before the odd-month deadline when balance minus the reserve is below the monthly cost', function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00'));
        ($this->balance)(300_000, '2026-10-31');

        ($this->evaluate)('vat-reserve');

        $insight = Insight::sole();

        expect($insight)
            ->fingerprint->toBe('vat-reserve:2026-09-10')
            ->severity->toBe(InsightSeverity::Warning)
            ->title->toBe('營業稅（11/15 前申報）預留 8.13 萬後餘額 21.87 萬，低於月成本')
            ->and($insight->evidence['reserve'])->toBe(81_268);
    });

    test('does not fire when enough cash remains', function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00'));
        ($this->balance)(320_000, '2026-10-31');

        ($this->evaluate)('vat-reserve');

        expect(Insight::count())->toBe(0);
    });

    test('does not fire outside the window', function (string $now) {
        $this->travelTo(CarbonImmutable::parse($now));
        ($this->balance)(100_000, '2026-10-01');

        ($this->evaluate)('vat-reserve');

        expect(Insight::count())->toBe(0);
    })->with(['2026-10-31 09:00', '2026-11-16 09:00', '2026-12-10 09:00']);

    test('a recorded tax.vat_reserve for the period wins over the estimate', function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00'));
        ($this->balance)(400_000, '2026-11-09');
        app(MetricRecorder::class)->record('tax.vat_reserve', 200_000, CarbonImmutable::parse('2026-11-01'));

        ($this->evaluate)('vat-reserve');

        expect(Insight::sole()->evidence)->toMatchArray(['reserve' => 200_000]);
    });

    test('finds the next filing deadline', function (string $date, string $deadline) {
        expect(VatReserveRule::nextDeadline(CarbonImmutable::parse($date))->toDateString())->toBe($deadline);
    })->with([
        ['2026-09-26', '2026-11-15'],
        ['2026-11-15', '2026-11-15'],
        ['2026-11-16', '2027-01-15'],
        ['2026-12-01', '2027-01-15'],
        ['2027-01-03', '2027-01-15'],
    ]);
});
