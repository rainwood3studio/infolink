<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Action item workflow status.
 */
enum ActionItemStatus: string implements HasColor, HasLabel
{
    case Todo = 'todo';
    case Doing = 'doing';
    case Waiting = 'waiting';
    case Done = 'done';
    case Dropped = 'dropped';

    public function getLabel(): string
    {
        return match ($this) {
            self::Todo => '待辦',
            self::Doing => '進行中',
            self::Waiting => '等待中',
            self::Done => '完成',
            self::Dropped => '放棄',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Todo => 'gray',
            self::Doing => 'info',
            self::Waiting => 'warning',
            self::Done => 'success',
            self::Dropped => 'danger',
        };
    }
}
