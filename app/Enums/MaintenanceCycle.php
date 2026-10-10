<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How often a maintenance contract bills.
 */
enum MaintenanceCycle: string implements HasLabel
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    public function getLabel(): string
    {
        return match ($this) {
            self::Monthly => '每月',
            self::Yearly => '每年',
        };
    }

    /**
     * 「每月」 / 「每年」 as a suffix, e.g. 「NT$100,000／月」.
     */
    public function perLabel(): string
    {
        return match ($this) {
            self::Monthly => '月',
            self::Yearly => '年',
        };
    }
}
