<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How a metric value is measured and displayed.
 */
enum MetricUnit: string implements HasLabel
{
    case Twd = 'twd';
    case Count = 'count';
    case Hours = 'hours';
    case Ratio = 'ratio';
    case Months = 'months';
    case Days = 'days';

    public function getLabel(): string
    {
        return match ($this) {
            self::Twd => '新台幣',
            self::Count => '筆數',
            self::Hours => '小時',
            self::Ratio => '比例',
            self::Months => '月',
            self::Days => '天',
        };
    }
}
