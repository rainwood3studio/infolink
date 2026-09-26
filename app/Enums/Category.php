<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Metric and insight grouping.
 */
enum Category: string implements HasColor, HasLabel
{
    case Finance = 'finance';
    case Sales = 'sales';
    case Delivery = 'delivery';
    case Company = 'company';

    public function getLabel(): string
    {
        return match ($this) {
            self::Finance => '財務',
            self::Sales => '業務',
            self::Delivery => '交付',
            self::Company => '公司',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Finance => 'success',
            self::Sales => 'info',
            self::Delivery => 'warning',
            self::Company => 'gray',
        };
    }
}
