<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * How certain an expected receipt is.
 */
enum Confidence: string implements HasColor, HasLabel
{
    case High = 'high';
    case Low = 'low';

    public function getLabel(): string
    {
        return match ($this) {
            self::High => '高',
            self::Low => '低',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::High => 'success',
            self::Low => 'warning',
        };
    }
}
