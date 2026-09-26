<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Delivery state of one notification attempt; deferred ones wait for the end of quiet hours.
 */
enum NotificationStatus: string implements HasColor, HasLabel
{
    case Sent = 'sent';
    case Deferred = 'deferred';
    case Skipped = 'skipped';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Sent => '已送出',
            self::Deferred => '延後',
            self::Skipped => '略過',
            self::Failed => '失敗',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Sent => 'success',
            self::Deferred => 'warning',
            self::Skipped => 'gray',
            self::Failed => 'danger',
        };
    }
}
