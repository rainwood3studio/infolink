<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Insight lifecycle.
 */
enum InsightStatus: string implements HasColor, HasLabel
{
    case Open = 'open';
    case Acknowledged = 'acknowledged';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => '未處理',
            self::Acknowledged => '已確認',
            self::Resolved => '已解決',
            self::Dismissed => '已忽略',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'danger',
            self::Acknowledged => 'warning',
            self::Resolved => 'success',
            self::Dismissed => 'gray',
        };
    }
}
