<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How often a recurring fixed expense falls due. Non-monthly cadences carry the explicit `due_months` list on the
 * expense (e.g. VAT: [1, 3, 5, 7, 9, 11]); the cadence only says how many months the list is expected to hold.
 */
enum ExpenseCadence: string implements HasLabel
{
    case Monthly = 'monthly';
    case Bimonthly = 'bimonthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';

    public function getLabel(): string
    {
        return match ($this) {
            self::Monthly => '每月',
            self::Bimonthly => '雙月',
            self::Quarterly => '每季',
            self::Yearly => '每年',
        };
    }

    /**
     * How many due months a year this cadence has.
     */
    public function monthsPerYear(): int
    {
        return match ($this) {
            self::Monthly => 12,
            self::Bimonthly => 6,
            self::Quarterly => 4,
            self::Yearly => 1,
        };
    }

    /**
     * The default due months when none are given: every n-th month starting from `$firstMonth`.
     *
     * @return list<int>
     */
    public function defaultDueMonths(int $firstMonth = 1): array
    {
        $step = intdiv(12, $this->monthsPerYear());
        $months = [];

        for ($month = (($firstMonth - 1) % $step) + 1; $month <= 12; $month += $step) {
            $months[] = $month;
        }

        return $months;
    }
}
