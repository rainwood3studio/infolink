<?php

namespace App\Enums;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Support\Contracts\HasLabel;

/**
 * The quick "延後" choices for an action item, each resolving to a new due date.
 */
enum ActionItemPostpone: string implements HasLabel
{
    case Tomorrow = 'tomorrow';
    case NextMonday = 'next_monday';
    case InAWeek = 'in_a_week';

    public function getLabel(): string
    {
        return match ($this) {
            self::Tomorrow => '明天',
            self::NextMonday => '下週一',
            self::InAWeek => '一週後',
        };
    }

    /**
     * The new due date counted from `$today`. 「明天」 is the next weekday, so Friday and the weekend land on Monday.
     */
    public function dueOn(?CarbonInterface $today = null): CarbonImmutable
    {
        $today = CarbonImmutable::instance($today ?? today())->startOfDay();

        return match ($this) {
            self::Tomorrow => $today->nextWeekday(),
            self::NextMonday => $today->next(CarbonInterface::MONDAY),
            self::InAWeek => $today->addWeek(),
        };
    }
}
