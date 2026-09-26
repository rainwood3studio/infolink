<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Insight severity.
 */
enum InsightSeverity: string implements HasColor, HasLabel
{
    case Critical = 'critical';
    case Warning = 'warning';
    case Info = 'info';

    public function getLabel(): string
    {
        return match ($this) {
            self::Critical => '嚴重',
            self::Warning => '注意',
            self::Info => '資訊',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Critical => 'danger',
            self::Warning => 'warning',
            self::Info => 'info',
        };
    }
}
