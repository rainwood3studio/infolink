<?php

namespace App\Domain\Alerts\Rules;

use App\Domain\Alerts\Firing;
use App\Enums\InsightKind;
use App\Models\AlertRule;
use App\Models\Customer;
use Carbon\CarbonImmutable;

/**
 * 維護合約到期: a customer with a maintenance contract ({@see Customer::hasMaintenanceContract()}) whose
 * `maintenance_until` is within `threshold` days (params `threshold`, else the rule's threshold column, default 45),
 * one insight per customer (`maintenance-renewal:<customer>`). A contract whose end date is already past but is still
 * recorded fires too (「已到期 N 天」): either the renewal was never entered or the customer really stopped paying,
 * and both need a decision. The insight auto-resolves once the end date is moved into the future (beyond the
 * threshold), cleared, or the contract fields are removed.
 *
 * Template variables: customer (short name), name, until, days (signed; negative = ended), days_text, fee (untaxed,
 * formatted), fee_taxed, cycle.
 */
class MaintenanceRenewalRule extends BaseRule
{
    /** Days before the end date at which the reminder starts. */
    public const int DEFAULT_THRESHOLD_DAYS = 45;

    /** Assumed VAT when quoting the taxed fee. */
    public const float TAX_RATE = 0.05;

    public static function label(): string
    {
        return '維護合約到期';
    }

    public function evaluate(AlertRule $rule, CarbonImmutable $now): array
    {
        $today = $now->startOfDay();
        $thresholdDays = (int) ($this->param($rule, 'threshold') ?? $this->threshold($rule, self::DEFAULT_THRESHOLD_DAYS));

        return Customer::query()
            ->whereNotNull('maintenance_until')
            ->orderBy('maintenance_until')
            ->orderBy('short_name')
            ->get()
            ->filter(fn (Customer $customer): bool => $customer->hasMaintenanceContract())
            ->map(function (Customer $customer) use ($today, $thresholdDays): ?Firing {
                $days = $customer->maintenanceDaysLeft($today);

                if ($days === null || $days > $thresholdDays) {
                    return null;
                }

                $until = CarbonImmutable::instance($customer->maintenance_until)->toDateString();
                $fee = (int) $customer->maintenance_fee_untaxed;
                $feeTaxed = (int) round($fee * (1 + self::TAX_RATE));
                $cycle = $customer->maintenance_cycle->getLabel();
                $daysText = match (true) {
                    $days < 0 => '已到期 '.abs($days).' 天',
                    $days === 0 => '今天到期',
                    default => "{$days} 天後到期",
                };

                return new Firing(
                    vars: [
                        'customer' => $customer->short_name,
                        'name' => $customer->name,
                        'until' => $until,
                        'days' => $days,
                        'days_text' => $daysText,
                        'fee' => number_format($fee),
                        'fee_taxed' => number_format($feeTaxed),
                        'cycle' => $cycle,
                    ],
                    body: implode("\n", [
                        "- 客戶：{$customer->short_name}（{$customer->name}）",
                        sprintf('- 維護費：未稅 NT$%s／%s（含稅 NT$%s），%s收費', number_format($fee), $customer->maintenance_cycle->perLabel(), number_format($feeTaxed), $cycle),
                        sprintf('- 合約期間：%s 起，%s 到期（**%s**）', CarbonImmutable::instance($customer->maintenance_since)->toDateString(), $until, $daysText),
                        '',
                        '**建議**：與客戶確認續約並建立新一期維護費應收；續約後把維護合約起訖日往後延。'.($days < 0 ? '若客戶不再續約，清掉維護合約欄位，客戶收款頁才不會繼續把它當未入帳。' : ''),
                    ]),
                    evidence: [
                        'customer_id' => $customer->id,
                        'maintenance_until' => $until,
                        'days_until_end' => $days,
                        'fee_untaxed' => $fee,
                        'fee_taxed' => $feeTaxed,
                        'cycle' => $customer->maintenance_cycle->value,
                        'threshold_days' => $thresholdDays,
                    ],
                    kind: InsightKind::Reminder,
                );
            })
            ->filter()
            ->values()
            ->all();
    }
}
