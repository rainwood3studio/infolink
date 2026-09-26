<?php

namespace App\Domain\Alerts\Rules;

use App\Domain\Alerts\Firing;
use App\Domain\Finance\ReceivableService;
use App\Enums\InsightKind;
use App\Models\AlertRule;
use App\Models\CostBaseline;
use App\Models\MetricValue;
use App\Models\Receivable;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * 營業稅預留: VAT for the previous two months is filed by the 15th of odd months (Jan/Mar/May/Jul/Sep/Nov). Within
 * params `window_days` (default 14) days before that deadline, fire when cash balance − VAT reserve < monthly cost.
 *
 * The reserve is the latest `tax.vat_reserve` value recorded since the start of the period (a manual/Claude
 * figure wins), otherwise computed: output VAT (amount_taxed − amount_untaxed) of receivables invoiced in the period
 * (or, when no invoice date was recorded, received in it) minus the VAT already inside the monthly cost for the two
 * months (params `vat_in_monthly_cost`, else the cost baseline breakdown lines whose name contains 「營業稅」).
 *
 * Template variables: period (e.g. 2026-09-10), deadline (m/d), reserve / balance / remaining / monthly_cost (萬).
 */
class VatReserveRule extends BaseRule
{
    public function __construct(protected ReceivableService $receivables) {}

    public static function label(): string
    {
        return '營業稅預留';
    }

    public function evaluate(AlertRule $rule, CarbonImmutable $now): array
    {
        $today = $now->startOfDay();
        $deadline = self::nextDeadline($today);

        if ($today->diffInDays($deadline) > (int) $this->param($rule, 'window_days', 14)) {
            return [];
        }

        $cash = $this->receivables->currentCashBalance();
        $monthlyCost = CostBaseline::query()->whereDate('effective_from', '<=', $today)->latest('effective_from')->first();

        if ($cash === null || $monthlyCost === null) {
            return [];
        }

        $periodStart = $deadline->startOfMonth()->subMonthsNoOverflow(2);
        $periodEnd = $periodStart->addMonthNoOverflow()->endOfMonth()->startOfDay();
        $period = $periodStart->format('Y-m').'-'.$periodEnd->format('m');
        [$reserve, $reserveBasis] = $this->reserve($rule, $periodStart, $periodEnd, $monthlyCost);
        $remaining = $cash['balance'] - $reserve;

        if ($remaining >= $monthlyCost->monthly_cost) {
            return [];
        }

        return [new Firing(
            vars: [
                'period' => $period,
                'deadline' => $deadline->format('n/j'),
                'reserve' => self::wan($reserve),
                'balance' => self::wan($cash['balance']),
                'remaining' => self::wan($remaining),
                'monthly_cost' => self::wan($monthlyCost->monthly_cost),
            ],
            body: implode("\n", [
                sprintf('- 申報期別：%s 月（%s 前申報）', $period, $deadline->toDateString()),
                sprintf('- 目前餘額：NT$%s（%s）', number_format($cash['balance']), $cash['as_of']->toDateString()),
                sprintf('- 營業稅預留：NT$%s（%s）', number_format($reserve), $reserveBasis),
                sprintf('- 扣除預留後：**NT$%s**，低於月成本 NT$%s', number_format($remaining), number_format($monthlyCost->monthly_cost)),
                '',
                '**建議**：確認本期銷項／進項稅額與繳款金額，排定收款或調度資金，確保繳稅後仍有一個月的營運現金。',
            ]),
            evidence: [
                'period' => $period,
                'deadline' => $deadline->toDateString(),
                'balance' => $cash['balance'],
                'reserve' => $reserve,
                'reserve_basis' => $reserveBasis,
                'monthly_cost' => $monthlyCost->monthly_cost,
            ],
            kind: InsightKind::Reminder,
        )];
    }

    /**
     * The next filing deadline on or after `$date`: the 15th of an odd month.
     */
    public static function nextDeadline(CarbonImmutable $date): CarbonImmutable
    {
        $candidate = $date->startOfMonth()->day(15);

        while ($candidate->month % 2 === 0 || $candidate->lt($date->startOfDay())) {
            $candidate = $candidate->startOfMonth()->addMonthNoOverflow()->day(15);
        }

        return $candidate;
    }

    /**
     * @return array{0:int, 1:string} The reserve and a description of how it was obtained.
     */
    protected function reserve(AlertRule $rule, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, CostBaseline $monthlyCost): array
    {
        $recorded = MetricValue::query()
            ->where('metric_key', 'tax.vat_reserve')
            ->where('dimension', '')
            ->whereDate('period_start', '>=', $periodStart->toDateString())
            ->orderByDesc('period_start')
            ->first();

        if ($recorded !== null) {
            return [(int) round((float) $recorded->value), '指標 tax.vat_reserve，'.$recorded->period_start->toDateString()];
        }

        $outputTax = (int) Receivable::query()
            ->where(fn ($query) => $query
                ->whereBetween('invoiced_on', [$periodStart->toDateString(), $periodEnd->toDateString()])
                ->orWhere(fn ($query) => $query
                    ->whereNull('invoiced_on')
                    ->whereBetween('received_on', [$periodStart->toDateString(), $periodEnd->toDateString()])))
            ->get(['amount_taxed', 'amount_untaxed'])
            ->sum(fn (Receivable $receivable): int => $receivable->amount_taxed - $receivable->amount_untaxed);

        $vatInCost = $this->param($rule, 'vat_in_monthly_cost');
        $vatInCost = $vatInCost !== null
            ? (int) $vatInCost
            : (int) collect($monthlyCost->breakdown ?? [])
                ->filter(fn (mixed $amount, string|int $name): bool => Str::contains((string) $name, '營業稅') && is_numeric($amount))
                ->sum();

        return [
            max(0, $outputTax - 2 * $vatInCost),
            sprintf('推算：銷項稅額 %s − 月成本已含 2 × %s', number_format($outputTax), number_format($vatInCost)),
        ];
    }
}
