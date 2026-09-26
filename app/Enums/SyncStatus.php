<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Outcome of a sync run.
 */
enum SyncStatus: string implements HasColor, HasLabel
{
    case Running = 'running';
    case Ok = 'ok';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function getLabel(): string
    {
        return match ($this) {
            self::Running => '執行中',
            self::Ok => '成功',
            self::Failed => '失敗',
            self::Skipped => '略過',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Running => 'info',
            self::Ok => 'success',
            self::Failed => 'danger',
            self::Skipped => 'gray',
        };
    }
}
